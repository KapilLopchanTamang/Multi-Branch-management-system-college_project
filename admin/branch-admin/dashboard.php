<?php
// Start session
session_start();

// Include database connection and auth functions
require_once '../../includes/db_connect.php';
require_once '../includes/auth_functions.php';

// Check if user is logged in and is a branch admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'branch_admin') {
    // Redirect to login page if not logged in or not a branch admin
    header("Location: ../login.php");
    exit();
}

// Get admin data and branch name in a single query
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

// Get all dashboard stats in a single query
$stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM customers WHERE branch = ?) AS customer_count,
        (SELECT COUNT(*) FROM trainers WHERE branch = ? AND status = 'active') AS trainer_count,
        (SELECT COUNT(*) FROM memberships m 
         JOIN customers c ON m.customer_id = c.id 
         WHERE c.branch = ? AND m.status = 'Active' AND m.end_date >= CURDATE()) AS active_count,
        (SELECT COUNT(*) FROM attendance 
         WHERE branch = ? AND DATE(check_in) = CURDATE()) AS today_attendance
");
$stmt->bind_param("ssss", $branch_name, $branch_name, $branch_name, $branch_name);
$stmt->execute();
$result = $stmt->get_result();
$stats = $result->fetch_assoc();

// Get current month and year for calendar
$current_month = date('m');
$current_year = date('Y');
$current_day = date('d');
$first_day_of_month = date('N', strtotime("$current_year-$current_month-01"));
$days_in_month = date('t', strtotime("$current_year-$current_month-01"));

// Get trainer schedules for current month with trainer info in a single query
$stmt = $conn->prepare("
    SELECT ts.id, ts.title, ts.start_time, ts.end_time, ts.status,
           t.id as trainer_id, t.first_name, t.last_name, t.profile_photo,
           c.first_name as customer_first_name, c.last_name as customer_last_name
    FROM trainer_schedules ts
    JOIN trainers t ON ts.trainer_id = t.id
    LEFT JOIN training_sessions trs ON ts.id = trs.schedule_id
    LEFT JOIN customers c ON trs.customer_id = c.id
    WHERE t.branch = ? 
    AND MONTH(ts.start_time) = ? 
    AND YEAR(ts.start_time) = ?
    ORDER BY ts.start_time
");
$stmt->bind_param("sii", $branch_name, $current_month, $current_year);
$stmt->execute();
$trainer_schedules_result = $stmt->get_result();

// Process trainer schedules data
$schedule_data = [];
$today_schedules = [];
$today_date = date('Y-m-d');

while ($row = $trainer_schedules_result->fetch_assoc()) {
    $day = date('d', strtotime($row['start_time']));
    
    // Store schedules by day
    if (!isset($schedule_data[$day])) {
        $schedule_data[$day] = [];
    }
    $schedule_data[$day][] = $row;
    
    // Store today's schedules separately
    if (date('Y-m-d', strtotime($row['start_time'])) === $today_date) {
        $today_schedules[] = $row;
    }
}

// Get top 3 trainers by session count
$stmt = $conn->prepare("
    SELECT t.id, t.first_name, t.last_name, t.profile_photo, 
           COUNT(ts.id) as session_count,
           (COUNT(ts.id) * 100 / (SELECT COUNT(*) FROM trainer_schedules ts2 
                                  JOIN trainers t2 ON ts2.trainer_id = t2.id 
                                  WHERE t2.branch = ?)) as percentage
    FROM trainers t
    LEFT JOIN trainer_schedules ts ON t.id = ts.trainer_id
    WHERE t.branch = ? AND t.status = 'active'
    GROUP BY t.id
    ORDER BY session_count DESC
    LIMIT 3
");
$stmt->bind_param("ss", $branch_name, $branch_name);
$stmt->execute();
$top_trainers = $stmt->get_result();

// Get recent activities (combined query)
$stmt = $conn->prepare("
    (SELECT 'new_customer' as type, c.first_name, c.last_name, c.created_at as timestamp
     FROM customers c
     WHERE c.branch = ?
     ORDER BY c.created_at DESC
     LIMIT 2)
    UNION
    (SELECT 'check_in' as type, c.first_name, c.last_name, a.check_in as timestamp
     FROM attendance a
     JOIN customers c ON a.customer_id = c.id
     WHERE a.branch = ?
     ORDER BY a.check_in DESC
     LIMIT 2)
    UNION
    (SELECT 'new_trainer' as type, t.first_name, t.last_name, t.created_at as timestamp
     FROM trainers t
     WHERE t.branch = ?
     ORDER BY t.created_at DESC
     LIMIT 1)
    ORDER BY timestamp DESC
    LIMIT 5
");
$stmt->bind_param("sss", $branch_name, $branch_name, $branch_name);
$stmt->execute();
$activities = $stmt->get_result();

// Get user permissions for menu display
$user_permissions = $_SESSION['permissions'] ?? [];
$has_all_permissions = in_array('all', $user_permissions);

// Define menu items with their required permissions
$menu_items = [
    ['url' => 'dashboard.php', 'icon' => 'fas fa-tachometer-alt', 'text' => 'Dashboard', 'permission' => null],
    ['url' => 'customers.php', 'icon' => 'fas fa-user-friends', 'text' => 'Customers', 'permission' => 'customers'],
    ['url' => 'trainers.php', 'icon' => 'fas fa-user-tie', 'text' => 'Trainers', 'permission' => 'trainers'],
    ['url' => 'attendance.php', 'icon' => 'fas fa-clipboard-check', 'text' => 'Attendance', 'permission' => 'attendance'],
    ['url' => 'equipment.php', 'icon' => 'fas fa-dumbbell', 'text' => 'Equipment', 'permission' => 'equipment'],
    ['url' => 'memberships.php', 'icon' => 'fas fa-id-card', 'text' => 'Memberships', 'permission' => 'memberships'],
    ['url' => 'payments.php', 'icon' => 'fas fa-credit-card', 'text' => 'Payments', 'permission' => 'payments'],
    ['url' => 'reports.php', 'icon' => 'fas fa-chart-line', 'text' => 'Reports', 'permission' => 'reports'],
    ['url' => 'settings.php', 'icon' => 'fas fa-cog', 'text' => 'Settings', 'permission' => 'settings'],
    ['url' => '../logout.php', 'icon' => 'fas fa-sign-out-alt', 'text' => 'Logout', 'permission' => null]
];

// Close database connection to free up resources
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Admin Dashboard - Gym Network</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #3f3d56;
            --primary-light: #5c5a7e;
            --secondary: #6c63ff;
            --success: #4caf50;
            --warning: #ffc107;
            --danger: #f44336;
            --light: #f8f9fa;
            --dark: #343a40;
            --white: #ffffff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-400: #ced4da;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-800: #343a40;
            --gray-900: #212529;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f0f2f5;
            color: var(--gray-800);
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 240px;
            background-color: var(--primary);
            color: var(--white);
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 100;
            transition: var(--transition);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-logo h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--white);
            margin-left: 10px;
        }

        .sidebar-logo i {
            font-size: 24px;
            color: var(--white);
        }

        .sidebar-menu {
            padding: 20px 0;
            flex: 1;
            overflow-y: auto;
        }

        .sidebar-menu ul {
            list-style: none;
        }

        .sidebar-menu li {
            margin-bottom: 5px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: var(--gray-300);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover {
            background-color: var(--primary-light);
            color: var(--white);
        }

        .sidebar-menu a.active {
            background-color: var(--primary-light);
            color: var(--white);
            border-left: 3px solid var(--secondary);
        }

        .sidebar-menu i {
            margin-right: 10px;
            font-size: 18px;
            width: 20px;
            text-align: center;
        }
        
        .menu-item-disabled {
            opacity: 0.5;
            pointer-events: none;
            cursor: not-allowed;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 240px;
            padding: 20px;
            transition: var(--transition);
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background-color: var(--white);
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .header-left {
            display: flex;
            align-items: center;
        }

        .header-left h1 {
            font-size: 20px;
            font-weight: 600;
            color: var(--gray-800);
            margin-right: 20px;
        }

        .search-bar {
            display: flex;
            align-items: center;
            background-color: var(--gray-100);
            border-radius: 20px;
            padding: 8px 15px;
            width: 300px;
        }

        .search-bar input {
            border: none;
            background: transparent;
            outline: none;
            width: 100%;
            padding: 0 10px;
            color: var(--gray-700);
        }

        .search-bar button {
            background: var(--secondary);
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            cursor: pointer;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .notification-icon, .message-icon {
            position: relative;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--gray-100);
            border-radius: 50%;
            cursor: pointer;
            transition: var(--transition);
        }

        .notification-icon:hover, .message-icon:hover {
            background-color: var(--gray-200);
        }

        .notification-badge, .message-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: var(--danger);
            color: var(--white);
            font-size: 10px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
        }

        .user-info {
            display: none;
        }

        @media (min-width: 768px) {
            .user-info {
                display: block;
            }
        }

        .user-name {
            font-weight: 600;
            font-size: 14px;
        }

        .user-role {
            font-size: 12px;
            color: var(--gray-600);
        }

        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-card {
            background-color: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .stat-card-title {
            font-size: 14px;
            color: var(--gray-600);
            font-weight: 500;
        }

        .stat-card-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
        }

        .stat-card-icon.purple {
            background-color: var(--secondary);
        }

        .stat-card-icon.blue {
            background-color: #3498db;
        }

        .stat-card-icon.green {
            background-color: var(--success);
        }

        .stat-card-icon.orange {
            background-color: var(--warning);
        }

        .stat-card-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-card-description {
            font-size: 12px;
            color: var(--gray-600);
        }

        /* Calendar Section */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .calendar-section {
            background-color: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .calendar-title {
            font-size: 16px;
            font-weight: 600;
        }

        .calendar-nav {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .calendar-nav-btn {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: var(--gray-100);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .calendar-nav-btn:hover {
            background-color: var(--gray-200);
        }

        .calendar-month {
            font-size: 14px;
            font-weight: 600;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }

        .calendar-weekday {
            text-align: center;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-600);
            padding: 5px;
        }

        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 14px;
            cursor: pointer;
            position: relative;
            transition: var(--transition);
            padding-bottom: 15px;
        }

        .calendar-day:hover {
            background-color: var(--gray-100);
        }

        .calendar-day.today {
            background-color: var(--secondary);
            color: var(--white);
            font-weight: 600;
        }

        .calendar-day.inactive {
            color: var(--gray-400);
        }

        .day-events {
            position: absolute;
            bottom: 3px;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            gap: 3px;
        }

        .day-event-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            display: inline-block;
        }

        .day-event-more {
            font-size: 8px;
            color: var(--gray-600);
        }

        /* Performance Chart */
        .performance-section {
            background-color: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
        }

        .performance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .performance-title {
            font-size: 16px;
            font-weight: 600;
        }

        .performance-legend {
            display: flex;
            gap: 15px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: var(--gray-600);
        }

        .legend-color {
            width: 10px;
            height: 10px;
            border-radius: 2px;
        }

        .legend-color.purple {
            background-color: var(--secondary);
        }

        .legend-color.green {
            background-color: var(--success);
        }

        .legend-color.yellow {
            background-color: var(--warning);
        }

        .chart-container {
            height: 250px;
            position: relative;
        }

        /* Top Trainers Section */
        .top-trainers-section {
            background-color: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
        }

        .top-trainers-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .top-trainers-title {
            font-size: 16px;
            font-weight: 600;
        }

        .top-trainers-year {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            color: var(--gray-600);
            background-color: var(--gray-100);
            padding: 5px 10px;
            border-radius: 15px;
        }

        .top-trainers-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .trainer-card {
            background-color: var(--gray-100);
            border-radius: var(--border-radius);
            padding: 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .trainer-card.first {
            background-color: rgba(108, 99, 255, 0.1);
        }

        .trainer-card.second {
            background-color: rgba(76, 175, 80, 0.1);
        }

        .trainer-card.third {
            background-color: rgba(255, 193, 7, 0.1);
        }

        .trainer-position {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .trainer-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--gray-300);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            overflow: hidden;
        }

        .trainer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .trainer-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .trainer-branch {
            font-size: 12px;
            color: var(--gray-600);
            margin-bottom: 10px;
        }

        .trainer-score {
            font-size: 24px;
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 5px;
        }

        .trainer-card.first .trainer-score {
            color: var(--secondary);
        }

        .trainer-card.second .trainer-score {
            color: var(--success);
        }

        .trainer-card.third .trainer-score {
            color: var(--warning);
        }

        .trainer-rank {
            font-size: 14px;
            font-weight: 600;
        }

        /* Recent Activity Section */
        .recent-activity-section {
            background-color: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
        }

        .recent-activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .recent-activity-title {
            font-size: 16px;
            font-weight: 600;
        }

        .view-all-link {
            font-size: 14px;
            color: var(--secondary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .activity-item {
            display: flex;
            gap: 15px;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background-color: rgba(108, 99, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--secondary);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .activity-time {
            font-size: 12px;
            color: var(--gray-600);
        }

        /* Today's Schedule Styles */
        .today-schedule {
            margin-top: 20px;
            border-top: 1px solid var(--gray-200);
            padding-top: 15px;
        }

        .today-schedule-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .today-schedule-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .schedule-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-radius: 8px;
            background-color: var(--gray-100);
        }

        .schedule-time {
            font-size: 12px;
            font-weight: 600;
            background-color: var(--white);
            padding: 5px 8px;
            border-radius: 4px;
            min-width: 100px;
            text-align: center;
            margin-right: 10px;
        }

        .schedule-content {
            flex: 1;
        }

        .schedule-title {
            font-size: 13px;
            font-weight: 500;
        }

        .schedule-trainer {
            font-size: 12px;
            color: var(--gray-600);
        }

        .schedule-status {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 500;
        }

        .status-scheduled {
            background-color: rgba(108, 99, 255, 0.1);
            color: var(--secondary);
        }

        .status-completed {
            background-color: rgba(76, 175, 80, 0.1);
            color: var(--success);
        }

        .status-cancelled {
            background-color: rgba(244, 67, 54, 0.1);
            color: var(--danger);
        }

        .no-schedule {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 15px;
            color: var(--gray-500);
            text-align: center;
        }

        .no-schedule i {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .no-schedule p {
            font-size: 13px;
        }

        .view-all-schedules {
            display: block;
            text-align: center;
            margin-top: 10px;
            font-size: 13px;
            color: var(--secondary);
            text-decoration: none;
            padding: 8px;
            border-radius: 4px;
            transition: var(--transition);
        }

        .view-all-schedules:hover {
            background-color: rgba(108, 99, 255, 0.05);
        }

        /* Permission denied message */
        .permission-denied {
            background-color: rgba(244, 67, 54, 0.1);
            color: var(--danger);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .permission-denied i {
            font-size: 24px;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .top-trainers-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }
            
            .sidebar-logo h2 {
                display: none;
            }
            
            .sidebar-menu a span {
                display: none;
            }
            
            .sidebar-menu i {
                margin-right: 0;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .search-bar {
                width: 200px;
            }
        }

        @media (max-width: 576px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .search-bar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-logo">
            <i class="fas fa-dumbbell"></i>
            <h2>Gym Network</h2>
        </div>
        <div class="sidebar-menu">
            <ul>
                <?php foreach ($menu_items as $item): ?>
                    <?php 
                    // Check if this menu item requires permission
                    $has_permission = true;
                    if ($item['permission'] !== null) {
                        $has_permission = $has_all_permissions || in_array($item['permission'], $user_permissions);
                    }
                    
                    // Determine if this is the active page
                    $is_active = basename($_SERVER['PHP_SELF']) === $item['url'];
                    
                    // Set classes based on permission and active state
                    $classes = [];
                    if ($is_active) $classes[] = 'active';
                    if (!$has_permission) $classes[] = 'menu-item-disabled';
                    
                    $class_attr = !empty($classes) ? ' class="' . implode(' ', $classes) . '"' : '';
                    ?>
                    <li>
                        <a href="<?php echo $has_permission ? $item['url'] : '#'; ?>"<?php echo $class_attr; ?> 
                           <?php if (!$has_permission): ?>title="You don't have permission to access this feature"<?php endif; ?>>
                            <i class="<?php echo $item['icon']; ?>"></i>
                            <span><?php echo $item['text']; ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>Welcome to <?php echo htmlspecialchars($admin['branch_name']); ?></h1>
                <div class="search-bar">
                    <input type="text" placeholder="Search...">
                    <button><i class="fas fa-search"></i></button>
                </div>
            </div>
            <div class="header-right">
                <div class="notification-icon">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge">3</span>
                </div>
                <div class="message-icon">
                    <i class="fas fa-envelope"></i>
                    <span class="message-badge">5</span>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php echo substr($admin['name'], 0, 1); ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($admin['name']); ?></div>
                        <div class="user-role">Branch Admin</div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="permission-denied">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Access Denied:</strong> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-title">Customers</div>
                    <div class="stat-card-icon purple">
                        <i class="fas fa-user-friends"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?php echo $stats['customer_count']; ?></div>
                <div class="stat-card-description">Total registered customers</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-title">Trainers</div>
                    <div class="stat-card-icon blue">
                        <i class="fas fa-user-tie"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?php echo $stats['trainer_count']; ?></div>
                <div class="stat-card-description">Active trainers</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-title">Memberships</div>
                    <div class="stat-card-icon green">
                        <i class="fas fa-id-card"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?php echo $stats['active_count']; ?></div>
                <div class="stat-card-description">Active memberships</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-title">Check-ins</div>
                    <div class="stat-card-icon orange">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?php echo $stats['today_attendance']; ?></div>
                <div class="stat-card-description">Today's attendance</div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Calendar Section -->
            <div class="calendar-section">
                <div class="calendar-header">
                    <div class="calendar-title">Trainer Schedule Calendar</div>
                    <div class="calendar-nav">
                        <button class="calendar-nav-btn"><i class="fas fa-chevron-left"></i></button>
                        <div class="calendar-month"><?php echo date('F Y'); ?></div>
                        <button class="calendar-nav-btn"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="calendar-grid">
                    <div class="calendar-weekday">Sun</div>
                    <div class="calendar-weekday">Mon</div>
                    <div class="calendar-weekday">Tue</div>
                    <div class="calendar-weekday">Wed</div>
                    <div class="calendar-weekday">Thu</div>
                    <div class="calendar-weekday">Fri</div>
                    <div class="calendar-weekday">Sat</div>
                    
                    <?php
                    // Add empty cells for days before the first day of the month
                    for ($i = 1; $i < $first_day_of_month; $i++) {
                        echo '<div class="calendar-day inactive"></div>';
                    }
                    
                    // Add cells for each day of the month
                    for ($day = 1; $day <= $days_in_month; $day++) {
                        $class = 'calendar-day';
                        if ($day == $current_day) {
                            $class .= ' today';
                        }
                        
                        // Check if there are trainer schedules for this day
                        $has_schedules = isset($schedule_data[$day]) && count($schedule_data[$day]) > 0;
                        
                        echo '<div class="' . $class . '">';
                        echo $day;
                        
                        // If this day has trainer schedules, display a dot for each trainer (up to 3)
                        if ($has_schedules) {
                            echo '<div class="day-events">';
                            $displayed = 0;
                            $trainers_shown = [];
                            
                            foreach ($schedule_data[$day] as $schedule) {
                                // Only show one dot per trainer to avoid duplicates
                                if (!in_array($schedule['trainer_id'], $trainers_shown) && $displayed < 3) {
                                    $status_color = 'var(--secondary)';
                                    if ($schedule['status'] == 'completed') {
                                        $status_color = 'var(--success)';
                                    } else if ($schedule['status'] == 'cancelled') {
                                        $status_color = 'var(--danger)';
                                    }
                                    
                                    echo '<span class="day-event-dot" style="background-color: ' . $status_color . ';" 
                                          title="' . htmlspecialchars($schedule['first_name'] . ' ' . $schedule['last_name']) . '"></span>';
                                    
                                    $trainers_shown[] = $schedule['trainer_id'];
                                    $displayed++;
                                }
                            }
                            
                            // If there are more trainers than we displayed, show a +X indicator
                            $remaining = count(array_unique(array_column($schedule_data[$day], 'trainer_id'))) - $displayed;
                            if ($remaining > 0) {
                                echo '<span class="day-event-more">+' . $remaining . '</span>';
                            }
                            
                            echo '</div>';
                        }
                        
                        echo '</div>';
                    }
                    
                    // Add empty cells for days after the last day of the month
                    $remaining_cells = 7 - (($first_day_of_month - 1 + $days_in_month) % 7);
                    if ($remaining_cells < 7) {
                        for ($i = 0; $i < $remaining_cells; $i++) {
                            echo '<div class="calendar-day inactive"></div>';
                        }
                    }
                    ?>
                </div>
                
                <!-- Today's Schedule List -->
                <div class="today-schedule">
                    <h3 class="today-schedule-title">Today's Sessions</h3>
                    <div class="today-schedule-list">
                        <?php
                        if (count($today_schedules) > 0) {
                            // Limit to 3 schedules for better performance
                            $display_count = min(count($today_schedules), 3);
                            for ($i = 0; $i < $display_count; $i++) {
                                $schedule = $today_schedules[$i];
                                $start_time = date('g:i A', strtotime($schedule['start_time']));
                                $end_time = date('g:i A', strtotime($schedule['end_time']));
                                $status_class = 'status-' . $schedule['status'];
                                $status_text = ucfirst($schedule['status']);
                        ?>
                        <div class="schedule-item">
                            <div class="schedule-time">
                                <?php echo $start_time; ?> - <?php echo $end_time; ?>
                            </div>
                            <div class="schedule-content">
                                <div class="schedule-title">
                                    <?php echo htmlspecialchars($schedule['title']); ?>
                                    <?php if (!empty($schedule['customer_first_name'])): ?>
                                    with <?php echo htmlspecialchars($schedule['customer_first_name'] . ' ' . $schedule['customer_last_name']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="schedule-trainer">
                                    by <?php echo htmlspecialchars($schedule['first_name'] . ' ' . $schedule['last_name']); ?>
                                </div>
                            </div>
                            <div class="schedule-status <?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </div>
                        </div>
                        <?php
                            }
                        } else {
                        ?>
                        <div class="no-schedule">
                            <i class="fas fa-calendar-times"></i>
                            <p>No training sessions scheduled for today</p>
                        </div>
                        <?php
                        }
                        ?>
                    </div>
                    <?php if (hasPermission('trainers')): ?>
                    <a href="trainers.php" class="view-all-schedules">View All Schedules</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Performance Chart -->
            <div class="performance-section">
                <div class="performance-header">
                    <div class="performance-title">Branch Performance</div>
                    <div class="performance-legend">
                        <div class="legend-item">
                            <div class="legend-color purple"></div>
                            <span>Customers</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color green"></div>
                            <span>Sessions</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color yellow"></div>
                            <span>Revenue</span>
                        </div>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="performanceChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Top Trainers Section -->
        <?php if (hasPermission('trainers')): ?>
        <div class="top-trainers-section">
            <div class="top-trainers-header">
                <div class="top-trainers-title">Top Trainers</div>
                <div class="top-trainers-year">
                    <span><?php echo date('Y'); ?></span>
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            <div class="top-trainers-grid">
                <?php
                $positions = ['1st', '2nd', '3rd'];
                $classes = ['first', 'second', 'third'];
                $i = 0;
                
                if ($top_trainers->num_rows > 0) {
                    while ($trainer = $top_trainers->fetch_assoc()) {
                        $percentage = number_format($trainer['percentage'], 1);
                ?>
                <div class="trainer-card <?php echo $classes[$i]; ?>">
                    <div class="trainer-position"><?php echo $positions[$i]; ?></div>
                    <div class="trainer-avatar">
                        <?php if (!empty($trainer['profile_photo']) && file_exists('../../' . $trainer['profile_photo'])): ?>
                            <img src="../../<?php echo htmlspecialchars($trainer['profile_photo']); ?>" alt="<?php echo htmlspecialchars($trainer['first_name']); ?>">
                        <?php else: ?>
                            <i class="fas fa-user-tie"></i>
                        <?php endif; ?>
                    </div>
                    <div class="trainer-name"><?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?></div>
                    <div class="trainer-branch"><?php echo htmlspecialchars($branch_name); ?> Branch</div>
                    <div class="trainer-score"><?php echo $percentage; ?>%</div>
                    <div class="trainer-rank"><?php echo $trainer['session_count']; ?> Sessions</div>
                </div>
                <?php
                        $i++;
                    }
                }
                
                // Fill in empty slots if less than 3 trainers
                while ($i < 3) {
                ?>
                <div class="trainer-card <?php echo $classes[$i]; ?>">
                    <div class="trainer-position"><?php echo $positions[$i]; ?></div>
                    <div class="trainer-avatar">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="trainer-name">No Trainer</div>
                    <div class="trainer-branch"><?php echo htmlspecialchars($branch_name); ?> Branch</div>
                    <div class="trainer-score">0.0%</div>
                    <div class="trainer-rank">0 Sessions</div>
                </div>
                <?php
                    $i++;
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Activity Section -->
        <div class="recent-activity-section">
            <div class="recent-activity-header">
                <div class="recent-activity-title">Recent Activity</div>
                <a href="#" class="view-all-link">
                    View All
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="activity-list">
                <?php
                if ($activities->num_rows > 0) {
                    while ($activity = $activities->fetch_assoc()) {
                        $icon = '';
                        $title = '';
                        
                        switch ($activity['type']) {
                            case 'new_customer':
                                $icon = 'fas fa-user-plus';
                                $title = 'New customer registered: ' . htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']);
                                break;
                            case 'check_in':
                                $icon = 'fas fa-clipboard-check';
                                $title = 'Customer checked in: ' . htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']);
                                break;
                            case 'new_trainer':
                                $icon = 'fas fa-user-tie';
                                $title = 'New trainer added: ' . htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']);
                                break;
                        }
                ?>
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="<?php echo $icon; ?>"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title"><?php echo $title; ?></div>
                        <div class="activity-time"><?php echo date('F j, Y, g:i a', strtotime($activity['timestamp'])); ?></div>
                    </div>
                </div>
                <?php
                    }
                } else {
                ?>
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title">No recent activity</div>
                        <div class="activity-time">Start adding customers to see activity here</div>
                    </div>
                </div>
                <?php
                }
                ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Performance Chart - Defer initialization for better page load performance
            setTimeout(function() {
                const performanceCtx = document.getElementById('performanceChart').getContext('2d');
                const performanceChart = new Chart(performanceCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                        datasets: [
                            {
                                label: 'Customers',
                                data: [65, 78, 90, 85, 92, 98],
                                backgroundColor: '#6c63ff',
                                borderRadius: 5,
                                barPercentage: 0.6,
                                categoryPercentage: 0.7
                            },
                            {
                                label: 'Sessions',
                                data: [45, 55, 65, 70, 75, 80],
                                backgroundColor: '#4caf50',
                                borderRadius: 5,
                                barPercentage: 0.6,
                                categoryPercentage: 0.7
                            },
                            {
                                label: 'Revenue',
                                data: [30, 40, 45, 50, 55, 60],
                                backgroundColor: '#ffc107',
                                borderRadius: 5,
                                barPercentage: 0.6,
                                categoryPercentage: 0.7
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                }
                            },
                            y: {
                                beginAtZero: true,
                                grid: {
                                    borderDash: [5, 5]
                                },
                                ticks: {
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                }
                            }
                        }
                    }
                });
            }, 100);
            
            // Calendar navigation
            const calendarNavBtns = document.querySelectorAll('.calendar-nav-btn');
            calendarNavBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    // This would be implemented to navigate between months
                    // For now, just show an alert
                    alert('Calendar navigation will be implemented in the next update');
                });
            });
        });
    </script>
</body>
</html>

