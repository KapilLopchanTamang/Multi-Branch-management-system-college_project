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
    
    // Get email and sanitize
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    
    // Check if email exists
    $stmt = $conn->prepare("SELECT id, name FROM admins WHERE email = ?");
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
        // mail($email, "Reset Your Password", "Click the link to reset your password: http://yourdomain.com/reset-password.php?token=$token");
        
        // For demo purposes, just show success message
        $_SESSION['reset_message'] = "Password reset link has been sent to your email.";
        header("Location: index.php");
        exit();
    } else {
        $error = "Email not found";
    }
    
    $stmt->close();
    $conn->close();
    
    // If there's an error, redirect back with error message
    if (isset($error)) {
        $_SESSION['reset_error'] = $error;
        header("Location: forgot-password.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Gym Admin Portal</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <div class="left-panel">
            <div class="content">
                <h1>Reset your<br>password</h1>
                <p>We'll send you a link to reset your password and get you back to managing your gym branches.</p>
            </div>
            <div class="illustration">
                <img src="gym-illustration.png" alt="Gym Management Illustration">
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
                
                <p class="register-link">Remember your password? <a href="index.php">Back to login</a></p>
            </div>
        </div>
    </div>
</body>
</html>

