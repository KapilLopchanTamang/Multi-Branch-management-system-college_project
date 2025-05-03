<?php
// Start session
session_start();

// Include database connection
require_once '../../includes/db_connect.php';

// Check if user is logged in and is a branch admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'branch_admin') {
  // Redirect to login page if not logged in or not a branch admin
  header("Location: ../login.php");
  exit();
}

// Get admin's branch
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT b.name as branch_name FROM admins a JOIN branches b ON a.id = b.admin_id WHERE a.id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$branch_name = $admin['branch_name'];

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Get form data and sanitize
  $first_name = filter_var($_POST['first_name'], FILTER_SANITIZE_STRING);
  $last_name = filter_var($_POST['last_name'], FILTER_SANITIZE_STRING);
  $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
  $phone = filter_var($_POST['phone'], FILTER_SANITIZE_STRING);
  $address = filter_var($_POST['address'], FILTER_SANITIZE_STRING);
  $password = $_POST['password'];
  $branch = $_POST['branch']; // This should match the admin's branch
  $subscription_type = $_POST['subscription_type'];
  $fitness_goal = $_POST['fitness_goal'];
  $weight = !empty($_POST['weight']) ? filter_var($_POST['weight'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null;
  
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
  
  if ($branch !== $branch_name) {
      $errors[] = "Invalid branch selection";
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
      
      // Check if the customers table has all required columns
      $result = $conn->query("SHOW COLUMNS FROM customers LIKE 'address'");
      if ($result->num_rows == 0) {
          // Add missing columns to customers table
          $conn->query("ALTER TABLE customers ADD COLUMN address VARCHAR(255) AFTER last_name");
          $conn->query("ALTER TABLE customers ADD COLUMN subscription_type ENUM('monthly', 'six_months', 'yearly') DEFAULT 'monthly' AFTER branch");
          $conn->query("ALTER TABLE customers ADD COLUMN weight DECIMAL(5,2) AFTER fitness_goal");
      }
      
      // Insert customer data
      $stmt = $conn->prepare("INSERT INTO customers (first_name, last_name, email, phone, address, password, branch, subscription_type, fitness_goal, weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->bind_param("sssssssssd", $first_name, $last_name, $email, $phone, $address, $hashed_password, $branch, $subscription_type, $fitness_goal, $weight);
      
      if ($stmt->execute()) {
          // Get the new customer ID
          $customer_id = $conn->insert_id;
          
          // Create a membership record for this customer
          $start_date = date('Y-m-d');
          $end_date = date('Y-m-d');
          $status = 'Active';
          
          // Set end date based on subscription type
          if ($subscription_type === 'monthly') {
              $end_date = date('Y-m-d', strtotime('+1 month'));
          } elseif ($subscription_type === 'six_months') {
              $end_date = date('Y-m-d', strtotime('+6 months'));
          } elseif ($subscription_type === 'yearly') {
              $end_date = date('Y-m-d', strtotime('+1 year'));
          }
          
          // Check if memberships table exists
          $result = $conn->query("SHOW TABLES LIKE 'memberships'");
          if ($result->num_rows == 0) {
              // Create memberships table
              $conn->query("CREATE TABLE IF NOT EXISTS memberships (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  customer_id INT NOT NULL,
                  membership_type VARCHAR(50) NOT NULL,
                  status VARCHAR(20) NOT NULL,
                  start_date DATE NOT NULL,
                  end_date DATE NOT NULL,
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
              )");
          }
          
          // Insert membership record
          $stmt = $conn->prepare("INSERT INTO memberships (customer_id, membership_type, status, start_date, end_date) VALUES (?, ?, ?, ?, ?)");
          $stmt->bind_param("issss", $customer_id, $subscription_type, $status, $start_date, $end_date);
          $stmt->execute();
          
          // Registration successful
          $_SESSION['customer_message'] = "Customer added successfully!";
          $_SESSION['customer_message_type'] = "success";
      } else {
          $_SESSION['customer_message'] = "Error adding customer: " . $conn->error;
          $_SESSION['customer_message_type'] = "error";
      }
  } else {
      // Store errors in session
      $_SESSION['customer_message'] = "Error: " . implode(", ", $errors);
      $_SESSION['customer_message_type'] = "error";
  }
  
  // Redirect back to customers page
  header("Location: customers.php");
  exit();
}
else {
  // If not POST request, redirect to customers page
  header("Location: customers.php");
  exit();
}
?>

