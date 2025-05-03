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

// Check if admin_override column exists in attendance table
$result = $conn->query("SHOW COLUMNS FROM attendance LIKE 'admin_override'");
if ($result->num_rows == 0) {
    // Add admin_override column if it doesn't exist
    $conn->query("ALTER TABLE attendance ADD COLUMN admin_override TINYINT(1) DEFAULT 0 AFTER notes");
}

// Get admin data and branch info
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT a.name, a.email, b.id as branch_id, b.name as branch_name, b.location 
                    FROM admins a 
                    LEFT JOIN branches b ON a.id = b.admin_id 
                    WHERE a.id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

$branch_name = $admin['branch_name'];

// Check if attendance_settings table exists, if not create it
$result = $conn->query("SHOW TABLES LIKE 'attendance_settings'");
if ($result->num_rows == 0) {
  // Create attendance_settings table
  $sql = "CREATE TABLE IF NOT EXISTS attendance_settings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      branch VARCHAR(100) NOT NULL,
      allow_self_checkin BOOLEAN DEFAULT FALSE,
      max_entries_per_day INT DEFAULT 1,
      require_checkout BOOLEAN DEFAULT FALSE,
      auto_checkout_after INT DEFAULT 180, -- in minutes (3 hours)
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY (branch)
  )";
  $conn->query($sql);
  
  // Insert default settings for this branch
  $stmt = $conn->prepare("INSERT INTO attendance_settings (branch, allow_self_checkin, max_entries_per_day, require_checkout, auto_checkout_after) 
                        VALUES (?, FALSE, 1, FALSE, 180)");
  $stmt->bind_param("s", $branch_name);
  $stmt->execute();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $allow_self_checkin = isset($_POST['allow_self_checkin']) ? 1 : 0;
  $max_entries_per_day = intval($_POST['max_entries_per_day']);
  $require_checkout = isset($_POST['require_checkout']) ? 1 : 0;
  $auto_checkout_after = intval($_POST['auto_checkout_after']);
  
  // Validate inputs
  if ($max_entries_per_day < 1) {
      $max_entries_per_day = 1;
  }
  
  if ($auto_checkout_after < 30) {
      $auto_checkout_after = 30;
  }
  
  // Update settings
  $stmt = $conn->prepare("INSERT INTO attendance_settings 
                        (branch, allow_self_checkin, max_entries_per_day, require_checkout, auto_checkout_after) 
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        allow_self_checkin = VALUES(allow_self_checkin),
                        max_entries_per_day = VALUES(max_entries_per_day),
                        require_checkout = VALUES(require_checkout),
                        auto_checkout_after = VALUES(auto_checkout_after)");
  $stmt->bind_param("siiii", $branch_name, $allow_self_checkin, $max_entries_per_day, $require_checkout, $auto_checkout_after);
  
  if ($stmt->execute()) {
      $_SESSION['settings_message'] = "Attendance settings updated successfully!";
      $_SESSION['settings_message_type'] = "success";
  } else {
      $_SESSION['settings_message'] = "Error updating settings: " . $conn->error;
      $_SESSION['settings_message_type'] = "error";
  }
  
  // Redirect to avoid resubmission
  header("Location: attendance_settings.php");
  exit();
}

// Handle auto checkout request
if (isset($_POST['run_auto_checkout'])) {
    // Get auto checkout time from settings
    $stmt = $conn->prepare("SELECT auto_checkout_after FROM attendance_settings WHERE branch = ?");
    $stmt->bind_param("s", $branch_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
    $auto_checkout_after = $settings['auto_checkout_after'] ?? 180; // Default 3 hours
    
    // Run auto checkout for this branch
    $stmt = $conn->prepare("UPDATE attendance 
                          SET check_out = NOW(), 
                              notes = CONCAT(IFNULL(notes, ''), ' | Manual auto checkout triggered by admin') 
                          WHERE branch = ? 
                          AND check_out IS NULL 
                          AND TIMESTAMPDIFF(MINUTE, check_in, NOW()) > ?");
    $stmt->bind_param("si", $branch_name, $auto_checkout_after);
    $stmt->execute();
    
    $affected_rows = $stmt->affected_rows;
    
    if ($affected_rows > 0) {
        $_SESSION['settings_message'] = "Auto checkout completed successfully! $affected_rows customer(s) were checked out.";
        $_SESSION['settings_message_type'] = "success";
    } else {
        $_SESSION['settings_message'] = "No customers needed to be automatically checked out.";
        $_SESSION['settings_message_type'] = "info";
    }
    
    // Redirect to avoid resubmission
    header("Location: attendance_settings.php");
    exit();
}

// Handle data cleanup request
if (isset($_POST['cleanup_data'])) {
    // Delete attendance records older than 1 year
    $stmt = $conn->prepare("DELETE FROM attendance 
                          WHERE branch = ? 
                          AND check_in < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
    $stmt->bind_param("s", $branch_name);
    $stmt->execute();
    
    $affected_rows = $stmt->affected_rows;
    
    if ($affected_rows > 0) {
        $_SESSION['settings_message'] = "Data cleanup completed successfully! $affected_rows old attendance record(s) were removed.";
        $_SESSION['settings_message_type'] = "success";
    } else {
        $_SESSION['settings_message'] = "No old attendance records found to clean up.";
        $_SESSION['settings_message_type'] = "info";
    }
    
    // Redirect to avoid resubmission
    header("Location: attendance_settings.php");
    exit();
}

// Get current settings
$stmt = $conn->prepare("SELECT * FROM attendance_settings WHERE branch = ?");
$stmt->bind_param("s", $branch_name);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
  $settings = $result->fetch_assoc();
} else {
  // Default settings if not found
  $settings = [
      'allow_self_checkin' => 0,
      'max_entries_per_day' => 1,
      'require_checkout' => 0,
      'auto_checkout_after' => 180
  ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance Settings - Gym Network</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
      .settings-container {
          max-width: 800px;
          margin: 0 auto;
      }
      
      .settings-card {
          background-color: #fff;
          border-radius: 8px;
          padding: 30px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
          margin-bottom: 20px;
      }
      
      .settings-card h3 {
          margin-top: 0;
          margin-bottom: 20px;
          color: #333;
          font-size: 18px;
          border-bottom: 1px solid #eee;
          padding-bottom: 10px;
      }
      
      .settings-group {
          margin-bottom: 25px;
      }
      
      .settings-group:last-child {
          margin-bottom: 0;
      }
      
      .settings-group h4 {
          margin-top: 0;
          margin-bottom: 15px;
          color: #555;
          font-size: 16px;
      }
      
      .settings-option {
          margin-bottom: 15px;
      }
      
      .settings-option label {
          display: block;
          margin-bottom: 8px;
          font-weight: 500;
          color: #555;
      }
      
      .settings-option input[type="text"],
      .settings-option input[type="number"],
      .settings-option select {
          width: 100%;
          padding: 10px 15px;
          border: 1px solid #ddd;
          border-radius: 4px;
          font-size: 14px;
      }
      
      .settings-option input[type="checkbox"] {
          margin-right: 10px;
      }
      
      .checkbox-label {
          display: flex;
          align-items: center;
          cursor: pointer;
      }
      
      .checkbox-label span {
          font-weight: normal;
      }
      
      .settings-description {
          margin-top: 5px;
          font-size: 13px;
          color: #777;
      }
      
      .settings-actions {
          display: flex;
          justify-content: flex-end;
          gap: 10px;
          margin-top: 20px;
      }
      
      .settings-btn {
          padding: 10px 20px;
          border-radius: 4px;
          font-size: 14px;
          cursor: pointer;
          border: none;
          display: inline-flex;
          align-items: center;
          gap: 5px;
      }
      
      .settings-btn.save {
          background-color: #ff6b45;
          color: white;
      }
      
      .settings-btn.reset {
          background-color: #f5f5f5;
          color: #333;
      }
      
      .settings-btn:hover {
          opacity: 0.9;
      }
      
      .settings-info {
          background-color: #e3f2fd;
          border-left: 4px solid #1976d2;
          padding: 15px;
          margin-bottom: 20px;
          border-radius: 4px;
      }
      
      .settings-info p {
          margin: 0;
          color: #0d47a1;
          font-size: 14px;
      }
      
      .settings-warning {
          background-color: #fff3cd;
          border-left: 4px solid #ffc107;
          padding: 15px;
          margin-bottom: 20px;
          border-radius: 4px;
      }
      
      .settings-warning p {
          margin: 0;
          color: #856404;
          font-size: 14px;
      }
  </style>
</head>
<body>
  <div class="dashboard-container">
      <!-- Sidebar -->
      <div class="sidebar">
          <div class="logo">
              <h2>Gym Network</h2>
          </div>
          <div class="menu">
              <ul>
                  <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                  <li><a href="customers.php"><i class="fas fa-user-friends"></i> Customers</a></li>
                  <li><a href="attendance.php"><i class="fas fa-clipboard-check"></i> Attendance</a></li>
                  <li><a href="#"><i class="fas fa-calendar-alt"></i> Classes</a></li>
                  <li><a href="#"><i class="fas fa-dumbbell"></i> Equipment</a></li>
                  <li class="active"><a href="attendance_settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                  <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
              </ul>
          </div>
      </div>
      
      <!-- Main Content -->
      <div class="main-content">
          <div class="header">
              <div class="page-title">
                  <h1>Attendance Settings</h1>
              </div>
              <div class="user-info">
                  <span>Welcome, <?php echo htmlspecialchars($admin['name']); ?></span>
                  <div class="user-avatar">
                      <i class="fas fa-user-circle"></i>
                  </div>
              </div>
          </div>
          
          <!-- Dashboard Content -->
          <div class="dashboard-content">
              <?php if (isset($_SESSION['settings_message'])): ?>
                  <div class="<?php echo $_SESSION['settings_message_type'] === 'success' ? 'success-message' : ($_SESSION['settings_message_type'] === 'info' ? 'info-message' : 'error-message'); ?>">
                      <?php echo $_SESSION['settings_message']; ?>
                  </div>
                  <?php unset($_SESSION['settings_message'], $_SESSION['settings_message_type']); ?>
              <?php endif; ?>
              
              <div class="settings-container">
                  <div class="settings-info">
                      <p><i class="fas fa-info-circle"></i> These settings control how attendance is managed for <?php echo htmlspecialchars($branch_name); ?>.</p>
                  </div>
                  
                  <form action="attendance_settings.php" method="post">
                      <div class="settings-card">
                          <h3><i class="fas fa-cog"></i> General Attendance Settings</h3>
                          
                          <div class="settings-group">
                              <h4>Check-in Options</h4>
                              
                              <div class="settings-option">
                                  <label class="checkbox-label">
                                      <input type="checkbox" name="allow_self_checkin" <?php if ($settings['allow_self_checkin']) echo 'checked'; ?>>
                                      <span>Allow customers to check themselves in</span>
                                  </label>
                                  <div class="settings-description">
                                      If enabled, customers can check themselves in using the customer portal or QR code.
                                  </div>
                              </div>
                              
                              <div class="settings-option">
                                  <label for="max_entries_per_day">Maximum check-ins per day</label>
                                  <input type="number" id="max_entries_per_day" name="max_entries_per_day" min="1" max="10" value="<?php echo $settings['max_entries_per_day']; ?>">
                                  <div class="settings-description">
                                      The maximum number of times a customer can check in per day. Additional check-ins require admin override.
                                  </div>
                              </div>
                          </div>
                          
                          <div class="settings-group">
                              <h4>Check-out Options</h4>
                              
                              <div class="settings-option">
                                  <label class="checkbox-label">
                                      <input type="checkbox" name="require_checkout" <?php if ($settings['require_checkout']) echo 'checked'; ?>>
                                      <span>Require check-out</span>
                                  </label>
                                  <div class="settings-description">
                                      If enabled, customers must check out when leaving the gym.
                                  </div>
                              </div>
                              
                              <div class="settings-option">
                                  <label for="auto_checkout_after">Auto check-out after (minutes)</label>
                                  <input type="number" id="auto_checkout_after" name="auto_checkout_after" min="30" max="720" value="<?php echo $settings['auto_checkout_after']; ?>">
                                  <div class="settings-description">
                                      Automatically check out customers after this many minutes (minimum 30 minutes).
                                  </div>
                              </div>
                          </div>
                          
                          <div class="settings-actions">
                              <button type="reset" class="settings-btn reset">
                                  <i class="fas fa-undo"></i> Reset
                              </button>
                              <button type="submit" class="settings-btn save">
                                  <i class="fas fa-save"></i> Save Settings
                              </button>
                          </div>
                      </div>
                  </form>
                  
                  <div class="settings-card">
                      <h3><i class="fas fa-tools"></i> Advanced Options</h3>
                      
                      <div class="settings-group">
                          <h4>Data Management</h4>
                          
                          <form action="attendance_settings.php" method="post">
                              <div class="settings-option">
                                  <button type="submit" name="cleanup_data" class="settings-btn reset" onclick="return confirmDataCleanup()">
                                      <i class="fas fa-broom"></i> Clean Up Old Attendance Data
                                  </button>
                                  <div class="settings-description">
                                      Remove attendance records older than 1 year to optimize database performance.
                                  </div>
                              </div>
                          </form>
                          
                          <form action="attendance_settings.php" method="post">
                              <div class="settings-option">
                                  <button type="submit" name="run_auto_checkout" class="settings-btn reset" onclick="return confirmAutoCheckout()">
                                      <i class="fas fa-sign-out-alt"></i> Run Auto Check-out Now
                                  </button>
                                  <div class="settings-description">
                                      Manually trigger the auto check-out process for customers who are still checked in.
                                  </div>
                              </div>
                          </form>
                      </div>
                      
                      <div class="settings-warning">
                          <p><i class="fas fa-exclamation-triangle"></i> <strong>Important:</strong> The auto check-out process runs automatically when the attendance page is loaded. This button is only needed if you want to force an immediate check-out.</p>
                      </div>
                  </div>
              </div>
          </div>
      </div>
  </div>

  <script>
      // Confirm data cleanup
      function confirmDataCleanup() {
          return confirm('Are you sure you want to remove attendance records older than 1 year? This action cannot be undone.');
      }
      
      // Confirm auto check-out
      function confirmAutoCheckout() {
          return confirm('Are you sure you want to automatically check out all customers who have been checked in longer than the auto-checkout time?');
      }
  </script>
</body>
</html>

