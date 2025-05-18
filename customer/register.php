<?php
// Start session
include 'includes/pageeffect.php';

// Include database connection
require_once '../includes/db_connect.php';

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data and sanitize
    $first_name = filter_var($_POST['first_name'], FILTER_SANITIZE_STRING);
    $last_name = filter_var($_POST['last_name'], FILTER_SANITIZE_STRING);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $phone = filter_var($_POST['phone'], FILTER_SANITIZE_STRING);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $branch = $_POST['branch'];
    $fitness_goal = $_POST['fitness_goal'];
    
    // Validate data
    $errors = [];
    
    if (empty($first_name) || empty($last_name)) {
        $errors[] = "Name is required";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email is required";
    }
    
    if (empty($phone)) {
        $errors[] = "Phone number is required";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    if (empty($branch)) {
        $errors[] = "Please select a branch";
    }
    
    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM customers WHERE email = ?");
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
        
        // Insert customer data
        $stmt = $conn->prepare("INSERT INTO customers (first_name, last_name, email, phone, password, branch, fitness_goal) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $first_name, $last_name, $email, $phone, $hashed_password, $branch, $fitness_goal);
        
        if ($stmt->execute()) {
            // Registration successful
            $_SESSION['register_success'] = "Registration successful! You can now login.";
            header("Location: login.php");
            exit();
        } else {
            $errors[] = "Registration failed: " . $conn->error;
        }
    }
    
    // Store errors in session
    if (!empty($errors)) {
        $_SESSION['register_errors'] = $errors;
    }
    
    $stmt->close();
}

// Get branches for dropdown
$stmt = $conn->prepare("SELECT name FROM branches");
$stmt->execute();
$branches = $stmt->get_result();

// If no branches found, create sample branches
if ($branches->num_rows == 0) {
    $sample_branches = [
        ['Downtown Fitness', '123 Main St, Downtown'],
        ['Uptown Gym', '456 High St, Uptown'],
        ['Westside Health Club', '789 West Blvd, Westside'],
        ['East End Fitness Center', '321 East Ave, East End']
    ];
    
    $stmt = $conn->prepare("INSERT INTO branches (name, location) VALUES (?, ?)");
    
    foreach ($sample_branches as $branch) {
        $stmt->bind_param("ss", $branch[0], $branch[1]);
        $stmt->execute();
    }
    
    // Retry getting branches
    $stmt = $conn->prepare("SELECT name FROM branches");
    $stmt->execute();
    $branches = $stmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Registration - Gym Network</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="left-panel">
            <div class="content">
                <h1>Fitness starts<br>with you</h1>
                <p>Join our network of fitness centers and start your journey to a healthier lifestyle with personalized training programs.</p>
            </div>
            <div class="illustration">
                <img src="../assets/images/gym-customer-illustration.png" alt="Fitness Illustration">
            </div>
        </div>
        <div class="right-panel">
            <div class="form-container">
                <h2>Customer Registration</h2>
                <p class="subtitle">Create your account to get started</p>
                
                <?php if (isset($_SESSION['register_errors'])): ?>
                    <div class="error-message">
                        <ul>
                            <?php foreach ($_SESSION['register_errors'] as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php unset($_SESSION['register_errors']); ?>
                <?php endif; ?>
                
                <form action="register.php" method="post">
                    <div class="form-row">
                        <div class="form-group half">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" required value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                        </div>
                        <div class="form  : ''; ?>">
                        </div>
                        <div class="form-group half">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" required value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" required value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-container">
                            <input type="password" id="password" name="password" required>
                            <span class="toggle-password" onclick="togglePasswordVisibility('password')">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        <p class="password-hint">Must be at least 8 characters</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-container">
                            <input type="password" id="confirm_password" name="confirm_password" required>
                            <span class="toggle-password" onclick="togglePasswordVisibility('confirm_password')">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="branch">Select Branch</label>
                        <select id="branch" name="branch" required>
                            <option value="">Select a branch</option>
                            <?php while ($branch = $branches->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($branch['name']); ?>" <?php echo (isset($_POST['branch']) && $_POST['branch'] == $branch['name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($branch['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="fitness_goal">Fitness Goal</label>
                        <select id="fitness_goal" name="fitness_goal">
                            <option value="">Select your primary goal</option>
                            <option value="weight_loss" <?php echo (isset($_POST['fitness_goal']) && $_POST['fitness_goal'] == 'weight_loss') ? 'selected' : ''; ?>>Weight Loss</option>
                            <option value="muscle_gain" <?php echo (isset($_POST['fitness_goal']) && $_POST['fitness_goal'] == 'muscle_gain') ? 'selected' : ''; ?>>Muscle Gain</option>
                            <option value="endurance" <?php echo (isset($_POST['fitness_goal']) && $_POST['fitness_goal'] == 'endurance') ? 'selected' : ''; ?>>Endurance</option>
                            <option value="flexibility" <?php echo (isset($_POST['fitness_goal']) && $_POST['fitness_goal'] == 'flexibility') ? 'selected' : ''; ?>>Flexibility</option>
                            <option value="general_fitness" <?php echo (isset($_POST['fitness_goal']) && $_POST['fitness_goal'] == 'general_fitness') ? 'selected' : ''; ?>>General Fitness</option>
                        </select>
                    </div>
                    
                    <div class="form-group terms">
                        <label class="checkbox-container">
                            <input type="checkbox" name="terms" required>
                            <span class="checkmark"></span>
                            <span class="checkbox-text">I agree to Gym's <a href="#">Terms of Use</a> and <a href="#">Privacy Policy</a></span>
                        </label>
                    </div>
                    
                    <button type="submit" class="sign-in-btn">Create Account</button>
                </form>
                
                <p class="register-link">Already have an account? <a href="login.php">Sign in</a></p>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
</body>
</html>

