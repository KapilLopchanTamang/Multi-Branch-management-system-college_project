<?php
// Start session
session_start();

// Include database connection
require_once '../../includes/db_connect.php';

// Check if user is logged in and is a super admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'super_admin') {
    // Redirect to login page if not logged in or not a super admin
    header("Location: ../login.php");
    exit();
}

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data and sanitize
    $name = filter_var($_POST['admin_name'], FILTER_SANITIZE_STRING);
    $email = filter_var($_POST['admin_email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['admin_password'];
    $branch_id = isset($_POST['admin_branch']) ? intval($_POST['admin_branch']) : null;
    
    // Validate data
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Name is required";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email is required";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters";
    }
    
    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $errors[] = "Email already registered";
    }
    
    // If no errors, proceed with registration
    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Set role to branch_admin
        $role = 'branch_admin';
        
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Insert admin data
            $stmt = $conn->prepare("INSERT INTO admins (name, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $name, $email, $hashed_password, $role);
            $stmt->execute();
            
            // Get the new admin ID
            $admin_id = $conn->insert_id;
            
            // If branch is selected, assign admin to branch
            if (!empty($branch_id)) {
                $stmt = $conn->prepare("UPDATE branches SET admin_id = ? WHERE id = ?");
                $stmt->bind_param("ii", $admin_id, $branch_id);
                $stmt->execute();
            }
            
            // Commit transaction
            $conn->commit();
            
            // Set success message
            $_SESSION['admin_added'] = "Branch admin added successfully!";
            
            // Redirect to dashboard
            header("Location: dashboard.php");
            exit();
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $errors[] = "Error adding admin: " . $e->getMessage();
        }
    }
    
    // Store errors in session
    if (!empty($errors)) {
        $_SESSION['admin_errors'] = $errors;
        // Redirect back to dashboard
        header("Location: dashboard.php");
        exit();
    }
}
else {
    // If not POST request, redirect to dashboard
    header("Location: dashboard.php");
    exit();
}
?>

