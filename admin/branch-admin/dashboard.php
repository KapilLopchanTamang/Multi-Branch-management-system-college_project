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

// Get customer count for this branch
$branch_name = $admin['branch_name'];
$stmt = $conn->prepare("SELECT COUNT(*) as customer_count FROM customers WHERE branch = ?");
$stmt->bind_param("s", $branch_name);
$stmt->execute();
$result = $stmt->get_result();
$customer_data = $result->fetch_assoc();
$customer_count = $customer_data['customer_count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Admin Dashboard - Gym Network</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
                    <li class="active"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="#"><i class="fas fa-user-friends"></i> Customers</a></li>
                    <li><a href="#"><i class="fas fa-calendar-alt"></i> Classes</a></li>
                    <li><a href="#"><i class="fas fa-dumbbell"></i> Equipment</a></li>
                    <li><a href="#"><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="page-title">
                    <h1>Branch Admin Dashboard</h1>
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
                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <div class="welcome-content">
                        <h2>Welcome to <?php echo htmlspecialchars($admin['branch_name']); ?> Dashboard</h2>
                        <p>You are managing the <?php echo htmlspecialchars($admin['branch_name']); ?> located at <?php echo htmlspecialchars($admin['location']); ?>.</p>
                        <p>Here you can manage your branch customers, classes, equipment, and more.</p>
                        <button class="action-button">View Branch Details</button>
                    </div>
                    <div class="welcome-image">
                        <img src="../../assets/images/branch-admin-welcome.png" alt="Welcome">
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-cards">
                    <div class="card">
                        <div class="card-icon">
                            <i class="fas fa-user-friends"></i>
                        </div>
                        <div class="card-info">
                            <h3><?php echo $customer_count; ?></h3>
                            <p>Total Customers</p>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="card-info">
                            <h3>0</h3>
                            <p>Active Memberships</p>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="card-info">
                            <h3>0</h3>
                            <p>Classes Today</p>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="card-info">
                            <h3>0</h3>
                            <p>New Registrations</p>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="action-buttons">
                        <button class="action-btn">
                            <i class="fas fa-user-plus"></i>
                            <span>Add Customer</span>
                        </button>
                        <button class="action-btn">
                            <i class="fas fa-calendar-plus"></i>
                            <span>Schedule Class</span>
                        </button>
                        <button class="action-btn">
                            <i class="fas fa-clipboard-list"></i>
                            <span>View Reports</span>
                        </button>
                        <button class="action-btn">
                            <i class="fas fa-cog"></i>
                            <span>Branch Settings</span>
                        </button>
                    </div>
                </div>
                
                <!-- Recent Activity Section -->
                <div class="section">
                    <div class="section-header">
                        <h2>Recent Activity</h2>
                    </div>
                    
                    <div class="activity-list">
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div class="activity-details">
                                <p class="activity-text">New customer registration</p>
                                <p class="activity-time">Today, 10:30 AM</p>
                            </div>
                        </div>
                        
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="activity-details">
                                <p class="activity-text">Membership renewal</p>
                                <p class="activity-time">Yesterday, 3:45 PM</p>
                            </div>
                        </div>
                        
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="activity-details">
                                <p class="activity-text">New class scheduled</p>
                                <p class="activity-time">Yesterday, 1:15 PM</p>
                            </div>
                        </div>
                        
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-dumbbell"></i>
                            </div>
                            <div class="activity-details">
                                <p class="activity-text">Equipment maintenance completed</p>
                                <p class="activity-time">2 days ago</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

