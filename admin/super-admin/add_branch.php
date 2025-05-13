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
    $branch_name = filter_var($_POST['branch_name'], FILTER_SANITIZE_STRING);
    $branch_location = filter_var($_POST['branch_location'], FILTER_SANITIZE_STRING);
    $admin_id = isset($_POST['branch_admin']) && !empty($_POST['branch_admin']) ? intval($_POST['branch_admin']) : null;
    
    // Validate data
    $errors = [];
    
    if (empty($branch_name)) {
        $errors[] = "Branch name is required";
    }
    
    if (empty($branch_location)) {
        $errors[] = "Branch location is required";
    }
    
    // Check if branch name already exists
    $stmt = $conn->prepare("SELECT id FROM branches WHERE name = ?");
    $stmt->bind_param("s", $branch_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $errors[] = "Branch name already exists";
    }
    
    // If admin is selected, check if admin exists and is a branch admin
    if (!empty($admin_id)) {
        $stmt = $conn->prepare("SELECT id, role FROM admins WHERE id = ?");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) {
            $errors[] = "Selected admin does not exist";
        } else {
            $admin = $result->fetch_assoc();
            if ($admin['role'] !== 'branch_admin') {
                $errors[] = "Selected user is not a branch admin";
            }
            
            // Check if admin is already assigned to a branch
            $stmt = $conn->prepare("SELECT id FROM branches WHERE admin_id = ?");
            $stmt->bind_param("i", $admin_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $errors[] = "Selected admin is already assigned to a branch";
            }
        }
    }
    
    // If no errors, proceed with adding the branch
    if (empty($errors)) {
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Insert branch data
            $stmt = $conn->prepare("INSERT INTO branches (name, location, admin_id) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $branch_name, $branch_location, $admin_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Set success message
            $_SESSION['branch_added'] = "Branch added successfully!";
            
            // Redirect to dashboard
            header("Location: dashboard.php");
            exit();
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $errors[] = "Error adding branch: " . $e->getMessage();
        }
    }
    
    // Store errors in session
    if (!empty($errors)) {
        $_SESSION['branch_errors'] = $errors;
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

