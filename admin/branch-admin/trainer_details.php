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

// Get admin data and branch information
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT a.name, a.email, b.id as branch_id, b.name as branch_name, b.location 
                      FROM admins a 
                      LEFT JOIN branches b ON a.id = b.admin_id 
                      WHERE a.id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$branch_id = $admin['branch_id'];
$branch_name = $admin['branch_name'];

// Check if trainer ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "Trainer ID is required.";
    header("Location: trainers.php");
    exit();
}

$trainer_id = $_GET['id'];
$error_message = '';
$success_message = '';

// Get trainer details
try {
    $stmt = $conn->prepare("SELECT * FROM trainers WHERE id = ? AND branch = ?");
    $stmt->bind_param("is", $trainer_id, $branch_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['error_message'] = "Trainer not found or does not belong to your branch.";
        header("Location: trainers.php");
        exit();
    }
    
    $trainer = $result->fetch_assoc();
} catch (Exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Get trainer's schedule
try {
    $stmt = $conn->prepare("
        SELECT ts.*, c.first_name, c.last_name, c.id as customer_id, 
               tca.start_date as assignment_start_date, tca.end_date as assignment_end_date
        FROM training_sessions ts 
        JOIN customers c ON ts.customer_id = c.id 
        LEFT JOIN trainer_customer_assignments tca ON ts.trainer_id = tca.trainer_id AND ts.customer_id = tca.customer_id
        WHERE ts.trainer_id = ?
        ORDER BY ts.session_date ASC, ts.start_time ASC
    ");
    $stmt->bind_param("i", $trainer_id);
    $stmt->execute();
    $schedule_result = $stmt->get_result();
    $schedules = [];
    
    while ($row = $schedule_result->fetch_assoc()) {
        $schedules[] = $row;
    }
} catch (Exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Get all customers for this branch
try {
    $stmt = $conn->prepare("SELECT id, first_name, last_name FROM customers WHERE branch = ? ORDER BY first_name, last_name");
    $stmt->bind_param("s", $branch_name);
    $stmt->execute();
    $customers_result = $stmt->get_result();
    $customers = [];
    
    while ($row = $customers_result->fetch_assoc()) {
        $customers[] = $row;
    }
} catch (Exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Get trainer's customer assignments
try {
    $stmt = $conn->prepare("
        SELECT tca.*, c.first_name, c.last_name, c.email, c.phone
        FROM trainer_customer_assignments tca 
        JOIN customers c ON tca.customer_id = c.id 
        WHERE tca.trainer_id = ? 
        ORDER BY tca.status, tca.start_date DESC
    ");
    $stmt->bind_param("i", $trainer_id);
    $stmt->execute();
    $assignments_result = $stmt->get_result();
    $assignments = [];
    
    while ($row = $assignments_result->fetch_assoc()) {
        $assignments[] = $row;
    }
} catch (Exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Process form submission for adding a new session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_session') {
    $customer_id = $_POST['customer_id'];
    $session_date = $_POST['session_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $notes = $_POST['notes'];
    $assignment_start_date = $_POST['assignment_start_date'];
    $assignment_end_date = $_POST['assignment_end_date'];
    
    // Validate input
    $errors = [];
    
    if (empty($customer_id)) {
        $errors[] = "Customer is required";
    }
    
    if (empty($session_date)) {
        $errors[] = "Session date is required";
    }
    
    if (empty($start_time)) {
        $errors[] = "Start time is required";
    }
    
    if (empty($end_time)) {
        $errors[] = "End time is required";
    }
    
    if (empty($assignment_start_date)) {
        $errors[] = "Assignment start date is required";
    }
    
    if (!empty($assignment_start_date) && !empty($assignment_end_date)) {
        if (strtotime($assignment_end_date) < strtotime($assignment_start_date)) {
            $errors[] = "Assignment end date must be after start date";
        }
    }
    
    // Check if session date is within assignment period
    if (!empty($session_date) && !empty($assignment_start_date) && !empty($assignment_end_date)) {
        if (strtotime($session_date) < strtotime($assignment_start_date) || strtotime($session_date) > strtotime($assignment_end_date)) {
            $errors[] = "Session date must be within the assignment period";
        }
    }
    
    // Check if end time is after start time
    if (!empty($start_time) && !empty($end_time)) {
        $start = strtotime($start_time);
        $end = strtotime($end_time);
        
        if ($end <= $start) {
            $errors[] = "End time must be after start time";
        }
    }
    
    // If no errors, insert the session
    if (empty($errors)) {
        // First, create a schedule entry
        $stmt = $conn->prepare("INSERT INTO trainer_schedules (trainer_id, title, description, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, 'scheduled')");
        $title = "Training Session";
        $full_start = date('Y-m-d H:i:s', strtotime($session_date . ' ' . $start_time));
        $full_end = date('Y-m-d H:i:s', strtotime($session_date . ' ' . $end_time));
        $stmt->bind_param("issss", $trainer_id, $title, $notes, $full_start, $full_end);
        
        if ($stmt->execute()) {
            $schedule_id = $conn->insert_id;
            
            // Then, create a training session
            $stmt = $conn->prepare("INSERT INTO training_sessions (trainer_id, customer_id, schedule_id, session_date, start_time, end_time, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')");
            $stmt->bind_param("iiissss", $trainer_id, $customer_id, $schedule_id, $session_date, $start_time, $end_time, $notes);
            
            if ($stmt->execute()) {
                // Check if customer is already assigned to this trainer
                $stmt = $conn->prepare("SELECT id FROM trainer_customer_assignments WHERE trainer_id = ? AND customer_id = ? AND status = 'active'");
                $stmt->bind_param("ii", $trainer_id, $customer_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                // If not assigned, create an assignment
                if ($result->num_rows === 0) {
                    $stmt = $conn->prepare("INSERT INTO trainer_customer_assignments (trainer_id, customer_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')");
                    $stmt->bind_param("iiss", $trainer_id, $customer_id, $assignment_start_date, $assignment_end_date);
                    $stmt->execute();
                } else {
                    // Update existing assignment with new dates
                    $assignment = $result->fetch_assoc();
                    $stmt = $conn->prepare("UPDATE trainer_customer_assignments SET start_date = ?, end_date = ? WHERE id = ?");
                    $stmt->bind_param("ssi", $assignment_start_date, $assignment_end_date, $assignment['id']);
                    $stmt->execute();
                }
                
                $success_message = "Training session scheduled successfully.";
                // Refresh the page to show updated schedule
                header("Location: trainer_details.php?id=" . $trainer_id . "&success=schedule_added");
                exit();
            } else {
                $error_message = "Error scheduling training session: " . $conn->error;
            }
        } else {
            $error_message = "Error creating schedule: " . $conn->error;
        }
    } else {
        $error_message = "Please correct the following errors: " . implode(", ", $errors);
    }
}

// Handle form submission for ending an assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'end_assignment') {
    $assignment_id = $_POST['assignment_id'];
    
    $stmt = $conn->prepare("UPDATE trainer_customer_assignments SET status = 'completed', end_date = CURDATE() WHERE id = ? AND trainer_id = ?");
    $stmt->bind_param("ii", $assignment_id, $trainer_id);
    
    if ($stmt->execute()) {
        $success_message = "Customer assignment ended successfully.";
        // Refresh the page to show updated assignments
        header("Location: trainer_details.php?id=" . $trainer_id . "&success=assignment_ended");
        exit();
    } else {
        $error_message = "Error ending assignment: " . $conn->error;
    }
}

// Check for success message in URL
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'schedule_added':
            $success_message = "Training session scheduled successfully.";
            break;
        case 'assignment_ended':
            $success_message = "Customer assignment ended successfully.";
            break;
    }
}

// Helper function to get color based on status
function getStatusColor($status) {
    $colors = [
        'scheduled' => '#3498db',
        'completed' => '#2ecc71',
        'cancelled' => '#e74c3c',
        'no_show' => '#f39c12'
    ];
    
    return $colors[$status] ?? '#3498db';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainer Details - <?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?></title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.css">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>
    <style>
        :root {
            --primary-color: #3498db;
            --primary-hover: #2980b9;
            --secondary-color: #2ecc71;
            --secondary-hover: #27ae60;
            --danger-color: #e74c3c;
            --danger-hover: #c0392b;
            --warning-color: #f39c12;
            --warning-hover: #e67e22;
            --light-bg: #f8f9fa;
            --border-color: #e9ecef;
            --text-dark: #343a40;
            --text-muted: #6c757d;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--text-dark);
        }
        
        .dashboard-content {
            padding: 20px;
        }
        
        .trainer-profile {
            background-color: #fff;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .profile-header {
            padding: 30px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: #f8f9fa;
        }
        
        .profile-info {
            display: flex;
            align-items: center;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            color: white;
            overflow: hidden;
            position: relative;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-avatar i {
            font-size: 40px;
        }
        
        .profile-name h2 {
            font-size: 24px;
            color: var(--text-dark);
            margin: 0 0 5px 0;
            font-weight: 600;
        }
        
        .profile-title {
            font-size: 16px;
            color: var(--text-muted);
            margin: 0;
        }
        
        .profile-status {
            font-size: 12px;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 500;
            margin-top: 10px;
            display: inline-block;
        }
        
        .status-active {
            background-color: rgba(46, 204, 113, 0.15);
            color: var(--secondary-color);
        }
        
        .status-inactive {
            background-color: rgba(108, 117, 125, 0.15);
            color: var(--text-muted);
        }
        
        .status-on_leave {
            background-color: rgba(243, 156, 18, 0.15);
            color: var(--warning-color);
        }
        
        .profile-actions {
            display: flex;
            gap: 10px;
        }
        
        .profile-action {
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 14px;
            text-decoration: none;
            transition: background-color 0.2s;
            display: inline-block;
            text-align: center;
            font-weight: 500;
        }
        
        .action-edit {
            background-color: rgba(52, 152, 219, 0.1);
            color: var(--primary-color);
        }
        
        .action-edit:hover {
            background-color: rgba(52, 152, 219, 0.2);
        }
        
        .action-schedule {
            background-color: rgba(46, 204, 113, 0.1);
            color: var(--secondary-color);
        }
        
        .action-schedule:hover {
            background-color: rgba(46, 204, 113, 0.2);
        }
        
        .profile-body {
            padding: 30px;
        }
        
        .profile-section {
            margin-bottom: 30px;
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .profile-section:last-child {
            margin-bottom: 0;
        }
        
        .section-title {
            font-size: 18px;
            color: var(--text-dark);
            margin: 0 0 20px 0;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
        }
        
        .section-title .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
            border-radius: 4px;
        }
        
        .contact-info {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
        }
        
        .contact-item i {
            width: 20px;
            margin-right: 10px;
            color: var(--primary-color);
        }
        
        .contact-label {
            font-size: 14px;
            color: var(--text-muted);
            margin-right: 5px;
            font-weight: 500;
        }
        
        .contact-value {
            font-size: 14px;
            color: var(--text-dark);
        }
        
        .bio-text {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
        }
        
        .customer-list {
            margin-top: 20px;
        }
        
        .customer-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .customer-item:last-child {
            border-bottom: none;
        }
        
        .customer-info {
            display: flex;
            align-items: center;
        }
        
        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--light-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .customer-avatar i {
            font-size: 18px;
            color: var(--text-muted);
        }
        
        .customer-name {
            font-size: 15px;
            color: var(--text-dark);
            margin: 0 0 3px 0;
            font-weight: 500;
        }
        
        .customer-email {
            font-size: 13px;
            color: var(--text-muted);
            margin: 0;
        }
        
        .customer-details {
            font-size: 13px;
            color: var(--text-muted);
            margin: 5px 0 0 0;
        }
        
        .assignment-status {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .assignment-active {
            background-color: rgba(46, 204, 113, 0.15);
            color: var(--secondary-color);
        }
        
        .assignment-completed {
            background-color: rgba(108, 117, 125, 0.15);
            color: var(--text-muted);
        }
        
        .assignment-cancelled {
            background-color: rgba(231, 76, 60, 0.15);
            color: var(--danger-color);
        }
        
        .session-list {
            margin-top: 20px;
        }
        
        .session-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .session-item:last-child {
            border-bottom: none;
        }
        
        .session-info {
            display: flex;
            flex-direction: column;
        }
        
        .session-date {
            font-size: 15px;
            color: var(--text-dark);
            margin: 0 0 3px 0;
            font-weight: 500;
        }
        
        .session-time {
            font-size: 13px;
            color: var(--text-muted);
            margin: 0;
        }
        
        .session-customer {
            font-size: 13px;
            color: var(--primary-color);
            margin: 5px 0 0 0;
        }
        
        .session-status {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .session-scheduled {
            background-color: rgba(52, 152, 219, 0.15);
            color: var(--primary-color);
        }
        
        .session-completed {
            background-color: rgba(46, 204, 113, 0.15);
            color: var(--secondary-color);
        }
        
        .session-cancelled {
            background-color: rgba(231, 76, 60, 0.15);
            color: var(--danger-color);
        }
        
        .session-no-show {
            background-color: rgba(243, 156, 18, 0.15);
            color: var(--warning-color);
        }
        
        .calendar-container {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .fc .fc-daygrid-day.fc-day-today {
            background-color: rgba(52, 152, 219, 0.1);
        }

        .fc .fc-button-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .fc .fc-button-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }

        .fc .fc-button-primary:not(:disabled).fc-button-active, 
        .fc .fc-button-primary:not(:disabled):active {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }

        .fc-event-title-container {
            padding: 2px 4px;
        }

        .fc-h-event {
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .fc-event {
            cursor: pointer;
            border-radius: 4px;
        }
        
        .fc-event-title {
            font-weight: normal;
            padding: 2px 5px;
        }
        
        .fc-daygrid-event {
            white-space: normal;
        }
        
        .assignment-period {
            background-color: rgba(52, 152, 219, 0.2);
            border: none !important;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
        }
        
        .alert i {
            margin-right: 10px;
            font-size: 18px;
        }
        
        .alert-success {
            background-color: rgba(46, 204, 113, 0.15);
            color: var(--secondary-color);
            border-left: 4px solid var(--secondary-color);
        }
        
        .alert-error {
            background-color: rgba(231, 76, 60, 0.15);
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }
        
        .add-session-form {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--text-dark);
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            color: var(--text-dark);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .assignment-dates {
            background-color: var(--light-bg);
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
            border-left: 4px solid var(--primary-color);
        }
        
        .date-range-group {
            display: flex;
            gap: 15px;
        }
        
        .date-range-group .form-group {
            flex: 1;
        }
        
        .validation-info {
            margin-top: 15px;
            padding: 15px;
            background-color: rgba(243, 156, 18, 0.1);
            border-radius: 6px;
            font-size: 13px;
            color: var(--warning-color);
            border-left: 4px solid var(--warning-color);
        }
        
        .validation-info ul {
            margin: 10px 0 0 0;
            padding-left: 20px;
        }
        
        .validation-info li {
            margin-bottom: 5px;
        }
        
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.2s;
            font-weight: 500;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: var(--danger-hover);
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            background-color: var(--light-bg);
            border-radius: 8px;
        }
        
        .empty-state i {
            font-size: 36px;
            color: var(--text-muted);
            margin-bottom: 15px;
        }
        
        .empty-state p {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 0;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            
            .profile-actions {
                width: 100%;
            }
            
            .profile-action {
                flex: 1;
                text-align: center;
            }
            
            .contact-info {
                grid-template-columns: 1fr;
            }
            
            .date-range-group {
                flex-direction: column;
                gap: 10px;
            }
        }
        
        @media (max-width: 576px) {
            .profile-section {
                padding: 15px;
            }
            
            .section-title {
                font-size: 16px;
            }
            
            .calendar-container {
                padding: 10px;
            }
            
            .fc .fc-toolbar {
                flex-direction: column;
                gap: 10px;
            }
            
            .fc .fc-toolbar-title {
                font-size: 1.2em;
            }
            
            .customer-item, .session-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .customer-item > div:last-child, .session-item > div:last-child {
                align-self: flex-start;
            }
        }

        .empty-state h4 {
            font-size: 18px;
            color: var(--text-dark);
            margin: 10px 0;
            font-weight: 500;
        }
        
        .empty-state i {
            font-size: 48px;
            color: var(--text-muted);
            margin-bottom: 15px;
            opacity: 0.7;
        }
        
        .empty-state p {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 15px;
            max-width: 300px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .empty-state .btn {
            display: inline-block;
            transition: all 0.3s ease;
        }
        
        .empty-state .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .mt-3 {
            margin-top: 15px;
        }

        .highlight-form {
            animation: highlight-pulse 1.5s ease;
        }
        
        @keyframes highlight-pulse {
            0% { box-shadow: 0 0 0 0 rgba(52, 152, 219, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(52, 152, 219, 0); }
            100% { box-shadow: 0 0 0 0 rgba(52, 152, 219, 0); }
        }
        
        /* Profile photo upload button */
        .photo-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            background-color: var(--primary-color);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .photo-upload-btn:hover {
            background-color: var(--primary-hover);
            transform: scale(1.1);
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
                    <li><a href="attendance.php"><i class="fas fa-clipboard-check"></i> Attendance</a></li>
                    <li class="active"><a href="trainers.php"><i class="fas fa-user-tie"></i> Trainers</a></li>
                    <li><a href="#"><i class="fas fa-calendar-alt"></i> Classes</a></li>
                    <li><a href="#"><i class="fas fa-dumbbell"></i> Equipment</a></li>
                    <li><a href="#"><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="<?php echo isset($_SESSION['base_path']) ? $_SESSION['base_path'] : ''; ?>/admin/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="page-title">
                    <h1>Trainer Details</h1>
                </div>
                <div class="user-info">
                    <span><?php echo htmlspecialchars($admin['name']); ?></span>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
                
                <div class="trainer-profile">
                    <div class="profile-header">
                        <div class="profile-info">
                            <div class="profile-avatar">
                                <?php if (!empty($trainer['profile_photo']) && file_exists('../../' . $trainer['profile_photo'])): ?>
                                    <img src="../../<?php echo htmlspecialchars($trainer['profile_photo']); ?>" alt="<?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?>">
                                <?php else: ?>
                                    <i class="fas fa-user-tie"></i>
                                <?php endif; ?>
                                <a href="edit_trainer.php?id=<?php echo $trainer['id']; ?>" class="photo-upload-btn" title="Change photo">
                                    <i class="fas fa-camera"></i>
                                </a>
                            </div>
                            <div class="profile-name">
                                <h2><?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?></h2>
                                <p class="profile-title"><?php echo !empty($trainer['specialization']) ? htmlspecialchars($trainer['specialization']) : 'Fitness Trainer'; ?></p>
                                <span class="profile-status status-<?php echo $trainer['status']; ?>">
                                    <?php 
                                        $status_text = ucfirst($trainer['status']);
                                        if ($trainer['status'] === 'on_leave') {
                                            $status_text = 'On Leave';
                                        }
                                        echo $status_text;
                                    ?>
                                </span>
                            </div>
                        </div>
                        <div class="profile-actions">
                            <a href="edit_trainer.php?id=<?php echo $trainer['id']; ?>" class="profile-action action-edit">
                                <i class="fas fa-edit"></i> Edit Profile
                            </a>
                            <a href="trainer_schedule.php?trainer_id=<?php echo $trainer['id']; ?>" class="profile-action action-schedule">
                                <i class="fas fa-calendar"></i> View Schedule
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="profile-section">
                    <h3 class="section-title">Contact Information</h3>
                    <div class="contact-info">
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <span class="contact-label">Email:</span>
                            <span class="contact-value"><?php echo htmlspecialchars($trainer['email']); ?></span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-phone"></i>
                            <span class="contact-label">Phone:</span>
                            <span class="contact-value"><?php echo htmlspecialchars($trainer['phone']); ?></span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-building"></i>
                            <span class="contact-label">Branch:</span>
                            <span class="contact-value"><?php echo htmlspecialchars($trainer['branch']); ?></span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span class="contact-label">Joined:</span>
                            <span class="contact-value"><?php echo date('F j, Y', strtotime($trainer['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($trainer['bio'])): ?>
                <div class="profile-section">
                    <h3 class="section-title">Bio</h3>
                    <p class="bio-text"><?php echo nl2br(htmlspecialchars($trainer['bio'])); ?></p>
                </div>
                <?php endif; ?>
                
                <div class="profile-section">
                    <h3 class="section-title">Trainer Schedule</h3>
                    <div class="calendar-container">
                        <div id="calendar"></div>
                        <div id="calendar-loading" class="text-center" style="padding: 20px;">
                            <i class="fas fa-spinner fa-spin"></i> Loading calendar...
                        </div>
                    </div>
                    
                    <div class="add-session-form">
                        <h3>Add New Training Session</h3>
                        <form method="POST" action="" id="sessionForm">
                            <input type="hidden" name="action" value="add_session">
                            
                            <div class="form-group">
                                <label for="customer_id" class="form-label">Customer</label>
                                <select id="customer_id" name="customer_id" class="form-control" required>
                                    <option value="">Select Customer</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo $customer['id']; ?>">
                                            <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="assignment-dates">
                                <h4>Assignment Period</h4>
                                <div class="date-range-group">
                                    <div class="form-group">
                                        <label for="assignment_start_date" class="form-label">Start Date</label>
                                        <input type="date" id="assignment_start_date" name="assignment_start_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="assignment_end_date" class="form-label">End Date</label>
                                        <input type="date" id="assignment_end_date" name="assignment_end_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="session_date" class="form-label">Session Date</label>
                                <input type="date" id="session_date" name="session_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                                <small id="date_error" class="text-danger" style="color: var(--danger-color); display: none; margin-top: 5px; font-size: 12px;">
                                    <i class="fas fa-exclamation-circle"></i> Session date must be within assignment period.
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label for="start_time" class="form-label">Start Time</label>
                                <input type="time" id="start_time" name="start_time" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="end_time" class="form-label">End Time</label>
                                <input type="time" id="end_time" name="end_time" class="form-control" required>
                                <small id="time_error" class="text-danger" style="color: var(--danger-color); display: none; margin-top: 5px; font-size: 12px;">
                                    <i class="fas fa-exclamation-circle"></i> End time must be after start time.
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea id="notes" name="notes" class="form-control" placeholder="Add session notes..."></textarea>
                            </div>
                            
                            <div class="validation-info">
                                <i class="fas fa-exclamation-triangle"></i> Scheduling rules:
                                <ul>
                                    <li>Session date must be within the assignment period</li>
                                    <li>Trainer cannot be double-booked at the same time</li>
                                    <li>Customer cannot be assigned to multiple trainers for overlapping periods</li>
                                    <li>Maximum 3 sessions per customer per week</li>
                                </ul>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Schedule Session</button>
                        </form>
                    </div>
                </div>
                
                <div class="profile-section">
                    <h3 class="section-title">Assigned Customers</h3>
                    <?php if (count($assignments) > 0): ?>
                        <div class="customer-list">
                            <?php foreach ($assignments as $assignment): ?>
                                <div class="customer-item">
                                    <div class="customer-info">
                                        <div class="customer-avatar">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div>
                                            <h4 class="customer-name"><?php echo htmlspecialchars($assignment['first_name'] . ' ' . $assignment['last_name']); ?></h4>
                                            <p class="customer-email"><?php echo htmlspecialchars($assignment['email']); ?></p>
                                            <p class="customer-details">
                                                <i class="fas fa-calendar-alt"></i> 
                                                From: <?php echo date('M j, Y', strtotime($assignment['start_date'])); ?>
                                                <?php if (!empty($assignment['end_date'])): ?>
                                                    To: <?php echo date('M j, Y', strtotime($assignment['end_date'])); ?>
                                                <?php else: ?>
                                                    (No end date)
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="assignment-status assignment-<?php echo $assignment['status']; ?>">
                                            <?php echo ucfirst($assignment['status']); ?>
                                        </span>
                                        <?php if ($assignment['status'] === 'active'): ?>
                                            <form method="post" action="" style="display: inline-block; margin-left: 10px;">
                                                <input type="hidden" name="action" value="end_assignment">
                                                <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to end this assignment?')">
                                                    End
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>No customers assigned to this trainer yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="profile-section">
                    <h3 class="section-title">Upcoming Sessions</h3>
                    <?php if (count($schedules) > 0): ?>
                        <div class="session-list">
                            <?php 
                            $upcoming_sessions = array_filter($schedules, function($session) {
                                return strtotime($session['session_date']) >= strtotime('today');
                            });
                            
                            if (count($upcoming_sessions) > 0):
                                foreach ($upcoming_sessions as $session): 
                            ?>
                                <div class="session-item">
                                    <div class="session-info">
                                        <h4 class="session-date"><?php echo date('l, F j, Y', strtotime($session['session_date'])); ?></h4>
                                        <p class="session-time"><?php echo date('g:i A', strtotime($session['start_time'])) . ' - ' . date('g:i A', strtotime($session['end_time'])); ?></p>
                                        <p class="session-customer">with <?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?></p>
                                    </div>
                                    <span class="session-status session-<?php echo $session['status']; ?>">
                                        <?php echo ucfirst($session['status']); ?>
                                    </span>
                                </div>
                            <?php 
                                endforeach;
                            else:
                            ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-times"></i>
                                    <h4>No Upcoming Sessions</h4>
                                    <p>There are no upcoming training sessions scheduled for this trainer.</p>
                                    <a href="#sessionForm" class="btn btn-primary btn-sm mt-3">
                                        <i class="fas fa-plus-circle"></i> Schedule New Session
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h4>No Sessions Found</h4>
                            <p>This trainer doesn't have any training sessions in the system yet.</p>
                            <a href="#sessionForm" class="btn btn-primary btn-sm mt-3">
                                <i class="fas fa-plus-circle"></i> Create First Session
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                height: 'auto',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                buttonText: {
                    today: 'Today',
                    month: 'Month',
                    week: 'Week',
                    day: 'Day'
                },
                firstDay: 1, // Start week on Monday
                events: [
                    <?php foreach ($schedules as $schedule): ?>
                    {
                        id: '<?php echo $schedule['id']; ?>',
                        title: '<?php echo addslashes($schedule['first_name'] . ' ' . $schedule['last_name']); ?>',
                        start: '<?php echo $schedule['session_date'] . 'T' . $schedule['start_time']; ?>',
                        end: '<?php echo $schedule['session_date'] . 'T' . $schedule['end_time']; ?>',
                        color: '<?php echo getStatusColor($schedule['status']); ?>',
                        extendedProps: {
                            status: '<?php echo $schedule['status']; ?>',
                            notes: '<?php echo addslashes($schedule['notes'] ?? ''); ?>'
                        }
                    },
                    <?php endforeach; ?>
                    
                    <?php 
                    // Add assignment periods for visual reference
                    foreach ($assignments as $assignment): 
                        if ($assignment['status'] === 'active' && !empty($assignment['start_date']) && !empty($assignment['end_date'])): 
                    ?>
                    {
                        title: 'Assignment: <?php echo addslashes($assignment['first_name'] . ' ' . $assignment['last_name']); ?>',
                        start: '<?php echo $assignment['start_date']; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($assignment['end_date'] . ' +1 day')); ?>',
                        rendering: 'background',
                        color: 'rgba(52, 152, 219, 0.2)',
                        className: 'assignment-period',
                        display: 'background'
                    },
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                ],
                eventTimeFormat: {
                    hour: 'numeric',
                    minute: '2-digit',
                    meridiem: 'short'
                },
                eventClick: function(info) {
                    // You could show a modal with event details here
                    alert('Session with ' + info.event.title + '\n' + 
                          'Status: ' + info.event.extendedProps.status + 
                          (info.event.extendedProps.notes ? '\nNotes: ' + info.event.extendedProps.notes : ''));
                }
            });
            
            calendar.render();
            document.getElementById('calendar-loading').style.display = 'none';
            
            // Set session date to match assignment start date by default
            document.getElementById('assignment_start_date').addEventListener('change', function() {
                document.getElementById('session_date').value = this.value;
            });
            
            // Validate that session date is within assignment period
            document.getElementById('session_date').addEventListener('change', function() {
                var sessionDate = new Date(this.value);
                var startDate = new Date(document.getElementById('assignment_start_date').value);
                var endDateInput = document.getElementById('assignment_end_date');
                var endDate = endDateInput.value ? new Date(endDateInput.value) : null;
                
                // Reset date error
                document.getElementById('date_error').style.display = 'none';
                
                if (sessionDate < startDate) {
                    document.getElementById('date_error').style.display = 'block';
                    this.value = document.getElementById('assignment_start_date').value;
                }
                
                if (endDate && sessionDate > endDate) {
                    document.getElementById('date_error').style.display = 'block';
                    this.value = document.getElementById('assignment_start_date').value;
                }
            });
            
            // Validate time inputs
            document.getElementById('start_time').addEventListener('change', function() {
                var startTime = this.value;
                var endTimeInput = document.getElementById('end_time');
                
                // Reset time error
                document.getElementById('time_error').style.display = 'none';
                
                if (endTimeInput.value && endTimeInput.value <= startTime) {
                    document.getElementById('time_error').style.display = 'block';
                    endTimeInput.value = '';
                }
            });
            
            document.getElementById('end_time').addEventListener('change', function() {
                var endTime = this.value;
                var startTimeInput = document.getElementById('start_time');
                
                // Reset time error
                document.getElementById('time_error').style.display = 'none';
                
                if (startTimeInput.value && endTime <= startTimeInput.value) {
                    document.getElementById('time_error').style.display = 'block';
                    this.value = '';
                }
            });
            
            // Form validation before submit
            document.getElementById('sessionForm').addEventListener('submit', function(e) {
                var isValid = true;
                
                // Check if session date is within assignment period
                var sessionDate = new Date(document.getElementById('session_date').value);
                var startDate = new Date(document.getElementById('assignment_start_date').value);
                var endDateInput = document.getElementById('assignment_end_date');
                var endDate = endDateInput.value ? new Date(endDateInput.value) : null;
                
                // Reset date error
                document.getElementById('date_error').style.display = 'none';
                
                if (sessionDate < startDate) {
                    document.getElementById('date_error').style.display = 'block';
                    isValid = false;
                }
                
                if (endDate && sessionDate > endDate) {
                    document.getElementById('date_error').style.display = 'block';
                    isValid = false;
                }
                
                // Check if end time is after start time
                var startTime = document.getElementById('start_time').value;
                var endTime = document.getElementById('end_time').value;
                
                // Reset time error
                document.getElementById('time_error').style.display = 'none';
                
                if (startTime && endTime && endTime <= startTime) {
                    document.getElementById('time_error').style.display = 'block';
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                }
            });

            // Smooth scroll to session form when clicking the schedule button in empty state
            document.querySelectorAll('.empty-state .btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href');
                    const targetElement = document.querySelector(targetId);
                    if (targetElement) {
                        window.scrollTo({
                            top: targetElement.offsetTop - 20,
                            behavior: 'smooth'
                        });
                        // Highlight the form briefly
                        targetElement.classList.add('highlight-form');
                        setTimeout(function() {
                            targetElement.classList.remove('highlight-form');
                        }, 1500);
                    }
                });
            });
        });
    </script>
</body>
</html>

