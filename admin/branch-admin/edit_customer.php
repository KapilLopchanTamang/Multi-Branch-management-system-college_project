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
  $customer_id = intval($_POST['customer_id']);
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
  
  if ($branch !== $branch_name) {
      $errors[] = "Invalid branch selection";
  }
  
  // Check if customer exists and belongs to this branch
  $stmt = $conn->prepare("SELECT id, subscription_type FROM customers WHERE id = ? AND branch = ?");
  $stmt->bind_param("is", $customer_id, $branch_name);
  $stmt->execute();
  $result = $stmt->get_result();
  
  if ($result->num_rows === 0) {
      $errors[] = "Customer not found or doesn't belong to your branch";
  } else {
      $current_customer = $result->fetch_assoc();
      $old_subscription_type = $current_customer['subscription_type'];
  }
  
  // Check if email already exists for another customer
  $stmt = $conn->prepare("SELECT id FROM customers WHERE email = ? AND id != ?");
  $stmt->bind_param("si", $email, $customer_id);
  $stmt->execute();
  $result = $stmt->get_result();
  
  if ($result->num_rows > 0) {
      $errors[] = "Email already registered to another customer";
  }
  
  // If no errors, proceed with update
  if (empty($errors)) {
      // If password is provided, update it
      if (!empty($password)) {
          // Validate password
          if (strlen($password) < 8) {
              $errors[] = "Password must be at least 8 characters";
          } else {
              // Hash password
              $hashed_password = password_hash($password, PASSWORD_DEFAULT);
              
              // Update customer data with new password
              $stmt = $conn->prepare("UPDATE customers SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, password = ?, subscription_type = ?, fitness_goal = ?, weight = ? WHERE id = ?");
              $stmt->bind_param("ssssssssdi", $first_name, $last_name, $email, $phone, $address, $hashed_password, $subscription_type, $fitness_goal, $weight, $customer_id);
          }
      } else {
          // Update customer data without changing password
          $stmt = $conn->prepare("UPDATE customers SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, subscription_type = ?, fitness_goal = ?, weight = ? WHERE id = ?");
          $stmt->bind_param("sssssssdi", $first_name, $last_name, $email, $phone, $address, $subscription_type, $fitness_goal, $weight, $customer_id);
      }
      
      if (empty($errors) && $stmt->execute()) {
          // Update successful
          $_SESSION['customer_message'] = "Customer updated successfully!";
          $_SESSION['customer_message_type'] = "success";
          
          // If subscription type has changed, update membership
          if (isset($old_subscription_type) && $old_subscription_type !== $subscription_type) {
              // Get current date
              $start_date = date('Y-m-d');
              $end_date = date('Y-m-d');
              $status = 'Active';
              
              // Set end date based on new subscription type
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
              
              // Update or insert membership record
              $stmt = $conn->prepare("SELECT id FROM memberships WHERE customer_id = ? ORDER BY created_at DESC LIMIT 1");
              $stmt->bind_param("i", $customer_id);
              $stmt->execute();
              $result = $stmt->get_result();
              
              if ($result->num_rows > 0) {
                  // Update existing membership
                  $membership = $result->fetch_assoc();
                  $stmt = $conn->prepare("UPDATE memberships SET membership_type = ?, status = ?, start_date = ?, end_date = ? WHERE id = ?");
                  $stmt->bind_param("ssssi", $subscription_type, $status, $start_date, $end_date, $membership['id']);
              } else {
                  // Insert new membership
                  $stmt = $conn->prepare("INSERT INTO memberships (customer_id, membership_type, status, start_date, end_date) VALUES (?, ?, ?, ?, ?)");
                  $stmt->bind_param("issss", $customer_id, $subscription_type, $status, $start_date, $end_date);
              }
              
              $stmt->execute();
          }
      } else {
          $_SESSION['customer_message'] = "Error updating customer: " . $conn->error;
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

