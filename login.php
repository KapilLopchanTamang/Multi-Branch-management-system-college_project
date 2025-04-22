<?php
// Start session
session_start();

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Database connection details
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "gym_admin";
    
    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Get form data and sanitize
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $branch = isset($_POST['branch']) ? $_POST['branch'] : '';
    $remember = isset($_POST['remember']) ? 1 : 0;
    
    // Prepare SQL statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT id, name, email, password, role FROM admins WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Verify password
        if (password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            
            // If branch is selected, store it in session
            if (!empty($branch)) {
                $_SESSION['selected_branch'] = $branch;
            }
            
            // If remember me is checked, set cookies
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                
                // Store token in database
                $stmt = $conn->prepare("UPDATE admins SET remember_token = ? WHERE id = ?");
                $stmt->bind_param("si", $token, $user['id']);
                $stmt->execute();
                
                // Set cookies for 30 days
                setcookie("remember_user", $user['id'], time() + (86400 * 30), "/");
                setcookie("remember_token", $token, time() + (86400 * 30), "/");
            }
            
            // Redirect to dashboard
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid password";
        }
    } else {
        $error = "Email not found";
    }
    
    $stmt->close();
    $conn->close();
    
    // If there's an error, redirect back with error message
    if (isset($error)) {
        $_SESSION['login_error'] = $error;
        header("Location: index.php");
        exit();
    }
}
?>

