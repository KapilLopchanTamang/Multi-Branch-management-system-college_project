<?php



// Start session
session_start();

// Include database connection
require_once '../includes/db_connect.php';

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get email and sanitize
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    
    // Check if email exists
    $stmt = $conn->prepare("SELECT id, first_name FROM customers WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token in database
        $stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $email, $token, $expires);
        $stmt->execute();
        
        // Send email with reset link (in a real application)
        // mail($email, "Reset Your Password", "Click the link to reset your password: http://yourdomain.com/customer/reset-password.php?token=$token");
        
        // For demo purposes, just show success message
        $_SESSION['reset_message'] = "Password reset link has been sent to your email.";
        header("Location: login.php");
        exit();
    } else {
        $error = "Email not found";
    }
    
    $stmt->close();
    
    // If there's an error, redirect back with error message
    if (isset($error)) {
        $_SESSION['reset_error'] = $error;
    }
}

// Get base path for assets
$script_name = $_SERVER['SCRIPT_NAME'];
$script_path = dirname($script_name);
$customer_pos = strpos($script_path, '/customer');
$base_path = $customer_pos !== false ? substr($script_path, 0, $customer_pos) : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Gym Customer Portal</title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/assets/css/styles.css">
</head>
<body>
    <div class="container">
        <div class="left-panel">
            <div class="content">
                <h1>Reset your<br>password</h1>
                <p>We'll send you a link to reset your password and get you back to your fitness journey.</p>
            </div>
            <div class="illustration">
                <img src="<?php echo $base_path; ?>/assets/images/gym-customer-illustration.png" alt="Fitness Illustration">
            </div>
        </div>
        <div class="right-panel">
            <div class="form-container">
                <h2>Forgot Password</h2>
                <p class="subtitle">Enter your email to receive a password reset link</p>
                
                <?php if (isset($_SESSION['reset_error'])): ?>
                    <div class="error-message">
                        <?php echo $_SESSION['reset_error']; unset($_SESSION['reset_error']); ?>
                    </div>
                <?php endif; ?>
                
                <form action="forgot-password.php" method="post">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <button type="submit" class="sign-in-btn">Send Reset Link</button>
                </form>
                
                <p class="register-link">Remember your password? <a href="login.php">Back to login</a></p>
            </div>
        </div>
    </div>
</body>
</html>

