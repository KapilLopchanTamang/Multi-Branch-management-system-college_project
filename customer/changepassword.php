<?php
// Start session and include page transition effect
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
$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();
$customer = $result->fetch_assoc();

// Handle password change
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = "Please fill in all password fields.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New passwords do not match.";
    } elseif (strlen($new_password) < 8) {
        $error_message = "New password must be at least 8 characters long.";
    } else {
        // Verify current password
        if (password_verify($current_password, $customer['password'])) {
            // Hash new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password
            $stmt = $conn->prepare("UPDATE customers SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $customer_id);
            
            if ($stmt->execute()) {
                $success_message = "Password changed successfully!";
            } else {
                $error_message = "Error changing password: " . $conn->error;
            }
        } else {
            $error_message = "Current password is incorrect.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | Gym Network</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        #head {
            background-image: repeating-radial-gradient(circle at 0 0, transparent 0, #ff6b45 100px), repeating-linear-gradient(#e74c3c, #ff6b45);
            background-color: #ff6b45;
        }
        .text-primary { color: #e74c3c; font-weight: bold; }
        .bg-primary { background-color: #FF6B45; }
        .bg-light { background-color: #f9f9f9; }
        
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
        <!-- Dashboard Content -->
        <main class="p-4 lg:p-6">
            <!-- Page Header -->
            <div id="head" class="rounded-3xl shadow-sm overflow-hidden mb-6 relative">
                <div class="p-6">
                    <h2 class="text-2xl font-bold text-white mb-2">
                        Change Password
                    </h2>
                    <p class="text-white opacity-90">
                        Update your account password
                    </p>
                </div>
            </div>
            
            <?php if (!empty($success_message)): ?>
                <div class="mb-4 p-4 bg-green-100 border border-green-200 text-green-700 rounded-lg">
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="mb-4 p-4 bg-red-100 border border-red-200 text-red-700 rounded-lg">
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>
            
            <!-- Change Password Form -->
            <div class="max-w-2xl mx-auto">
                <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Change Your Password</h3>
                    </div>
                    
                    <div class="p-6">
                        <form action="" method="POST">
                            <div class="space-y-4">
                                <div>
                                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password *</label>
                                    <input type="password" id="current_password" name="current_password" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                                </div>
                                
                                <div>
                                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password *</label>
                                    <input type="password" id="new_password" name="new_password" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                                    <p class="mt-1 text-sm text-gray-500">Password must be at least 8 characters long.</p>
                                </div>
                                
                                <div>
                                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password *</label>
                                    <input type="password" id="confirm_password" name="confirm_password" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                                </div>
                            </div>
                            
                            <div class="mt-6 flex justify-between">
                                <a href="profile.php" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 transition-colors">
                                    Back to Profile
                                </a>
                                <button type="submit" name="change_password" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-opacity-90 transition-colors">
                                    Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="mt-6 bg-blue-50 rounded-lg p-4 text-blue-700 text-sm">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-blue-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="font-medium">Password Security Tips</h3>
                            <ul class="mt-2 list-disc list-inside">
                                <li>Use a combination of letters, numbers, and special characters</li>
                                <li>Avoid using personal information in your password</li>
                                <li>Don't reuse passwords across different websites</li>
                                <li>Consider using a password manager for better security</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
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
    </script>
</body>
</html>