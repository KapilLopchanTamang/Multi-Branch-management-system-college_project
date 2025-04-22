<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    // Redirect to login page if not logged in
    header("Location: login.php");
    exit();
}

// Redirect based on user role
if ($_SESSION['user_role'] === 'super_admin') {
    header("Location: super-admin/dashboard.php");
    exit();
} else {
    header("Location: branch-admin/dashboard.php");
    exit();
}
?>

