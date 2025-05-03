<?php
// Start session
session_start();

// Include database connection
require_once '../../includes/db_connect.php';

// Check if user is logged in and is a branch admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'branch_admin') {
    // Redirect to login page if not logged in or not a branch admin
    header("Location: ../login.php");
    exit();
}

// Get admin data and branch info
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT a.name, a.email, b.id as branch_id, b.name as branch_name, b.location 
                      FROM admins a 
                      LEFT JOIN branches b ON a.id = b.admin_id 
                      WHERE a.id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

// Store branch info in session if not already set
if (!isset($_SESSION['admin_branch_id']) || !isset($_SESSION['admin_branch_name'])) {
    $_SESSION['admin_branch_id'] = $admin['branch_id'];
    $_SESSION['admin_branch_name'] = $admin['branch_name'];
}

$branch_name = $admin['branch_name'];

// First, let's modify the database check to ensure the attendance table has the admin_override column
// Find this section near the beginning of the file (around line 40-60)

// Check if attendance table exists, if not create it
$result = $conn->query("SHOW TABLES LIKE 'attendance'");
if ($result->num_rows == 0) {
    // Create attendance table
    $sql = file_get_contents('../../database/attendance_tables.sql');
    $conn->multi_query($sql);
    
    // Clear results to avoid issues with subsequent queries
    while ($conn->more_results() && $conn->next_result()) {
        // Consume all results
        if ($result = $conn->store_result()) {
            $result->free();
        }
    }
} else {
    // Check if admin_override column exists in attendance table
    $result = $conn->query("SHOW COLUMNS FROM attendance LIKE 'admin_override'");
    if ($result->num_rows == 0) {
        // Add admin_override column if it doesn't exist
        $conn->query("ALTER TABLE attendance ADD COLUMN admin_override TINYINT(1) DEFAULT 0 AFTER notes");
    }
}

// Get attendance settings for this branch
$stmt = $conn->prepare("SELECT * FROM attendance_settings WHERE branch = ?");
$stmt->bind_param("s", $branch_name);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $settings = $result->fetch_assoc();
    $max_entries_per_day = $settings['max_entries_per_day'] ?? 1;
    $auto_checkout_after = $settings['auto_checkout_after'] ?? 180; // Default 3 hours
} else {
    // Default settings if not found
    $max_entries_per_day = 1;
    $auto_checkout_after = 180; // 3 hours in minutes
}

// Auto checkout customers who have been checked in for too long
$stmt = $conn->prepare("UPDATE attendance 
                      SET check_out = NOW(), 
                          notes = CONCAT(IFNULL(notes, ''), ' | Auto checked-out by system') 
                      WHERE branch = ? 
                      AND check_out IS NULL 
                      AND TIMESTAMPDIFF(MINUTE, check_in, NOW()) > ?");
$stmt->bind_param("si", $branch_name, $auto_checkout_after);
$stmt->execute();

// Initialize selected date
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Now let's fix the check-in form submission to handle the case when admin_override is not set
// Find the check-in request handler (around line 80-130)

// Handle check-in request
if (isset($_POST['check_in']) && !empty($_POST['customer_id'])) {
    $customer_id = intval($_POST['customer_id']);
    $notes = isset($_POST['notes']) ? filter_var($_POST['notes'], FILTER_SANITIZE_STRING) : '';
    $admin_override = isset($_POST['admin_override']) && $_POST['admin_override'] == 1 ? 1 : 0;
    
    // Check if customer belongs to this branch
    $stmt = $conn->prepare("SELECT id FROM customers WHERE id = ? AND branch = ?");
    $stmt->bind_param("is", $customer_id, $branch_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Check how many times the customer has checked in today
        $stmt = $conn->prepare("SELECT COUNT(*) as check_in_count FROM attendance 
                              WHERE customer_id = ? AND branch = ? AND DATE(check_in) = CURDATE()");
        $stmt->bind_param("is", $customer_id, $branch_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $check_in_data = $result->fetch_assoc();
        $check_in_count = $check_in_data['check_in_count'];
        
        // Check if customer already has an active check-in (not checked out)
        $stmt = $conn->prepare("SELECT id FROM attendance 
                              WHERE customer_id = ? AND branch = ? AND DATE(check_in) = CURDATE() AND check_out IS NULL");
        $stmt->bind_param("is", $customer_id, $branch_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $active_check_in = $result->num_rows > 0;
        
        if ($active_check_in) {
            // Customer already checked in and hasn't checked out
            $_SESSION['attendance_message'] = "Customer already checked in today and hasn't checked out.";
            $_SESSION['attendance_message_type'] = "error";
        } else if ($check_in_count >= $max_entries_per_day && !$admin_override) {
            // Customer has reached max check-ins for the day and no admin override
            $_SESSION['attendance_message'] = "Customer has already checked in " . $check_in_count . " time(s) today. Admin override required for additional check-ins.";
            $_SESSION['attendance_message_type'] = "error";
            $_SESSION['show_override'] = true;
            $_SESSION['override_customer_id'] = $customer_id;
        } else {
            // Prepare the SQL statement with admin_override parameter
            try {
                // Insert check-in record
                $stmt = $conn->prepare("INSERT INTO attendance (customer_id, check_in, branch, created_by, notes, admin_override) 
                                      VALUES (?, NOW(), ?, ?, ?, ?)");
                $stmt->bind_param("isisi", $customer_id, $branch_name, $admin_id, $notes, $admin_override);
                
                if ($stmt->execute()) {
                    if ($admin_override) {
                        $_SESSION['attendance_message'] = "Check-in recorded successfully with admin override!";
                    } else {
                        $_SESSION['attendance_message'] = "Check-in recorded successfully!";
                    }
                    $_SESSION['attendance_message_type'] = "success";
                    unset($_SESSION['show_override']);
                    unset($_SESSION['override_customer_id']);
                } else {
                    $_SESSION['attendance_message'] = "Error recording check-in: " . $conn->error;
                    $_SESSION['attendance_message_type'] = "error";
                }
            } catch (Exception $e) {
                // Handle the exception if the admin_override column doesn't exist
                // Try again without the admin_override column
                try {
                    $stmt = $conn->prepare("INSERT INTO attendance (customer_id, check_in, branch, created_by, notes) 
                                          VALUES (?, NOW(), ?, ?, ?)");
                    $stmt->bind_param("isis", $customer_id, $branch_name, $admin_id, $notes);
                    
                    if ($stmt->execute()) {
                        $_SESSION['attendance_message'] = "Check-in recorded successfully!";
                        $_SESSION['attendance_message_type'] = "success";
                        unset($_SESSION['show_override']);
                        unset($_SESSION['override_customer_id']);
                    } else {
                        $_SESSION['attendance_message'] = "Error recording check-in: " . $conn->error;
                        $_SESSION['attendance_message_type'] = "error";
                    }
                } catch (Exception $innerEx) {
                    $_SESSION['attendance_message'] = "Error recording check-in: " . $innerEx->getMessage();
                    $_SESSION['attendance_message_type'] = "error";
                }
            }
        }
    } else {
        $_SESSION['attendance_message'] = "Customer not found or doesn't belong to your branch.";
        $_SESSION['attendance_message_type'] = "error";
    }
    
    // Redirect to avoid resubmission
    header("Location: attendance.php");
    exit();
}

// Handle check-out request
if (isset($_POST['check_out']) && !empty($_POST['attendance_id'])) {
    $attendance_id = intval($_POST['attendance_id']);
    $notes = isset($_POST['checkout_notes']) ? filter_var($_POST['checkout_notes'], FILTER_SANITIZE_STRING) : '';
    
    // Check if attendance record exists and belongs to this branch
    $stmt = $conn->prepare("SELECT id FROM attendance WHERE id = ? AND branch = ?");
    $stmt->bind_param("is", $attendance_id, $branch_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update check-out time
        $stmt = $conn->prepare("UPDATE attendance SET check_out = NOW(), notes = CONCAT(IFNULL(notes, ''), ' | Check-out notes: ', ?) WHERE id = ?");
        $stmt->bind_param("si", $notes, $attendance_id);
        
        if ($stmt->execute()) {
            $_SESSION['attendance_message'] = "Check-out recorded successfully!";
            $_SESSION['attendance_message_type'] = "success";
        } else {
            $_SESSION['attendance_message'] = "Error recording check-out: " . $conn->error;
            $_SESSION['attendance_message_type'] = "error";
        }
    } else {
        $_SESSION['attendance_message'] = "Attendance record not found or doesn't belong to your branch.";
        $_SESSION['attendance_message_type'] = "error";
    }
    
    // Redirect to avoid resubmission
    header("Location: attendance.php");
    exit();
}

// Fix the attendance records query to handle the case when admin_override column doesn't exist
// Find the query that gets attendance records (around line 200-210)

// Get attendance records for the selected date
try {
    $stmt = $conn->prepare("SELECT a.id, a.customer_id, a.check_in, a.check_out, a.notes, 
                          IFNULL(a.admin_override, 0) as admin_override,
                          c.first_name, c.last_name, c.email, c.phone 
                          FROM attendance a 
                          JOIN customers c ON a.customer_id = c.id 
                          WHERE a.branch = ? AND DATE(a.check_in) = ? 
                          ORDER BY a.check_in DESC");
    $stmt->bind_param("ss", $branch_name, $selected_date);
    $stmt->execute();
    $attendance_records = $stmt->get_result();
} catch (Exception $e) {
    // If admin_override column doesn't exist, try without it
    try {
        $stmt = $conn->prepare("SELECT a.id, a.customer_id, a.check_in, a.check_out, a.notes,
                              0 as admin_override,
                              c.first_name, c.last_name, c.email, c.phone 
                              FROM attendance a 
                              JOIN customers c ON a.customer_id = c.id 
                              WHERE a.branch = ? AND DATE(a.check_in) = ? 
                              ORDER BY a.check_in DESC");
        $stmt->bind_param("ss", $branch_name, $selected_date);
        $stmt->execute();
        $attendance_records = $stmt->get_result();
    } catch (Exception $innerEx) {
        // If there's still an error, create an empty result set
        $attendance_records = new mysqli_result();
        $_SESSION['attendance_message'] = "Error loading attendance records: " . $innerEx->getMessage();
        $_SESSION['attendance_message_type'] = "error";
    }
}

// Get all customers for this branch for the check-in form
$stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM customers WHERE branch = ? ORDER BY first_name, last_name");
$stmt->bind_param("s", $branch_name);
$stmt->execute();
$customers = $stmt->get_result();

// Get attendance statistics
$stmt = $conn->prepare("SELECT 
                      (SELECT COUNT(*) FROM attendance WHERE branch = ? AND DATE(check_in) = CURDATE()) as today_count,
                      (SELECT COUNT(*) FROM attendance WHERE branch = ? AND YEARWEEK(check_in, 1) = YEARWEEK(CURDATE(), 1)) as week_count,
                      (SELECT COUNT(*) FROM attendance WHERE branch = ? AND MONTH(check_in) = MONTH(CURDATE()) AND YEAR(check_in) = YEAR(CURDATE())) as month_count,
                      (SELECT COUNT(DISTINCT customer_id) FROM attendance WHERE branch = ? AND DATE(check_in) = CURDATE()) as unique_today");
$stmt->bind_param("ssss", $branch_name, $branch_name, $branch_name, $branch_name);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - Gym Network</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .attendance-container {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .attendance-sidebar {
            flex: 1;
            max-width: 350px;
        }
        
        .attendance-content {
            flex: 2;
        }
        
        .check-in-form {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .check-in-form h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        
        .customer-search {
            position: relative;
            margin-bottom: 15px;
        }
        
        .customer-search input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .customer-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 0 0 4px 4px;
            z-index: 10;
            display: none;
        }
        
        .customer-search-results.active {
            display: block;
        }
        
        .customer-search-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        
        .customer-search-item:hover {
            background-color: #f5f5f5;
        }
        
        .customer-search-item:last-child {
            border-bottom: none;
        }
        
        .stats-card {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .stats-card h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .stat-item {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 600;
            color: #ff6b45;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #777;
        }
        
        .date-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .date-picker {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .date-picker input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .date-picker button {
            padding: 8px 12px;
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .date-picker button:hover {
            background-color: #eee;
        }
        
        .date-nav-buttons {
            display: flex;
            gap: 10px;
        }
        
        .date-nav-buttons a {
            padding: 8px 12px;
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .date-nav-buttons a:hover {
            background-color: #eee;
        }
        
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .attendance-table th,
        .attendance-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .attendance-table th {
            background-color: #f9f9f9;
            color: #555;
            font-weight: 600;
        }
        
        .attendance-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .attendance-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-checked-in {
            background-color: #e8f5e9;
            color: #388e3c;
        }
        
        .status-checked-out {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .action-btn.check-in {
            background-color: #e8f5e9;
            color: #388e3c;
        }
        
        .action-btn.check-out {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        
        .action-btn:hover {
            opacity: 0.9;
        }
        
        .qr-scanner {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            text-align: center;
        }
        
        .qr-scanner h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        
        .scanner-placeholder {
            width: 100%;
            height: 200px;
            background-color: #f5f5f5;
            border: 2px dashed #ddd;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }
        
        .scanner-placeholder i {
            font-size: 48px;
            color: #aaa;
            margin-bottom: 10px;
        }
        
        .scanner-placeholder p {
            color: #777;
            font-size: 14px;
        }
        
        .scan-btn {
            background-color: #ff6b45;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .scan-btn:hover {
            background-color: #e55a35;
        }
        
        .attendance-filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            min-width: 150px;
        }
        
        .export-btn {
            margin-left: auto;
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 8px 15px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .export-btn:hover {
            background-color: #eee;
        }
        
        .admin-override-box {
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .admin-override-box h4 {
            color: #856404;
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .admin-override-box p {
            color: #856404;
            margin-bottom: 15px;
            font-size: 14px;
        }
        
        .admin-override-checkbox {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .admin-override-checkbox input {
            margin-right: 10px;
        }
        
        .admin-override-checkbox label {
            font-weight: 600;
            color: #856404;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .badge-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        @media (max-width: 992px) {
            .attendance-container {
                flex-direction: column;
            }
            
            .attendance-sidebar {
                max-width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .date-navigation {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .date-nav-buttons {
                width: 100%;
                justify-content: space-between;
            }
            
            .attendance-filters {
                flex-wrap: wrap;
            }
            
            .export-btn {
                margin-left: 0;
                margin-top: 10px;
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo">
                <h2>Gym Network</h2>
            </div>
            <div class="menu">
                <ul>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="customers.php"><i class="fas fa-user-friends"></i> Customers</a></li>
                    <li class="active"><a href="attendance.php"><i class="fas fa-clipboard-check"></i> Attendance</a></li>
                    <li><a href="trainers.php"><i class="fas fa-user-tie"></i> Trainers</a></li>
                    <li><a href="#"><i class="fas fa-calendar-alt"></i> Classes</a></li>
                    <li><a href="#"><i class="fas fa-dumbbell"></i> Equipment</a></li>
                    <li><a href="attendance_settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="page-title">
                    <h1>Attendance Management</h1>
                </div>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($admin['name']); ?></span>
                    <div class="user-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <?php if (isset($_SESSION['attendance_message'])): ?>
                    <div class="<?php echo $_SESSION['attendance_message_type'] === 'success' ? 'success-message' : 'error-message'; ?>">
                        <?php echo $_SESSION['attendance_message']; ?>
                    </div>
                    <?php unset($_SESSION['attendance_message'], $_SESSION['attendance_message_type']); ?>
                <?php endif; ?>
                
                <div class="attendance-container">
                    <div class="attendance-sidebar">
                        <!-- Check-in Form -->
                        <div class="check-in-form">
                            <h3><i class="fas fa-sign-in-alt"></i> Check-In Customer</h3>
                            <form action="attendance.php" method="post">
                                <div class="form-group">
                                    <label for="customer_id">Select Customer</label>
                                    <select id="customer_id" name="customer_id" required class="form-control" <?php if (isset($_SESSION['override_customer_id'])) echo 'disabled'; ?>>
                                        <option value="">-- Select Customer --</option>
                                        <?php 
                                        // Reset the customers result pointer
                                        $customers->data_seek(0);
                                        while ($customer = $customers->fetch_assoc()): 
                                            $selected = (isset($_SESSION['override_customer_id']) && $_SESSION['override_customer_id'] == $customer['id']) ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo $customer['id']; ?>" <?php echo $selected; ?>>
                                                <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name'] . ' (' . $customer['email'] . ')'); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                    <?php if (isset($_SESSION['override_customer_id'])): ?>
                                        <input type="hidden" name="customer_id" value="<?php echo $_SESSION['override_customer_id']; ?>">
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label for="notes">Notes (Optional)</label>
                                    <textarea id="notes" name="notes" rows="3" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; resize: vertical;"></textarea>
                                    <small style="color: #777; font-size: 12px; margin-top: 5px; display: block;">Add any relevant information about this check-in</small>
                                </div>
                                
                                <?php if (isset($_SESSION['show_override']) && $_SESSION['show_override']): ?>
                                    <div class="admin-override-box">
                                        <h4><i class="fas fa-exclamation-triangle"></i> Admin Override Required</h4>
                                        <p>This customer has already reached the maximum check-ins for today (<?php echo $max_entries_per_day; ?>). 
                                           As an admin, you can override this restriction.</p>
                                        <div class="admin-override-checkbox">
                                            <input type="checkbox" id="admin_override" name="admin_override" value="1">
                                            <label for="admin_override">I authorize this additional check-in</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <button type="submit" name="check_in" class="action-btn check-in">
                                    <i class="fas fa-sign-in-alt"></i> Check-In
                                </button>
                            </form>
                        </div>
                        
                        <!-- QR Scanner -->
                        <div class="qr-scanner">
                            <h3><i class="fas fa-qrcode"></i> QR Code Scanner</h3>
                            <div id="scanner-container">
                                <div id="scanner-placeholder" class="scanner-placeholder">
                                    <i class="fas fa-qrcode"></i>
                                    <p>Scan customer QR code for quick check-in</p>
                                </div>
                                <video id="qr-video" style="display: none; width: 100%; border-radius: 8px;"></video>
                                <canvas id="qr-canvas" style="display: none;"></canvas>
                                <div id="scanner-overlay" style="display: none; position: absolute; top: 0; left: 0; right: 0; bottom: 0; border: 2px solid #4CAF50; border-radius: 8px; pointer-events: none;"></div>
                            </div>
                            <div id="scan-result" style="margin-top: 10px; display: none; padding: 10px; background-color: #f9f9f9; border-radius: 4px; text-align: left;">
                                <p><strong>Customer:</strong> <span id="scanned-customer-name">Not detected</span></p>
                                <p><strong>Status:</strong> <span id="scan-status">Ready to scan</span></p>
                            </div>
                            <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: center;">
                                <button id="start-scan-btn" class="scan-btn">
                                    <i class="fas fa-camera"></i> Start Scanner
                                </button>
                                <button id="stop-scan-btn" class="scan-btn" style="display: none; background-color: #f44336;">
                                    <i class="fas fa-stop"></i> Stop Scanner
                                </button>
                            </div>
                        </div>
                        
                        <!-- Attendance Settings Info -->
                        <div class="stats-card">
                            <h3><i class="fas fa-info-circle"></i> Attendance Settings</h3>
                            <div style="background-color: #f9f9f9; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                <p><strong>Current Settings:</strong></p>
                                <ul style="margin-top: 10px; padding-left: 20px;">
                                    <li>Maximum check-ins per day: <span style="font-weight: 600;"><?php echo $max_entries_per_day; ?></span></li>
                                    <li>Auto checkout after: <span style="font-weight: 600;"><?php echo $auto_checkout_after; ?> minutes</span></li>
                                </ul>
                                <p style="margin-top: 10px; font-size: 13px; color: #666;">
                                    <i class="fas fa-cog"></i> You can change these settings in the <a href="attendance_settings.php" style="color: #ff6b45; text-decoration: none;">Attendance Settings</a> page.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Attendance Statistics -->
                        <div class="stats-card">
                            <h3><i class="fas fa-chart-bar"></i> Attendance Statistics</h3>
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['today_count']; ?></div>
                                    <div class="stat-label">Today's Check-ins</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['unique_today']; ?></div>
                                    <div class="stat-label">Unique Visitors Today</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['week_count']; ?></div>
                                    <div class="stat-label">This Week</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['month_count']; ?></div>
                                    <div class="stat-label">This Month</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="attendance-content">
                        <!-- Date Navigation -->
                        <div class="date-navigation">
                            <div class="date-picker">
                                <form action="attendance.php" method="get" id="dateForm">
                                    <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="document.getElementById('dateForm').submit();">
                                </form>
                            </div>
                            <div class="date-nav-buttons">
                                <?php
                                $yesterday = date('Y-m-d', strtotime($selected_date . ' -1 day'));
                                $tomorrow = date('Y-m-d', strtotime($selected_date . ' +1 day'));
                                $today = date('Y-m-d');
                                ?>
                                <a href="attendance.php?date=<?php echo $yesterday; ?>">
                                    <i class="fas fa-chevron-left"></i> Previous Day
                                </a>
                                <a href="attendance.php?date=<?php echo $today; ?>" <?php if ($selected_date == $today) echo 'style="background-color: #e8f5e9;"'; ?>>
                                    <i class="fas fa-calendar-day"></i> Today
                                </a>
                                <a href="attendance.php?date=<?php echo $tomorrow; ?>">
                                    Next Day <i class="fas fa-chevron-right"></i>
                                </a>
                            </div>
                        </div>
                        
                        <!-- Attendance Filters -->
                        <div class="attendance-filters" style="background-color: #f9f9f9; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px;">
                            <div style="flex: 1;">
                                <label for="statusFilter" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #555;">Status</label>
                                <select class="filter-select" id="statusFilter" onchange="filterAttendance()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="">All Status</option>
                                    <option value="checked-in">Checked In</option>
                                    <option value="checked-out">Checked Out</option>
                                </select>
                            </div>
                            
                            <div style="flex: 2;">
                                <label for="customerFilter" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #555;">Search Customer</label>
                                <input type="text" placeholder="Enter name or email..." id="customerFilter" onkeyup="filterAttendance()" class="filter-select" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            
                            <div style="align-self: flex-end;">
                                <button class="export-btn" onclick="exportAttendance()" style="background-color: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 10px 15px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; font-size: 14px;">
                                    <i class="fas fa-file-export"></i> Export
                                </button>
                            </div>
                        </div>
                        
                       
                        <!-- Debug Info -->
                        <?php if (isset($_SESSION['debug_info'])): ?>
                            <div style="background-color: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 10px; border-radius: 4px;">
                                <h4>Debug Info:</h4>
                                <pre><?php echo $_SESSION['debug_info']; ?></pre>
                            </div>
                            <?php unset($_SESSION['debug_info']); ?>
                        <?php endif; ?>

                        <!-- Attendance Records -->
                        <div class="section">
                            <div class="section-header">
                                <h2>Attendance Records for <?php echo date('F j, Y', strtotime($selected_date)); ?></h2>
                            </div>
                            
                            <div class="table-container">
                                <table class="attendance-table" id="attendanceTable">
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th>Check-In Time</th>
                                            <th>Check-Out Time</th>
                                            <th>Duration</th>
                                            <th>Status</th>
                                            <th>Notes</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($attendance_records->num_rows > 0): ?>
                                            <?php while ($record = $attendance_records->fetch_assoc()): ?>
                                                <?php
                                                $check_in_time = new DateTime($record['check_in']);
                                                $check_out_time = $record['check_out'] ? new DateTime($record['check_out']) : null;
                                                
                                                // Calculate duration
                                                if ($check_out_time) {
                                                    $interval = $check_in_time->diff($check_out_time);
                                                    $total_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
                                                    $hours = floor($total_minutes / 60);
                                                    $minutes = $total_minutes % 60;
                                                    $duration_text = sprintf('%d hr %d min', $hours, $minutes);
                                                } else {
                                                    $now = new DateTime();
                                                    $interval = $check_in_time->diff($now);
                                                    $total_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
                                                    $hours = floor($total_minutes / 60);
                                                    $minutes = $total_minutes % 60;
                                                    $duration_text = sprintf('%d hr %d min (ongoing)', $hours, $minutes);
                                                    
                                                    // Check if auto-checkout will happen soon
                                                    $minutes_until_auto_checkout = $auto_checkout_after - $total_minutes;
                                                    if ($minutes_until_auto_checkout > 0 && $minutes_until_auto_checkout <= 30) {
                                                        $duration_text .= ' <span style="color: #f44336; font-size: 11px;">(Auto checkout in ' . $minutes_until_auto_checkout . ' min)</span>';
                                                    }
                                                }
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div class="customer-details">
                                                            <?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?>
                                                            <?php if ($record['admin_override']): ?>
                                                                <span class="badge badge-warning">Override</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td><?php echo $check_in_time->format('h:i A'); ?></td>
                                                    <td><?php echo $check_out_time ? $check_out_time->format('h:i A') : '-'; ?></td>
                                                    <td><?php echo $duration_text; ?></td>
                                                    <td>
                                                        <?php if ($check_out_time): ?>
                                                            <span class="attendance-status status-checked-out">Checked Out</span>
                                                        <?php else: ?>
                                                            <span class="attendance-status status-checked-in">Checked In</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($record['notes'])): ?>
                                                            <div style="max-width: 200px; max-height: 60px; overflow-y: auto; background-color: #f9f9f9; padding: 8px; border-radius: 4px; border-left: 3px solid #ff6b45;">
                                                                <?php echo htmlspecialchars($record['notes']); ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">No notes</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!$check_out_time): ?>
                                                            <button class="action-btn check-out" onclick="showCheckoutModal(<?php echo $record['id']; ?>)">
                                                                <i class="fas fa-sign-out-alt"></i> Check-Out
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-muted">Already checked out</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="no-data">No attendance records found for this date</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Check-out Modal -->
    <div id="checkoutModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <span class="close" onclick="closeCheckoutModal()">&times;</span>
            <h2>Record Check-Out</h2>
            <form id="checkoutForm" action="attendance.php" method="post">
                <input type="hidden" id="attendance_id" name="attendance_id">
                
                <div class="form-group">
                    <label for="checkout_notes">Notes (Optional)</label>
                    <textarea id="checkout_notes" name="checkout_notes" rows="3" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; resize: vertical;"></textarea>
                    <small style="color: #777; font-size: 12px; margin-top: 5px; display: block;">Add any relevant information about this check-out</small>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="closeCheckoutModal()" style="padding: 8px 15px; background-color: #f5f5f5; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                    <button type="submit" name="check_out" class="action-btn check-out">
                        <i class="fas fa-sign-out-alt"></i> Confirm Check-Out
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function showCheckoutModal(attendanceId) {
            document.getElementById('attendance_id').value = attendanceId;
            document.getElementById('checkoutModal').style.display = 'block';
        }
        
        function closeCheckoutModal() {
            document.getElementById('checkoutModal').style.display = 'none';
        }
        
        // Filter attendance records
        function filterAttendance() {
            const statusFilter = document.getElementById('statusFilter').value.toLowerCase();
            const customerFilter = document.getElementById('customerFilter').value.toLowerCase();
            
            const table = document.getElementById('attendanceTable');
            const rows = table.getElementsByTagName('tr');
            
            let visibleCount = 0;
            
            // Start from index 1 to skip the header row
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                
                // Skip the "No attendance records found" row
                if (row.cells.length === 1 && row.cells[0].classList.contains('no-data')) {
                    continue;
                }
                
                const customerName = row.cells[0].textContent.toLowerCase();
                const statusCell = row.cells[4];
                const status = statusCell.textContent.toLowerCase();
                
                // Check if row matches all filters
                const matchesStatus = statusFilter === '' || status.includes(statusFilter);
                const matchesCustomer = customerFilter === '' || customerName.includes(customerFilter);
                
                // Show/hide row based on filter matches
                if (matchesStatus && matchesCustomer) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }
            
            // Show "no results" message if no matches found
            if (visibleCount === 0 && rows.length > 1) {
                // Check if we already have a no results row
                let noResultsRow = table.querySelector('.no-results-row');
                
                if (!noResultsRow) {
                    // Create a new row for no results message
                    noResultsRow = document.createElement('tr');
                    noResultsRow.className = 'no-results-row';
                    const cell = document.createElement('td');
                    cell.colSpan = 7;
                    cell.className = 'no-data';
                    cell.textContent = 'No matching records found';
                    noResultsRow.appendChild(cell);
                    table.querySelector('tbody').appendChild(noResultsRow);
                } else {
                    noResultsRow.style.display = '';
                }
            } else {
                // Hide the no results row if we have matches
                const noResultsRow = table.querySelector('.no-results-row');
                if (noResultsRow) {
                    noResultsRow.style.display = 'none';
                }
            }
        }
        
        // Export attendance records
        function exportAttendance() {
            // Get the table
            const table = document.getElementById('attendanceTable');
            
            // Create CSV content
            let csv = [];
            
            // Add header row
            const headerRow = [];
            const headers = table.querySelectorAll('th');
            for (let i = 0; i < headers.length - 1; i++) { // Skip the Actions column
                headerRow.push(headers[i].textContent);
            }
            csv.push(headerRow.join(','));
            
            // Add data rows
            const rows = table.querySelectorAll('tbody tr');
            for (let i = 0; i < rows.length; i++) {
                // Skip the "No attendance records found" row
                if (rows[i].cells.length === 1 && rows[i].cells[0].classList.contains('no-data')) {
                    continue;
                }
                
                const row = [];
                const cells = rows[i].querySelectorAll('td');
                for (let j = 0; j < cells.length - 1; j++) { // Skip the Actions column
                    // Get text content and clean it up
                    let cellText = cells[j].textContent.trim().replace(/,/g, ' ');
                    
                    // For status column, get just "Checked In" or "Checked Out"
                    if (j === 4) {
                        const statusSpan = cells[j].querySelector('.attendance-status');
                        if (statusSpan) {
                            cellText = statusSpan.textContent.trim();
                        }
                    }
                    
                    row.push(cellText);
                }
                csv.push(row.join(','));
            }
            
            // Create CSV file
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            
            // Create download link
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', `attendance_${document.getElementById('date').value}.csv`);
            document.body.appendChild(link);
            
            // Trigger download
            link.click();
            
            // Clean up
            document.body.removeChild(link);
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Initialize select2 for better dropdown experience
        document.addEventListener('DOMContentLoaded', function() {
            // You can add select2 or similar library initialization here
            // For now, we'll just focus on the core functionality
        });
    </script>

    <!-- Include jsQR library for QR code scanning -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>

    <script>
        // QR Scanner functionality
        let video = document.getElementById('qr-video');
        let canvasElement = document.getElementById('qr-canvas');
        let canvas = canvasElement.getContext('2d');
        let scannerPlaceholder = document.getElementById('scanner-placeholder');
        let startScanButton = document.getElementById('start-scan-btn');
        let stopScanButton = document.getElementById('stop-scan-btn');
        let scanResult = document.getElementById('scan-result');
        let scanStatus = document.getElementById('scan-status');
        let scannedCustomerName = document.getElementById('scanned-customer-name');
        
        let scanning = false;
        
        // Start QR scanner
        startScanButton.addEventListener('click', () => {
            startScanner();
        });
        
        // Stop QR scanner
        stopScanButton.addEventListener('click', () => {
            stopScanner();
        });
        
        function startScanner() {
            // Request camera access
            navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
                .then(function(stream) {
                    scanning = true;
                    scannerPlaceholder.style.display = 'none';
                    video.style.display = 'block';
                    startScanButton.style.display = 'none';
                    stopScanButton.style.display = 'inline-flex';
                    scanResult.style.display = 'block';
                    scanStatus.textContent = 'Scanning...';
                    
                    video.srcObject = stream;
                    video.setAttribute('playsinline', true); // Required for iOS
                    video.play();
                    requestAnimationFrame(tick);
                })
                .catch(function(err) {
                    console.error('Error accessing camera:', err);
                    alert('Error accessing camera: ' + err.message);
                    scanning = false;
                });
        }
        
        function stopScanner() {
            scanning = false;
            if (video.srcObject) {
                video.srcObject.getTracks().forEach(track => track.stop());
            }
            video.style.display = 'none';
            scannerPlaceholder.style.display = 'flex';
            startScanButton.style.display = 'inline-flex';
            stopScanButton.style.display = 'none';
            scanStatus.textContent = 'Scanner stopped';
        }
        
        // Improve the tick function for better QR code detection
        function tick() {
            if (!scanning) return;
            
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvasElement.height = video.videoHeight;
                canvasElement.width = video.videoWidth;
                canvas.drawImage(video, 0, 0, canvasElement.width, canvasElement.height);
                var imageData = canvas.getImageData(0, 0, canvasElement.width, canvasElement.height);
                
                try {
                    var code = jsQR(imageData.data, imageData.width, imageData.height, {
                        inversionAttempts: "dontInvert",
                    });
                    
                    if (code) {
                        // QR code detected
                        console.log("QR Code detected:", code.data);
                        
                        // Highlight the QR code
                        if (code.location) {
                            drawQRCodeOutline(code.location);
                        }
                        
                        // Check if the QR code data is a valid customer ID
                        if (isValidQRCode(code.data)) {
                            // Extract customer ID from QR code
                            const customerId = extractCustomerId(code.data);
                            
                            if (customerId) {
                                // Find customer name from the select dropdown
                                const customerSelect = document.getElementById('customer_id');
                                let customerName = "Unknown";
                                
                                for (let i = 0; i < customerSelect.options.length; i++) {
                                    if (customerSelect.options[i].value == customerId) {
                                        customerName = customerSelect.options[i].text;
                                        break;
                                    }
                                }
                                
                                // Update the UI
                                scannedCustomerName.textContent = customerName;
                                scanStatus.textContent = "Processing check-in...";
                                
                                // Automatically check in the customer
                                if (checkInCustomer(customerId)) {
                                    // Play a success sound
                                    playBeepSound();
                                    
                                    // Stop scanning after successful scan
                                    stopScanner();
                                    return;
                                }
                            } else {
                                scanStatus.textContent = "Invalid customer ID in QR code";
                            }
                        } else {
                            scanStatus.textContent = "Invalid QR code format. Please try again.";
                        }
                    }
                } catch (error) {
                    console.error("Error processing QR code:", error);
                }
            }
            
            requestAnimationFrame(tick);
        }

        // Add function to draw outline around detected QR code
        function drawQRCodeOutline(location) {
            const overlayCanvas = document.createElement('canvas');
            overlayCanvas.width = canvasElement.width;
            overlayCanvas.height = canvasElement.height;
            const overlayCtx = overlayCanvas.getContext('2d');
            
            overlayCtx.beginPath();
            overlayCtx.moveTo(location.topLeftCorner.x, location.topLeftCorner.y);
            overlayCtx.lineTo(location.topRightCorner.x, location.topRightCorner.y);
            overlayCtx.lineTo(location.bottomRightCorner.x, location.bottomRightCorner.y);
            overlayCtx.lineTo(location.bottomLeftCorner.x, location.bottomLeftCorner.y);
            overlayCtx.closePath();
            overlayCtx.lineWidth = 4;
            overlayCtx.strokeStyle = '#4CAF50';
            overlayCtx.stroke();
            
            // Add temporary visual feedback
            const tempCanvas = document.createElement('canvas');
            tempCanvas.width = canvasElement.width;
            tempCanvas.height = canvasElement.height;
            const tempCtx = tempCanvas.getContext('2d');
            
            tempCtx.drawImage(video, 0, 0, canvasElement.width, canvasElement.height);
            tempCtx.drawImage(overlayCanvas, 0, 0);
            
            // Display the frame with the QR code highlighted
            canvas.drawImage(tempCanvas, 0, 0);
        }

        // Add function to play a beep sound when QR code is detected
        function playBeepSound() {
            try {
                const beep = new Audio("data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YU" + Array(1000).join("123"));
                beep.volume = 0.2;
                beep.play();
            } catch (e) {
                console.log("Audio playback failed:", e);
            }
        }

        // Improve the QR code scanner functionality to better handle customer detection
        function isValidQRCode(qrData) {
            // Check if the QR code data is in the expected format
            // Format: "GYM_CUSTOMER_ID:{id}"
            return qrData && qrData.startsWith("GYM_CUSTOMER_ID:") && qrData.length > 15;
        }

        function extractCustomerId(qrData) {
            // Extract the customer ID from the QR code data
            // Format: "GYM_CUSTOMER_ID:{id}"
            try {
                return qrData.split(":")[1].trim();
            } catch (error) {
                console.error("Error extracting customer ID from QR code:", error);
                return null;
            }
        }

// Fix the QR scanner functionality
// Find the checkInCustomer function in the JavaScript section and replace it with this improved version:

function checkInCustomer(customerId) {
    // Set the customer ID in the select dropdown
    const customerSelect = document.getElementById('customer_id');
    
    // Verify the customer ID exists in the dropdown
    let customerExists = false;
    let customerName = "Unknown";
    
    for (let i = 0; i < customerSelect.options.length; i++) {
        if (customerSelect.options[i].value == customerId) {
            customerExists = true;
            customerName = customerSelect.options[i].text;
            customerSelect.selectedIndex = i;
            break;
        }
    }
    
    if (!customerExists) {
        scanStatus.textContent = "Error: Customer ID not found in this branch";
        return false;
    }
    
    // Add a note that this was a QR code check-in
    document.getElementById('notes').value = "Checked in via QR code scanner";
    
    // Update the UI
    scannedCustomerName.textContent = customerName;
    scanStatus.textContent = "Processing check-in...";
    
    // Check if admin override checkbox exists and is needed
    const adminOverrideCheckbox = document.getElementById('admin_override');
    if (adminOverrideCheckbox) {
        // If override is needed, check the box automatically for QR scans
        adminOverrideCheckbox.checked = true;
    }
    
    // Submit the check-in form
    setTimeout(() => {
        document.querySelector('button[name="check_in"]').click();
    }, 1000);
    
    return true;
}

        // Function to generate QR code URL for a customer
        function generateCustomerQRCode(customerId) {
            // This generates a URL for a QR code with the format "GYM_CUSTOMER_ID:{id}"
            const qrData = `GYM_CUSTOMER_ID:${customerId}`;
            const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(qrData)}`;
            return qrCodeUrl;
        }
    </script>
</body>
</html>

