<?php
// Start session
session_start();

// Include database connection
require_once '../../includes/db_connect.php';

// Initialize variables
$sessions = [];
$assignments = [];
$routines = [];
$error_message = null;
$success_message = null;
$no_trainers_message = null;

// Check if user is logged in and is a branch admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'branch_admin') {
    // Redirect to login page if not logged in or not a branch admin
    header("Location: ../login.php");
    exit();
}

// Get admin data
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT a.name, a.email, b.name as branch_name, b.location 
                        FROM admins a 
                        LEFT JOIN branches b ON a.id = b.admin_id 
                        WHERE a.id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$branch_name = $admin['branch_name'];

// Get all trainers for this branch
$stmt = $conn->prepare("SELECT id, first_name, last_name FROM trainers WHERE branch = ? AND status = 'active' ORDER BY first_name, last_name");
$stmt->bind_param("s", $branch_name);
$stmt->execute();
$trainers_result = $stmt->get_result();
$trainers = [];
while ($row = $trainers_result->fetch_assoc()) {
    $trainers[] = $row;
}

if (count($trainers) === 0) {
    $no_trainers_message = "No active trainers found. Please add trainers first.";
}

// Get selected trainer
$selected_trainer_id = isset($_GET['trainer_id']) ? $_GET['trainer_id'] : (count($trainers) > 0 ? $trainers[0]['id'] : null);

// Get all customers for this branch
$stmt = $conn->prepare("SELECT id, first_name, last_name FROM customers WHERE branch = ? ORDER BY first_name, last_name");
$stmt->bind_param("s", $branch_name);
$stmt->execute();
$customers_result = $stmt->get_result();
$customers = [];
while ($row = $customers_result->fetch_assoc()) {
    $customers[] = $row;
}

// Process form submission for adding a new session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_session') {
    $trainer_id = $_POST['trainer_id'];
    $customer_id = $_POST['customer_id'];
    $session_date = $_POST['session_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $notes = $_POST['notes'];
    $assignment_start_date = $_POST['assignment_start_date'];
    $assignment_end_date = $_POST['assignment_end_date'];
    
    // Validate input
    $errors = [];
    
    if (empty($trainer_id)) {
        $errors[] = "Trainer is required";
    }
    
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
                $selected_trainer_id = $trainer_id; // Keep the selected trainer
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

// Get trainer sessions for calendar
$sessions = [];
if ($selected_trainer_id) {
    $stmt = $conn->prepare("SELECT ts.*, c.first_name, c.last_name, tca.start_date as assignment_start_date, tca.end_date as assignment_end_date 
                            FROM training_sessions ts 
                            JOIN customers c ON ts.customer_id = c.id 
                            LEFT JOIN trainer_customer_assignments tca ON ts.trainer_id = tca.trainer_id AND ts.customer_id = tca.customer_id
                            WHERE ts.trainer_id = ?");
    $stmt->bind_param("i", $selected_trainer_id);
    $stmt->execute();
    $sessions_result = $stmt->get_result();
    
    while ($row = $sessions_result->fetch_assoc()) {
        $sessions[] = [
            'id' => $row['id'],
            'title' => $row['first_name'] . ' ' . $row['last_name'],
            'start' => $row['session_date'] . 'T' . $row['start_time'],
            'end' => $row['session_date'] . 'T' . $row['end_time'],
            'status' => $row['status'],
            'color' => getStatusColor($row['status']),
            'customer_id' => $row['customer_id'],
            'notes' => $row['notes'],
            'assignment_start_date' => $row['assignment_start_date'],
            'assignment_end_date' => $row['assignment_end_date']
        ];
    }
}

// After the line that gets trainer sessions for calendar (around line 150), add this code to get assignment periods
// Get trainer-customer assignments for background highlighting
$assignments = [];
if ($selected_trainer_id) {
    $stmt = $conn->prepare("SELECT tca.*, c.first_name, c.last_name 
                            FROM trainer_customer_assignments tca 
                            JOIN customers c ON tca.customer_id = c.id 
                            WHERE tca.trainer_id = ? AND tca.status = 'active'");
    $stmt->bind_param("i", $selected_trainer_id);
    $stmt->execute();
    $assignments_result = $stmt->get_result();
    
    while ($row = $assignments_result->fetch_assoc()) {
        // Only add if both dates are set
        if (!empty($row['start_date']) && !empty($row['end_date'])) {
            $assignments[] = [
                'id' => 'assignment_' . $row['id'],
                'title' => $row['first_name'] . ' ' . $row['last_name'] . ' (Assignment)',
                'start' => $row['start_date'],
                'end' => $row['end_date'],
                'display' => 'background',
                'color' => 'rgba(52, 152, 219, 0.2)',
                'customer_id' => $row['customer_id']
            ];
        }
    }
}

// Get trainer routines for calendar
$routines = [];
if ($selected_trainer_id) {
    $stmt = $conn->prepare("SELECT * FROM trainer_routines WHERE trainer_id = ?");
    $stmt->bind_param("i", $selected_trainer_id);
    $stmt->execute();
    $routines_result = $stmt->get_result();
    
    while ($row = $routines_result->fetch_assoc()) {
        $routines[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'daysOfWeek' => [getDayNumber($row['day_of_week'])],
            'startTime' => $row['start_time'],
            'endTime' => $row['end_time'],
            'color' => '#f0f0f0',
            'textColor' => '#666',
            'description' => $row['description']
        ];
    }
}

// Helper function to get day number for FullCalendar
function getDayNumber($day) {
    $days = [
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6
    ];
    
    return $days[$day] ?? 0;
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
    <title>Trainer Schedule - <?php echo htmlspecialchars($branch_name); ?></title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.css">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>
    <style>
        .schedule-container {
            display: flex;
            gap: 30px;
        }
        
        .schedule-sidebar {
            width: 300px;
            flex-shrink: 0;
        }
        
        .schedule-main {
            flex: 1;
        }
        
        .trainer-selector {
            background-color: #fff;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .trainer-selector h3 {
            font-size: 16px;
            color: #333;
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #f5f5f5;
        }
        
        .trainer-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .trainer-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 5px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .trainer-item:hover {
            background-color: #f5f5f5;
        }
        
        .trainer-item.active {
            background-color: #e3f2fd;
        }
        
        .trainer-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
        }
        
        .trainer-avatar i {
            font-size: 16px;
            color: #999;
        }
        
        .trainer-name {
            font-size: 14px;
            color: #333;
        }
        
        .add-session-form {
            background-color: #fff;
            border-radius: 4px;
            padding: 20px;
        }
        
        .add-session-form h3 {
            font-size: 16px;
            color: #333;
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #f5f5f5;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 14px;
            color: #333;
            transition: border-color 0.2s;
        }
        
        .form-control:focus {
            border-color: #3498db;
            outline: none;
        }
        
        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #f5f5f5;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .btn-primary {
            background-color: #3498db;
            color: white;
        }
        
        .btn-secondary {
            background-color: #f5f5f5;
            color: #333;
        }
        
        .calendar-container {
            background-color: #fff;
            border-radius: 4px;
            padding: 20px;
            height: 700px;
        }
        
        .fc-event {
            cursor: pointer;
        }
        
        .fc-event-title {
            font-weight: normal;
        }
        
        .fc-daygrid-event {
            white-space: normal;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }
        
        .alert-error {
            background-color: #ffebee;
            color: #d32f2f;
            border-left: 4px solid #d32f2f;
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            border-radius: 4px;
            width: 500px;
            max-width: 90%;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f5f5f5;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }
        
        .close-modal {
            font-size: 20px;
            color: #999;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding-top: 15px;
            border-top: 1px solid #f5f5f5;
        }
        
        .session-details {
            margin-bottom: 15px;
        }
        
        .session-details p {
            margin: 5px 0;
            font-size: 14px;
            color: #666;
        }
        
        .session-details strong {
            color: #333;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-scheduled {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        
        .status-completed {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-cancelled {
            background-color: #ffebee;
            color: #d32f2f;
        }
        
        .status-no-show {
            background-color: #fff8e1;
            color: #f57f17;
        }
        
        .assignment-dates {
            background-color: #f9f9f9;
            border-radius: 4px;
            padding: 15px;
            margin-top: 15px;
            border-left: 4px solid #3498db;
        }
        
        .assignment-dates h4 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 14px;
            color: #333;
        }
        
        .date-range-group {
            display: flex;
            gap: 10px;
        }
        
        .date-range-group .form-group {
            flex: 1;
        }

        /* Add this to the CSS section (inside the <style> tag) to style the assignment periods */
        .assignment-background {
            opacity: 0.3;
            border: none !important;
        }

        .fc-event.fc-bg-event {
            border-radius: 0;
        }

        .fc-event.fc-bg-event .fc-event-title {
            font-style: italic;
            font-size: 0.85em;
            margin-left: 5px;
            color: #333;
        }

        .fc-daygrid-day.assignment-day {
            background-color: rgba(52, 152, 219, 0.1);
        }

        .assignment-info {
            background-color: #f8f9fa;
            border-left: 4px solid #3498db;
            padding: 10px 15px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }

        .assignment-info h4 {
            margin-top: 0;
            margin-bottom: 8px;
            color: #333;
            font-size: 16px;
        }

        .assignment-info ul {
            margin: 0;
            padding-left: 20px;
        }

        .assignment-info li {
            margin-bottom: 5px;
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
                    <h1>Trainer Schedule</h1>
                </div>
                <div class="user-info">
                    <span><?php echo htmlspecialchars($admin['name']); ?></span>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <?php
                // Add this right after the opening of the dashboard-content div (around line 260)
                // This adds an informational section about assignments
                ?>
                <!-- Assignment information section -->
                <div class="assignment-info">
                    <h4>Trainer Assignment Periods</h4>
                    <p>The calendar shows highlighted periods when the trainer is assigned to customers:</p>
                    <ul>
                        <li>Light blue background indicates an active assignment period</li>
                        <li>Sessions can only be scheduled within assignment periods</li>
                        <li>A trainer cannot be double-booked with multiple customers at the same time</li>
                    </ul>
                </div>
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error">
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($no_trainers_message)): ?>
                    <div class="alert alert-error">
                        <?php echo $no_trainers_message; ?>
                    </div>
                <?php endif; ?>
                
                <div class="schedule-container">
                    <div class="schedule-sidebar">
                        <div class="trainer-selector">
                            <h3>Select Trainer</h3>
                            <div class="trainer-list">
                                <?php foreach ($trainers as $trainer): ?>
                                    <a href="trainer_schedule.php?trainer_id=<?php echo $trainer['id']; ?>" class="trainer-item <?php echo $trainer['id'] == $selected_trainer_id ? 'active' : ''; ?>" style="text-decoration: none;">
                                        <div class="trainer-avatar">
                                            <i class="fas fa-user-tie"></i>
                                        </div>
                                        <div class="trainer-name">
                                            <?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="add-session-form">
                            <h3>Add Training Session</h3>
                            <form method="POST" action="trainer_schedule.php<?php echo $selected_trainer_id ? '?trainer_id='.$selected_trainer_id : ''; ?>">
                                <input type="hidden" name="action" value="add_session">
                                
                                <div class="form-group">
                                    <label for="trainer_id" class="form-label">Trainer</label>
                                    <select id="trainer_id" name="trainer_id" class="form-control" required>
                                        <?php foreach ($trainers as $trainer): ?>
                                            <option value="<?php echo $trainer['id']; ?>" <?php echo $trainer['id'] == $selected_trainer_id ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
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
                                </div>
                                
                                <div class="form-group">
                                    <label for="start_time" class="form-label">Start Time</label>
                                    <input type="time" id="start_time" name="start_time" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="end_time" class="form-label">End Time</label>
                                    <input type="time" id="end_time" name="end_time" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea id="notes" name="notes" class="form-control" placeholder="Add session notes..."></textarea>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Schedule Session</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="schedule-main">
                        <div class="calendar-container">
                            <div id="calendar" style="height: 100%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Session Details Modal -->
    <div id="sessionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Session Details</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="session-details">
                    <p><strong>Customer:</strong> <span id="modalCustomer"></span></p>
                    <p><strong>Assignment Period:</strong> <span id="modalAssignmentPeriod"></span></p>
                    <p><strong>Session Date:</strong> <span id="modalDate"></span></p>
                    <p><strong>Time:</strong> <span id="modalTime"></span></p>
                    <p><strong>Status:</strong> <span id="modalStatus"></span></p>
                    <p><strong>Notes:</strong> <span id="modalNotes"></span></p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Close</button>
                <a href="#" id="editSessionBtn" class="btn btn-primary">Edit Session</a>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            
            if (!calendarEl) {
                console.error("Calendar element not found!");
                return;
            }
            
            console.log("Initializing calendar...");
            
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: <?php echo json_encode(array_merge($sessions, $assignments)); ?>,
                eventTimeFormat: {
                    hour: 'numeric',
                    minute: '2-digit',
                    meridiem: 'short'
                },
                eventClick: function(info) {
                    if (info.event && info.event.id) {
                        showSessionDetails(info.event);
                    } else {
                        console.error("Event data is incomplete");
                    }
                },
                eventContent: function(arg) {
                    let timeText = arg.timeText;
                    let title = arg.event.title;
                    
                    return {
                        html: '<div class="fc-event-time">' + timeText + '</div>' +
                              '<div class="fc-event-title">' + title + '</div>'
                    };
                },
                <?php
                // Add this to the CSS section (inside the <style> tag) to style the assignment periods
                ?>
                <?php
                /* Assignment periods styling */
                ?>
                <?php
                // Add this to the calendar initialization options, after eventContent
                ?>
                <?php
                // Style background events
                ?>
                eventDidMount: function(info) {
                    // Style background events
                    if (info.event.display === 'background') {
                        info.el.classList.add('assignment-background');
                        
                        // Add customer name to background event
                        if (!info.el.querySelector('.fc-event-title')) {
                            var titleEl = document.createElement('div');
                            titleEl.classList.add('fc-event-title');
                            titleEl.innerText = info.event.title.replace(' (Assignment)', '');
                            info.el.appendChild(titleEl);
                        }
                    }
                },

                <?php
                // Add this to the calendar initialization options
                ?>
                <?php
                // Highlight days within assignment periods
                ?>
                datesSet: function(dateInfo) {
                    // Highlight days that fall within assignment periods
                    setTimeout(function() {
                        var dayEls = document.querySelectorAll('.fc-daygrid-day');
                        dayEls.forEach(function(el) {
                            el.classList.remove('assignment-day');
                            
                            var date = el.getAttribute('data-date');
                            if (!date) return;
                            
                            var currentDate = new Date(date);
                            
                            // Check if this date falls within any assignment period
                            <?php foreach ($assignments as $assignment): ?>
                            var assignmentStart = new Date('<?php echo $assignment['start']; ?>');
                            var assignmentEnd = new Date('<?php echo $assignment['end']; ?>');
                            
                            if (currentDate >= assignmentStart && currentDate <= assignmentEnd) {
                                el.classList.add('assignment-day');
                            }
                            <?php endforeach; ?>
                        });
                    }, 100);
                },
            });
            
            calendar.render();
            
            // Add recurring events (routines)
            var routines = <?php echo json_encode($routines); ?>;
            routines.forEach(function(routine) {
                calendar.addEvent(routine);
            });
            
            // Add assignment periods for background highlighting
            var assignments = <?php echo json_encode($assignments); ?>;
            assignments.forEach(function(assignment) {
                calendar.addEvent(assignment);
            });
            
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
                
                if (sessionDate < startDate) {
                    alert('Session date cannot be before the assignment start date');
                    this.value = document.getElementById('assignment_start_date').value;
                }
                
                if (endDate && sessionDate > endDate) {
                    alert('Session date cannot be after the assignment end date');
                    this.value = document.getElementById('assignment_start_date').value;
                }
            });

            <?php
            // Enhance the JavaScript validation to check for assignment periods
            // Add this to the DOMContentLoaded event handler, after the calendar initialization
            ?>
            <?php
            // Customer assignment validation
            ?>
            // Get customer assignments for validation
            var customerAssignments = {};
            <?php
            // Pre-populate customer assignments
            ?>
            <?php
            // Pre-populate customer assignments
            if (!empty($assignments)) {
                echo "// Pre-populate customer assignments\n";
                foreach ($assignments as $assignment) {
                    echo "if (!customerAssignments[" . $assignment['customer_id'] . "]) customerAssignments[" . $assignment['customer_id'] . "] = [];\n";
                    echo "customerAssignments[" . $assignment['customer_id'] . "].push({
                        start: new Date('" . $assignment['start'] . "'),
                        end: new Date('" . $assignment['end'] . "')
                    });\n";
                }
            }
            ?>

            // Update session date validation to check assignment periods
            document.getElementById('customer_id').addEventListener('change', function() {
                var customerId = this.value;
                var sessionDateInput = document.getElementById('session_date');
                var assignmentStartInput = document.getElementById('assignment_start_date');
                var assignmentEndInput = document.getElementById('assignment_end_date');
                
                // Reset date inputs if no customer selected
                if (!customerId) {
                    return;
                }
                
                // If we have existing assignments for this customer
                if (customerAssignments[customerId] && customerAssignments[customerId].length > 0) {
                    var latestAssignment = customerAssignments[customerId][customerAssignments[customerId].length - 1];
                    
                    // Set the assignment dates to match the existing assignment
                    assignmentStartInput.value = latestAssignment.start.toISOString().split('T')[0];
                    assignmentEndInput.value = latestAssignment.end.toISOString().split('T')[0];
                    
                    // Set the session date to the start date
                    sessionDateInput.value = assignmentStartInput.value;
                    
                    // Update min/max constraints
                    sessionDateInput.min = assignmentStartInput.value;
                    sessionDateInput.max = assignmentEndInput.value;
                } else {
                    // Default to today for new assignments
                    var today = new Date().toISOString().split('T')[0];
                    assignmentStartInput.value = today;
                    assignmentEndInput.value = new Date(Date.now() + 30*24*60*60*1000).toISOString().split('T')[0];
                    sessionDateInput.value = today;
                    sessionDateInput.min = today;
                    sessionDateInput.max = "";
                }
            });

            // Enhanced validation before form submission
            document.querySelector('.add-session-form form').addEventListener('submit', function(e) {
                var trainerId = document.getElementById('trainer_id').value;
                var customerId = document.getElementById('customer_id').value;
                var sessionDate = document.getElementById('session_date').value;
                var startTime = document.getElementById('start_time').value;
                var endTime = document.getElementById('end_time').value;
                
                // Basic validation
                if (!trainerId || !customerId || !sessionDate || !startTime || !endTime) {
                    return; // Let the browser handle required fields
                }
                
                // Check if session date is within assignment period
                var assignmentStart = new Date(document.getElementById('assignment_start_date').value);
                var assignmentEnd = new Date(document.getElementById('assignment_end_date').value);
                var sessionDateTime = new Date(sessionDate);
                
                if (sessionDateTime < assignmentStart || sessionDateTime > assignmentEnd) {
                    e.preventDefault();
                    alert('Session date must be within the assignment period.');
                    return;
                }
                
                // Check for time validity
                var startDateTime = new Date(sessionDate + 'T' + startTime);
                var endDateTime = new Date(sessionDate + 'T' + endTime);
                
                if (endDateTime <= startDateTime) {
                    e.preventDefault();
                    alert('End time must be after start time.');
                    return;
                }
                
                // Check for overlapping sessions
                var hasOverlap = false;
                calendar.getEvents().forEach(function(event) {
                    // Skip background events and the same customer's events
                    if (event.display === 'background' || event.extendedProps.customer_id == customerId) {
                        return;
                    }
                    
                    var eventStart = event.start;
                    var eventEnd = event.end;
                    
                    // Only check events on the same day
                    if (eventStart.toDateString() === startDateTime.toDateString()) {
                        // Check for time overlap
                        if ((startDateTime < eventEnd && endDateTime > eventStart)) {
                            hasOverlap = true;
                            e.preventDefault();
                            alert('This trainer already has a session scheduled with ' + event.title + ' during this time.');
                            return;
                        }
                    }
                });
                
                if (hasOverlap) {
                    return;
                }
            });
        });
        
        // Show session details in modal
        function showSessionDetails(event) {
            document.getElementById('modalCustomer').textContent = event.title;
            
            // Display assignment period if available
            var assignmentStartDate = event.extendedProps.assignment_start_date;
            var assignmentEndDate = event.extendedProps.assignment_end_date;
            
            if (assignmentStartDate) {
                var formattedStartDate = new Date(assignmentStartDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                var assignmentPeriod = formattedStartDate;
                
                if (assignmentEndDate) {
                    var formattedEndDate = new Date(assignmentEndDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                    assignmentPeriod += ' to ' + formattedEndDate;
                } else {
                    assignmentPeriod += ' (No end date)';
                }
                
                document.getElementById('modalAssignmentPeriod').textContent = assignmentPeriod;
            } else {
                document.getElementById('modalAssignmentPeriod').textContent = 'Not specified';
            }
            
            document.getElementById('modalDate').textContent = event.start.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            document.getElementById('modalTime').textContent = event.start.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) + ' - ' + event.end.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            
            var status = event.extendedProps.status || 'scheduled';
            var statusText = status.charAt(0).toUpperCase() + status.slice(1);
            document.getElementById('modalStatus').innerHTML = '<span class="status-badge status-' + status + '">' + statusText + '</span>';
            
            document.getElementById('modalNotes').textContent = event.extendedProps.notes || 'No notes';
            
            // Set edit link
            if (event.id) {
                document.getElementById('editSessionBtn').href = 'edit_session.php?id=' + event.id;
                document.getElementById('editSessionBtn').style.display = 'inline-block';
            } else {
                document.getElementById('editSessionBtn').style.display = 'none';
            }
            
            // Show modal
            document.getElementById('sessionModal').style.display = 'block';
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('sessionModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            var modal = document.getElementById('sessionModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>

