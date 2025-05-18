<?php
// Start session
include 'includes/pageeffect.php';

// Include database connection
require_once '../includes/db_connect.php';

// Check if user is logged in as a customer
if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit();
}

// Get customer data
$customer_id = $_SESSION['customer_id'];
$stmt = $conn->prepare("SELECT c.*, c.profile_image, m.membership_type, m.status as membership_status, m.end_date, b.name as branch_name, b.location 
                        FROM customers c 
                        LEFT JOIN memberships m ON c.id = m.customer_id 
                        LEFT JOIN branches b ON c.branch = b.name 
                        WHERE c.id = ? 
                        ORDER BY m.end_date DESC LIMIT 1");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();
$customer = $result->fetch_assoc();

// Calculate days remaining until membership expiry
$days_remaining = 0;
$is_expired = false;
if (!empty($customer['end_date'])) {
    $today = new DateTime();
    $expiry_date = new DateTime($customer['end_date']);
    $interval = $today->diff($expiry_date);
    $days_remaining = $interval->days;
    $is_expired = $today > $expiry_date;
}

// Get upcoming classes for this branch
$stmt = $conn->prepare("SELECT * FROM classes WHERE branch = ? AND class_date >= CURDATE() ORDER BY class_date, start_time LIMIT 5");
$stmt->bind_param("s", $customer['branch_name']);
$stmt->execute();
$upcoming_classes = $stmt->get_result();

// Get attendance data for chart
$stmt = $conn->prepare("SELECT DATE(check_in) as date, COUNT(*) as count FROM attendance 
                        WHERE customer_id = ? AND check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                        GROUP BY DATE(check_in)");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$attendance_data = $stmt->get_result();

// Format attendance data for chart
$attendance_dates = [];
$attendance_counts = [];
while ($row = $attendance_data->fetch_assoc()) {
    $attendance_dates[] = date('M d', strtotime($row['date']));
    $attendance_counts[] = $row['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Gym Network</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        #head {
            background-image: repeating-radial-gradient(circle at 0 0, transparent 0, #ff6b45 100px), repeating-linear-gradient(#e74c3c, #ff6b45);
            background-color: #ff6b45;
        }
        /* Gradient background */
        .bg-gradient-pattern {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
        }
        .text-primary { color: #e74c3c; font-weight: bold; }
        .bg-primary { background-color: #FF6B45; }
        .bg-light { background-color: #f9f9f9; }
        
        /* Days remaining badge */
        .days-badge {
            background-color: white;
            border-radius: 8px;
            padding: 2px 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            position: absolute;
            top: 3px;
            right: 10px;
            font-size: 12px;
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #ddd; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #ccc; }
        
        /* Transition for sidebar */
        #content-wrapper { transition: margin-left 0.3s ease; }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Include sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div id="content-wrapper" class="ml-0 lg:ml-64 min-h-screen transition-all duration-300">
        <!-- Top Navigation -->
      
        <!-- Dashboard Content -->
        <main class="p-4 lg:p-6">
            <!-- Welcome Banner -->
            <div id="head" class="rounded-3xl shadow-sm overflow-hidden mb-6 relative">
                <!-- Days remaining badge -->
                <?php if (!$is_expired && $days_remaining > 0): ?>
                <div class="p-1  mb-8 days-badge">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-primary">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                    </svg>
                    <span class="font-medium text-orange-700"><?php echo $days_remaining; ?> days left</span>
                </div>
                <?php elseif ($is_expired): ?>
                <div class="days-badge bg-red-50">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <span class="font-medium text-red-600">Expired</span>
                </div>
                <?php endif; ?>
                
                <div class="p-6 flex flex-col md:flex-row items-center">
                    <div class="md:w-2/3 mb-4 md:mb-0 md:pr-6">
                        <h2 class="text-2xl font-bold text-white mb-2">
                            <p class="text-white mb-4 uppercase">
                                <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                            </p>
                        </h2>
                        
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-white bg-opacity-20 text-white">
                                <?php echo ucfirst(str_replace('_', ' ', $customer['fitness_goal'] ?? 'General Fitness')); ?>
                            </span>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-white bg-opacity-20 text-white">
                                <?php echo ucfirst($customer['subscription_type'] ?? 'Monthly'); ?> Plan
                            </span>
                        </div>
                    </div>
                    <div class="md:w-1/3 flex justify-center">
                        <?php if (!empty($customer['profile_image'])): ?>
                            <img class="h-40 w-40 object-cover rounded-lg" src="<?php echo '../' . htmlspecialchars($customer['profile_image']); ?>" alt="Profile">
                        <?php else: ?>
                            <div class="h-40 w-40 rounded-lg bg-white bg-opacity-20 flex items-center justify-center text-white text-6xl">
                                <?php echo strtoupper(substr($customer['first_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <!-- Membership Status -->
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-gray-100 mr-4">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6" style="color: #FF6B45;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Membership Status</p>
                            <p class="text-lg font-semibold text-gray-800">
                                <?php echo ucfirst($customer['membership_status'] ?? 'Active'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Membership Type -->
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-gray-100 mr-4">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6" style="color: #FF6B45;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Membership Type</p>
                            <p class="text-lg font-semibold text-gray-800">
                                <?php echo ucfirst($customer['membership_type'] ?? 'Premium'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Expiry Date -->
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-gray-100 mr-4">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6" style="color: #FF6B45;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Expiry Date</p>
                            <p class="text-lg font-semibold text-gray-800">
                                <?php echo date('M d, Y', strtotime($customer['end_date'] ?? '2025-12-31')); ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Workouts -->
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-gray-100 mr-4">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6" style="color: #FF6B45;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">This Month</p>
                            <p class="text-lg font-semibold text-gray-800">
                                <?php echo count($attendance_counts) ?: 0; ?> Workouts
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
          
        
            <!-- Recent Activity -->
            <div class="bg-white rounded-lg shadow-sm p-6 mt-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Recent Activity</h3>
                    <a href="activity.php" class="text-sm text-primary hover:underline">View All</a>
                </div>
                
                <?php if (count($attendance_dates) > 0): ?>
                <div class="space-y-3">
                    <?php for ($i = 0; $i < min(3, count($attendance_dates)); $i++): ?>
                    <div class="flex items-center p-3 bg-light rounded-lg">
                        <div class="p-3 rounded-full bg-gray-100 mr-4">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" style="color: #FF6B45;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h4 class="font-medium text-gray-800">Workout Session</h4>
                            <p class="text-sm text-gray-500"><?php echo $attendance_dates[$i]; ?></p>
                        </div>
                        <span class="text-sm text-gray-500"><?php echo $attendance_counts[$i]; ?> check-ins</span>
                    </div>
                    <?php endfor; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-8">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-12 h-12 mx-auto text-gray-300 mb-3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                    </svg>
                    <p class="text-gray-500">No recent activity found</p>
                    <a href="workouts.php" class="mt-3 inline-block px-4 py-2 bg-primary text-white rounded-md hover:bg-opacity-90 transition-colors">
                        Start a Workout
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle sidebar on mobile
            const sidebarToggle = document.getElementById('sidebar-toggle');
            const sidebar = document.getElementById('sidebar');
            const contentWrapper = document.getElementById('content-wrapper');
            
            if (sidebarToggle && sidebar && contentWrapper) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('translate-x-0');
                    sidebar.classList.toggle('-translate-x-full');
                    
                    if (window.innerWidth >= 1024) {
                        contentWrapper.classList.toggle('ml-0');
                        contentWrapper.classList.toggle('ml-64');
                    }
                });
            }
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth < 1024 && 
                    sidebar && 
                    !sidebar.contains(event.target) && 
                    sidebarToggle && 
                    !sidebarToggle.contains(event.target) &&
                    sidebar.classList.contains('translate-x-0')) {
                    sidebar.classList.remove('translate-x-0');
                    sidebar.classList.add('-translate-x-full');
                }
            });
        });
    </script>
</body>
</html>