<?php
// Start session
session_start();

// Clear all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Clear cookies if they exist
if (isset($_COOKIE['customer_remember'])) {
    setcookie('customer_remember', '', time() - 3600, '/');
}
if (isset($_COOKIE['customer_token'])) {
    setcookie('customer_token', '', time() - 3600, '/');
}

// Get base path
$script_name = $_SERVER['SCRIPT_NAME'];
$script_path = dirname($script_name);
$customer_pos = strpos($script_path, '/customer');
$base_path = $customer_pos !== false ? substr($script_path, 0, $customer_pos) : '';

// Redirect to login page
header("Location: {$base_path}/customer/login.php");
exit();
?>

