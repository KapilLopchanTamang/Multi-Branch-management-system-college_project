<?php
// Start session
include 'includes/pageeffect.php';

// Include database connection
require_once '../includes/db_connect.php';

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Get form data and sanitize
  $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
  $password = $_POST['password'];
  $branch = isset($_POST['branch']) ? $_POST['branch'] : '';
  $remember = isset($_POST['remember']) ? 1 : 0;
  
  // Validate inputs
  $errors = [];
  
  if (empty($email)) {
    $errors[] = "Email is required";
  }
  
  if (empty($password)) {
    $errors[] = "Password is required";
  }
  
  // If no errors, proceed with login
  if (empty($errors)) {
    // Prepare SQL statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, password, branch FROM customers WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
      $user = $result->fetch_assoc();
      
      // Verify password
      if (password_verify($password, $user['password'])) {
        // If branch is specified, check if it matches
        if (!empty($branch) && $branch !== $user['branch']) {
          $errors[] = "You are not registered at this branch. Please select the correct branch.";
        } else {
          // Set session variables
          $_SESSION['customer_id'] = $user['id'];
          $_SESSION['customer_name'] = $user['first_name'] . ' ' . $user['last_name'];
          $_SESSION['customer_email'] = $user['email'];
          $_SESSION['customer_branch'] = $user['branch'];
          
          // If remember me is checked, set cookies
          if ($remember) {
              $token = bin2hex(random_bytes(32));
              
              // Store token in database
              $stmt = $conn->prepare("UPDATE customers SET remember_token = ? WHERE id = ?");
              $stmt->bind_param("si", $token, $user['id']);
              $stmt->execute();
              
              // Set cookies for 30 days
              setcookie("customer_remember", $user['id'], time() + (86400 * 30), "/");
              setcookie("customer_token", $token, time() + (86400 * 30), "/");
          }
          
          // Get base path
          $script_name = $_SERVER['SCRIPT_NAME'];
          $script_path = dirname($script_name);
          $customer_pos = strpos($script_path, '/customer');
          $base_path = $customer_pos !== false ? substr($script_path, 0, $customer_pos) : '';
          $_SESSION['base_path'] = $base_path;
          
          // Redirect to customer dashboard
          header("Location: dashboard.php");
          exit();
        }
      } else {
        $errors[] = "Invalid password";
      }
    } else {
      $errors[] = "Email not found";
    }
    
    $stmt->close();
  }
  
  // If there are errors, store them in session
  if (!empty($errors)) {
    $_SESSION['login_errors'] = $errors;
  }
}

// Get base path for assets
$script_name = $_SERVER['SCRIPT_NAME'];
$script_path = dirname($script_name);
$customer_pos = strpos($script_path, '/customer');
$base_path = $customer_pos !== false ? substr($script_path, 0, $customer_pos) : '';

// Get all branches for dropdown
$stmt = $conn->prepare("SELECT name FROM branches");
$stmt->execute();
$branches = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Login - Gym Network</title>
  <link rel="stylesheet" href="<?php echo $base_path; ?>/assets/css/styles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div class="container">
      <div class="left-panel">
          <div class="content">
              <h1>Welcome back<br>to fitness</h1>
              <p>Sign in to access your membership details, book classes, and track your fitness progress.</p>
          </div>
          <div class="illustration">
              <img src="<?php echo $base_path; ?>/assets/images/gym-customer-illustration.png" alt="Fitness Illustration">
          </div>
      </div>
      <div class="right-panel">
          <div class="form-container">
              <h2>Customer Login</h2>
              <p class="subtitle">Sign in to your account</p>
              
              <?php if (isset($_SESSION['register_success'])): ?>
                  <div class="success-message">
                      <?php echo $_SESSION['register_success']; unset($_SESSION['register_success']); ?>
                  </div>
              <?php endif; ?>
              
              <?php if (isset($_SESSION['login_errors'])): ?>
                  <div class="error-message">
                      <ul>
                          <?php foreach ($_SESSION['login_errors'] as $error): ?>
                              <li><?php echo $error; ?></li>
                          <?php endforeach; ?>
                      </ul>
                  </div>
                  <?php unset($_SESSION['login_errors']); ?>
              <?php endif; ?>
              
              <form action="login.php" method="post">
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
                  
                  <div class="form-group">
                      <label for="branch">Your Branch</label>
                      <select id="branch" name="branch">
                          <option value="">Select your branch</option>
                          <?php while ($branch = $branches->fetch_assoc()): ?>
                              <option value="<?php echo htmlspecialchars($branch['name']); ?>">
                                  <?php echo htmlspecialchars($branch['name']); ?>
                              </option>
                          <?php endwhile; ?>
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
                  
                  <button type="submit" class="sign-in-btn">Sign in</button>
              </form>
              
              <p class="register-link">Don't have an account? <a href="register.php">Register now</a></p>
              
              <div style="margin-top: 20px; text-align: center;">
                  <a href="fix_customer.php" style="color: #777; font-size: 12px;">Fix Customer Accounts</a>
              </div>
          </div>
      </div>
  </div>

  <script>
      function togglePassword() {
          const passwordInput = document.getElementById('password');
          const toggleIcon = document.querySelector('.toggle-password i');
          
          if (passwordInput.type === 'password') {
              passwordInput.type = 'text';
              toggleIcon.classList.remove('fa-eye');
              toggleIcon.classList.add('fa-eye-slash');
          } else {
              passwordInput.type = 'password';
              toggleIcon.classList.remove('fa-eye-slash');
              toggleIcon.classList.add('fa-eye');
          }
      }
  </script>
</body>
</html>
