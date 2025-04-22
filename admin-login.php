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
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym Admin Portal</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="left-panel">
            <div class="content">
                <h1>Management is<br>at your fingertips</h1>
                <p>Take control of your gym branches with our powerful admin platform for the largest network of fitness centers.</p>
            </div>
            <div class="illustration">
                <img src="gym-illustration.png" alt="Gym Management Illustration">
            </div>
        </div>
        <div class="right-panel">
            <div class="form-container">
                <h2>Gym Admin Portal</h2>
                <p class="subtitle">Sign in to manage your branches</p>
                
                <?php if (isset($_SESSION['login_error'])): ?>
                    <div class="error-message">
                        <?php echo $_SESSION['login_error']; unset($_SESSION['login_error']); ?>
                    </div>
                <?php endif; ?>
                
                <div class="branch-selector">
                    <label class="radio-container">
                        <input type="radio" name="branchType" value="single" checked>
                        <span class="radio-text">I manage a single branch</span>
                    </label>
                    <label class="radio-container">
                        <input type="radio" name="branchType" value="multiple">
                        <span class="radio-text">I manage multiple branches</span>
                    </label>
                </div>
                
                <form action="admin-login.php" method="post">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-container">
                            <input type="password" id="password" name="password" required>
                            <span class="toggle-password" onclick="togglePassword()">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                    </div>
                    
                    <div class="branch-select form-group" id="branchSelect" style="display: none;">
                        <label for="branch">Select Branch</label>
                        <select id="branch" name="branch">
                            <option value="">Select your branch</option>
                            <option value="downtown">Downtown Fitness</option>
                            <option value="uptown">Uptown Gym</option>
                            <option value="westside">Westside Health Club</option>
                            <option value="eastend">East End Fitness Center</option>
                        </select>
                    </div>
                    
                    <div class="form-group remember-me">
                        <label class="checkbox-container">
                            <input type="checkbox" name="remember">
                            <span class="checkmark"></span>
                            <span class="checkbox-text">Remember me</span>
                        </label>
                        <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
                    </div>
                    
                    <div class="form-group terms">
                        <p>By proceeding, I agree to Gym's <a href="terms.php">Terms of Use</a> and acknowledge that I have read the <a href="privacy.php">Privacy Policy</a>.</p>
                    </div>
                    
                    <button type="submit" class="sign-in-btn">Sign in</button>
                </form>
                
                <p class="register-link">Back to <a href="index.php">Main Portal</a></p>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>

