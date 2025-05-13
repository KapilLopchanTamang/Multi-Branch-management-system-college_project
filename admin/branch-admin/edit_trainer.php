<?php
// Add this code at the beginning of the file, right after the PHP opening tag
// This handles the AJAX upload response

// Handle AJAX file uploads
if (isset($_POST['ajax_upload']) && $_POST['ajax_upload'] === '1' && isset($_FILES['profile_photo'])) {
    $response = ['success' => false, 'message' => ''];
    
    // Create directories if they don't exist
    $base_dir = '../../uploads';
    $trainer_dir = $base_dir . '/trainer_photos';
    
    // Create base directory if it doesn't exist
    if (!file_exists($base_dir)) {
        // Try PHP mkdir first
        if (!@mkdir($base_dir, 0777, true)) {
            // Try system command as fallback
            @system('mkdir -p ' . escapeshellarg($base_dir));
            @system('chmod 777 ' . escapeshellarg($base_dir));
        }
    }
    
    // Create trainer photos directory if it doesn't exist
    if (!file_exists($trainer_dir)) {
        // Try PHP mkdir first
        if (!@mkdir($trainer_dir, 0777, true)) {
            // Try system command as fallback
            @system('mkdir -p ' . escapeshellarg($trainer_dir));
            @system('chmod 777 ' . escapeshellarg($trainer_dir));
        }
    }
    
    // Set permissions
    @chmod($base_dir, 0777);
    @chmod($trainer_dir, 0777);
    
    // Get trainer ID from POST or GET
    $trainer_id = isset($_POST['trainer_id']) ? $_POST['trainer_id'] : (isset($_GET['id']) ? $_GET['id'] : 0);
    
    // Process the upload
    if ($_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $file_extension = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $new_filename = 'trainer_' . $trainer_id . '_' . time() . '.' . $file_extension;
        $upload_path = $trainer_dir . '/' . $new_filename;
        
        $upload_success = false;
        
        // Try multiple upload methods
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_path)) {
            $upload_success = true;
        } else if (copy($_FILES['profile_photo']['tmp_name'], $upload_path)) {
            $upload_success = true;
        } else {
            @system('cp ' . escapeshellarg($_FILES['profile_photo']['tmp_name']) . ' ' . escapeshellarg($upload_path));
            if (file_exists($upload_path)) {
                $upload_success = true;
            }
        }
        
        if ($upload_success) {
            $response['success'] = true;
            $response['message'] = 'File uploaded successfully';
            $response['file_path'] = 'uploads/trainer_photos/' . $new_filename;
            
            // Update the database if needed
            if ($trainer_id > 0) {
                // Include database connection if not already included
                if (!isset($conn)) {
                    require_once '../../includes/db_connect.php';
                }
                
                // Update the trainer's profile photo in the database
                $photo_path = 'uploads/trainer_photos/' . $new_filename;
                $stmt = $conn->prepare("UPDATE trainers SET profile_photo = ? WHERE id = ?");
                $stmt->bind_param("si", $photo_path, $trainer_id);
                $stmt->execute();
            }
        } else {
            $response['message'] = 'Failed to move uploaded file. Server error: ' . error_get_last()['message'];
        }
    } else {
        $response['message'] = 'File upload error: ' . $_FILES['profile_photo']['error'];
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
// Add this PHP code at the very beginning of the file, right after the opening <?php tag

// Debug information for file uploads
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check upload directory permissions and display information
function checkUploadDirectories() {
    $base_dir = '../../uploads';
    $trainer_dir = $base_dir . '/trainer_photos';
    $info = [];
    
    // Check if base directory exists
    $info[] = "Base upload directory (" . $base_dir . "): " . (file_exists($base_dir) ? "Exists" : "Does not exist");
    if (file_exists($base_dir)) {
        $info[] = "Base directory permissions: " . substr(sprintf('%o', fileperms($base_dir)), -4);
        $info[] = "Base directory writable: " . (is_writable($base_dir) ? "Yes" : "No");
    }
    
    // Check if trainer photos directory exists
    $info[] = "Trainer photos directory (" . $trainer_dir . "): " . (file_exists($trainer_dir) ? "Exists" : "Does not exist");
    if (file_exists($trainer_dir)) {
        $info[] = "Trainer directory permissions: " . substr(sprintf('%o', fileperms($trainer_dir)), -4);
        $info[] = "Trainer directory writable: " . (is_writable($trainer_dir) ? "Yes" : "No");
    }
    
    // Check PHP settings
    $info[] = "PHP upload_max_filesize: " . ini_get('upload_max_filesize');
    $info[] = "PHP post_max_size: " . ini_get('post_max_size');
    $info[] = "PHP max_execution_time: " . ini_get('max_execution_time');
    
    return $info;
}

// Add a server-side fallback method for creating the upload directory
// Add this function after the checkUploadDirectories function

// Function to create upload directories with proper permissions
function createUploadDirectories() {
    $base_dir = '../../uploads';
    $trainer_dir = $base_dir . '/trainer_photos';
    $result = ['success' => true, 'messages' => []];
    
    // Try to create base directory if it doesn't exist
    if (!file_exists($base_dir)) {
        if (!@mkdir($base_dir, 0777, true)) {
            $result['success'] = false;
            $result['messages'][] = "Failed to create base upload directory: " . $base_dir;
        } else {
            $result['messages'][] = "Successfully created base upload directory";
            @chmod($base_dir, 0777); // Set permissions
        }
    }
    
    // Try to create trainer photos directory if it doesn't exist
    if (file_exists($base_dir) && !file_exists($trainer_dir)) {
        if (!@mkdir($trainer_dir, 0777, true)) {
            $result['success'] = false;
            $result['messages'][] = "Failed to create trainer photos directory: " . $trainer_dir;
        } else {
            $result['messages'][] = "Successfully created trainer photos directory";
            @chmod($trainer_dir, 0777); // Set permissions
        }
    }
    
    return $result;
}

// Add a PHP function to create a temporary upload directory in the system temp directory
// Add this function after the createUploadDirectories function

// Function to use system temp directory as fallback
function useTempDirectoryFallback($file) {
    $result = ['success' => false, 'file_path' => '', 'message' => ''];
    
    // Get system temp directory
    $temp_dir = sys_get_temp_dir();
    if (!is_writable($temp_dir)) {
        $result['message'] = "System temp directory is not writable: " . $temp_dir;
        return $result;
    }
    
    // Create a unique filename
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = 'trainer_temp_' . time() . '_' . mt_rand(1000, 9999) . '.' . $file_extension;
    $temp_path = $temp_dir . '/' . $new_filename;
    
    // Move the uploaded file to temp directory
    if (move_uploaded_file($file['tmp_name'], $temp_path)) {
        $result['success'] = true;
        $result['file_path'] = $temp_path;
        $result['message'] = "File temporarily stored in system temp directory";
    } else {
        $result['message'] = "Failed to move file to system temp directory";
    }
    
    return $result;
}

// Try to create directories on page load
$create_result = createUploadDirectories();
if (!$create_result['success']) {
    foreach ($create_result['messages'] as $message) {
        $errors[] = $message;
    }
}

// Get directory information
$directory_info = checkUploadDirectories();

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

// Get admin data
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT a.name, a.email, b.name as branch_name, b.location 
                      FROM admins a 
                      LEFT JOIN branches b ON a.id = b.admin_id 
                      WHERE a.id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$branch_name = $admin['branch_name'];

// Check if trainer ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
  $_SESSION['error_message'] = "Trainer ID is required.";
  header("Location: trainers.php");
  exit();
}

$trainer_id = $_GET['id'];

// Get trainer data
$stmt = $conn->prepare("SELECT * FROM trainers WHERE id = ? AND branch = ?");
$stmt->bind_param("is", $trainer_id, $branch_name);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
  $_SESSION['error_message'] = "Trainer not found or does not belong to your branch.";
  header("Location: trainers.php");
  exit();
}

$trainer = $result->fetch_assoc();

// Initialize variables
$first_name = $trainer['first_name'];
$last_name = $trainer['last_name'];
$email = $trainer['email'];
$phone = $trainer['phone'];
$specialization = $trainer['specialization'];
$bio = $trainer['bio'];
$status = $trainer['status'];
$profile_photo = $trainer['profile_photo'];
$errors = [];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Validate and sanitize input
  $first_name = trim($_POST['first_name']);
  $last_name = trim($_POST['last_name']);
  $email = trim($_POST['email']);
  $phone = trim($_POST['phone']);
  $specialization = trim($_POST['specialization']);
  $bio = trim($_POST['bio']);
  $status = $_POST['status'];
  
  // Validation
  if (empty($first_name)) {
      $errors[] = "First name is required";
  }
  
  if (empty($last_name)) {
      $errors[] = "Last name is required";
  }
  
  if (empty($email)) {
      $errors[] = "Email is required";
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = "Invalid email format";
  } else {
      // Check if email already exists (excluding current trainer)
      $stmt = $conn->prepare("SELECT id FROM trainers WHERE email = ? AND id != ?");
      $stmt->bind_param("si", $email, $trainer_id);
      $stmt->execute();
      $result = $stmt->get_result();
      
      if ($result->num_rows > 0) {
          $errors[] = "Email already in use by another trainer";
      }
  }
  
  if (empty($phone)) {
      $errors[] = "Phone number is required";
  }
  
  // Handle profile photo upload
  $new_profile_photo = $profile_photo; // Default to current photo

if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK && !isset($_POST['ajax_upload'])) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($_FILES['profile_photo']['type'], $allowed_types)) {
        $errors[] = "Invalid file type. Only JPG, PNG, and GIF files are allowed.";
    } elseif ($_FILES['profile_photo']['size'] > $max_size) {
        $errors[] = "File size exceeds the maximum limit of 5MB.";
    } else {
        // Create uploads directory structure with error suppression
        $base_upload_dir = '../../uploads';
        $trainer_photos_dir = $base_upload_dir . '/trainer_photos';
        
        // Create directories with multiple methods to ensure success
        if (!file_exists($base_upload_dir)) {
            // Method 1: PHP mkdir
            @mkdir($base_upload_dir, 0777, true);
            
            // Method 2: System command if PHP mkdir fails
            if (!file_exists($base_upload_dir)) {
                @system('mkdir -p ' . escapeshellarg($base_upload_dir));
                @system('chmod 777 ' . escapeshellarg($base_upload_dir));
            }
        }
        
        if (!file_exists($trainer_photos_dir)) {
            // Method 1: PHP mkdir
            @mkdir($trainer_photos_dir, 0777, true);
            
            // Method 2: System command if PHP mkdir fails
            if (!file_exists($trainer_photos_dir)) {
                @system('mkdir -p ' . escapeshellarg($trainer_photos_dir));
                @system('chmod 777 ' . escapeshellarg($trainer_photos_dir));
            }
        }
        
        // Set permissions regardless of creation method
        @chmod($base_upload_dir, 0777);
        @chmod($trainer_photos_dir, 0777);
        
        // Generate unique filename
        $file_extension = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $new_filename = 'trainer_' . $trainer_id . '_' . time() . '.' . $file_extension;
        $upload_path = $trainer_photos_dir . '/' . $new_filename;
        
        // Try multiple upload methods
        $upload_success = false;
        
        // Method 1: Standard move_uploaded_file
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_path)) {
            $upload_success = true;
        } 
        // Method 2: Copy if move_uploaded_file fails
        else if (copy($_FILES['profile_photo']['tmp_name'], $upload_path)) {
            $upload_success = true;
        } 
        // Method 3: Use system command as last resort
        else {
            @system('cp ' . escapeshellarg($_FILES['profile_photo']['tmp_name']) . ' ' . escapeshellarg($upload_path));
            if (file_exists($upload_path)) {
                $upload_success = true;
            }
        }
        
        if ($upload_success) {
            $new_profile_photo = 'uploads/trainer_photos/' . $new_filename;
            
            // Delete old photo if exists
            if (!empty($profile_photo) && file_exists('../../' . $profile_photo)) {
                @unlink('../../' . $profile_photo);
            }
        } else {
            $upload_error = error_get_last();
            $errors[] = "Failed to upload profile photo. Error: " . ($upload_error ? $upload_error['message'] : 'Unknown error');
            
            // Try using system temp directory as fallback
            $temp_result = useTempDirectoryFallback($_FILES['profile_photo']);
            if ($temp_result['success']) {
                // Store the temp file path in session for later processing
                $_SESSION['temp_profile_photo'] = $temp_result['file_path'];
                $success_message = "Your photo has been temporarily stored. The administrator will need to fix the permissions issue.";
                // Clear the errors related to directory permissions
                $errors = array_filter($errors, function($error) {
                    return strpos($error, 'directory') === false && strpos($error, 'upload') === false;
                });
            }
        }
    }
} elseif (isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
    // Remove existing photo
    if (!empty($profile_photo) && file_exists('../../' . $profile_photo)) {
        @unlink('../../' . $profile_photo);
    }
    $new_profile_photo = null;
}
  
  // Add this to the file upload handling section
  if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK && !empty($errors)) {
      // If regular upload failed, try temp directory fallback
      $temp_result = useTempDirectoryFallback($_FILES['profile_photo']);
      if ($temp_result['success']) {
          // Store the temp file path in session for later processing
          $_SESSION['temp_profile_photo'] = $temp_result['file_path'];
          $success_message = "Your photo has been temporarily stored. The administrator will need to fix the permissions issue.";
          // Clear the errors related to directory permissions
          $errors = array_filter($errors, function($error) {
              return strpos($error, 'directory') === false;
          });
      }
  }
  
  // If no errors, update the trainer
  if (empty($errors)) {
      $stmt = $conn->prepare("UPDATE trainers SET first_name = ?, last_name = ?, email = ?, phone = ?, specialization = ?, bio = ?, status = ?, profile_photo = ? WHERE id = ?");
      $stmt->bind_param("ssssssssi", $first_name, $last_name, $email, $phone, $specialization, $bio, $status, $new_profile_photo, $trainer_id);
      
      if ($stmt->execute()) {
          // Redirect to trainers page with success message
          $_SESSION['success_message'] = "Trainer updated successfully.";
          header("Location: trainers.php");
          exit();
      } else {
          $errors[] = "Error updating trainer: " . $conn->error;
      }
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Trainer - <?php echo htmlspecialchars($branch_name); ?></title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
      :root {
          --primary-color: #3498db;
          --primary-hover: #2980b9;
          --secondary-color: #2ecc71;
          --secondary-hover: #27ae60;
          --danger-color: #e74c3c;
          --danger-hover: #c0392b;
          --warning-color: #f39c12;
          --warning-hover: #e67e22;
          --light-bg: #f8f9fa;
          --border-color: #e9ecef;
          --text-dark: #343a40;
          --text-muted: #6c757d;
      }
      
      .form-container {
          background-color: #fff;
          border-radius: 8px;
          padding: 30px;
          max-width: 800px;
          margin: 0 auto;
          box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      }
      
      .form-title {
          font-size: 20px;
          color: var(--text-dark);
          margin-top: 0;
          margin-bottom: 20px;
          padding-bottom: 15px;
          border-bottom: 1px solid var(--border-color);
          font-weight: 600;
      }
      
      .form-group {
          margin-bottom: 20px;
      }
      
      .form-label {
          display: block;
          margin-bottom: 8px;
          font-size: 14px;
          color: var(--text-dark);
          font-weight: 500;
      }
      
      .form-control {
          width: 100%;
          padding: 10px 12px;
          border: 1px solid var(--border-color);
          border-radius: 6px;
          font-size: 14px;
          color: var(--text-dark);
          transition: border-color 0.2s, box-shadow 0.2s;
      }
      
      .form-control:focus {
          border-color: var(--primary-color);
          outline: none;
          box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
      }
      
      .form-row {
          display: flex;
          gap: 20px;
      }
      
      .form-col {
          flex: 1;
      }
      
      .form-actions {
          display: flex;
          justify-content: flex-end;
          gap: 15px;
          margin-top: 30px;
          padding-top: 20px;
          border-top: 1px solid var(--border-color);
      }
      
      .btn {
          padding: 10px 16px;
          border: none;
          border-radius: 6px;
          font-size: 14px;
          cursor: pointer;
          transition: background-color 0.2s;
          text-decoration: none;
          display: inline-block;
          text-align: center;
          font-weight: 500;
      }
      
      .btn-primary {
          background-color: var(--primary-color);
          color: white;
      }
      
      .btn-secondary {
          background-color: var(--light-bg);
          color: var(--text-dark);
      }
      
      .btn-danger {
          background-color: var(--danger-color);
          color: white;
      }
      
      .btn-primary:hover {
          background-color: var(--primary-hover);
      }
      
      .btn-secondary:hover {
          background-color: #e0e0e0;
      }
      
      .btn-danger:hover {
          background-color: var(--danger-hover);
      }
      
      .error-list {
          background-color: rgba(231, 76, 60, 0.1);
          color: var(--danger-color);
          padding: 15px;
          border-radius: 6px;
          margin-bottom: 20px;
          font-size: 14px;
          border-left: 4px solid var(--danger-color);
      }
      
      .error-list ul {
          margin: 0;
          padding-left: 20px;
      }
      
      .error-list li {
          margin-bottom: 5px;
      }
      
      textarea.form-control {
          min-height: 120px;
          resize: vertical;
      }
      
      .photo-upload-container {
          margin-bottom: 20px;
      }
      
      .current-photo {
          display: flex;
          align-items: center;
          margin-bottom: 15px;
      }
      
      .photo-preview {
          width: 100px;
          height: 100px;
          border-radius: 50%;
          object-fit: cover;
          margin-right: 20px;
          background-color: var(--light-bg);
          display: flex;
          align-items: center;
          justify-content: center;
          overflow: hidden;
      }
      
      .photo-preview img {
          width: 100%;
          height: 100%;
          object-fit: cover;
      }
      
      .photo-preview i {
          font-size: 36px;
          color: var(--text-muted);
      }
      
      .photo-actions {
          display: flex;
          flex-direction: column;
          gap: 10px;
      }
      
      .photo-input-container {
          position: relative;
          overflow: hidden;
          display: inline-block;
      }
      
      .photo-input-container input[type=file] {
          position: absolute;
          font-size: 100px;
          opacity: 0;
          right: 0;
          top: 0;
          cursor: pointer;
      }
      
      .photo-requirements {
          font-size: 12px;
          color: var(--text-muted);
          margin-top: 8px;
      }
      
      .remove-photo-checkbox {
          display: flex;
          align-items: center;
          margin-top: 10px;
      }
      
      .remove-photo-checkbox input {
          margin-right: 8px;
      }
      
      .remove-photo-checkbox label {
          font-size: 14px;
          color: var(--text-dark);
      }
      
      #photo-preview-container {
          margin-top: 15px;
          display: none;
      }
      
      #photo-preview-container img {
          max-width: 200px;
          max-height: 200px;
          border-radius: 4px;
          border: 1px solid var(--border-color);
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
                  <li class="active"><a href="trainers.php"><i class="fas fa-user-tie"></i> Trainers</a></li>
                  <li><a href="#"><i class="fas fa-calendar-alt"></i> Classes</a></li>
                  <li><a href="#"><i class="fas fa-dumbbell"></i> Equipment</a></li>
                  <li><a href="#"><i class="fas fa-cog"></i> Settings</a></li>
                  <li><a href="<?php echo isset($_SESSION['base_path']) ? $_SESSION['base_path'] : ''; ?>/admin/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
              </ul>
          </div>
      </div>
      
      <!-- Main Content -->
      <div class="main-content">
          <div class="header">
              <div class="page-title">
                  <h1>Edit Trainer</h1>
              </div>
              <div class="user-info">
                  <span><?php echo htmlspecialchars($admin['name']); ?></span>
              </div>
          </div>
          
          <!-- Dashboard Content -->
          <div class="dashboard-content">
              <div class="form-container">
                  <h2 class="form-title">Edit Trainer: <?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></h2>
                  
                  <?php if (!empty($errors)): ?>
                      <div class="error-list">
                          <ul>
                              <?php foreach ($errors as $error): ?>
                                  <li><?php echo $error; ?></li>
                              <?php endforeach; ?>
                          </ul>
                      </div>
                  <?php endif; ?>
                  
                  <?php if (isset($success_message)): ?>
                      <div class="success-message" style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                          <?php echo $success_message; ?>
                      </div>
                  <?php endif; ?>
                  
                  <?php if (!empty($directory_info)): ?>
                      <div class="debug-info" style="background-color: #f8f9fa; border-left: 4px solid #6c757d; padding: 15px; margin-bottom: 20px; font-size: 14px; color: #6c757d;">
                          <h4 style="margin-top: 0; color: #343a40;">Upload Directory Information</h4>
                          <ul style="margin-bottom: 0; padding-left: 20px;">
                              <?php foreach ($directory_info as $info): ?>
                                  <li><?php echo $info; ?></li>
                              <?php endforeach; ?>
                          </ul>
                          <p style="margin-top: 15px; margin-bottom: 0;">
                              <strong>Recommendation:</strong> Create the directories manually and set permissions to 777:
                              <br>
                              <code>mkdir -p ../../uploads/trainer_photos</code>
                              <br>
                              <code>chmod -R 777 ../../uploads</code>
                          </p>
                      </div>
                  <?php endif; ?>
                  
                  <form method="POST" action="edit_trainer.php?id=<?php echo $trainer_id; ?>" enctype="multipart/form-data">
                      <div class="photo-upload-container">
                          <label class="form-label">Profile Photo</label>
                          <div class="current-photo">
                              <div class="photo-preview">
                                  <?php if (!empty($profile_photo) && file_exists('../../' . $profile_photo)): ?>
                                      <img src="../../<?php echo htmlspecialchars($profile_photo); ?>" alt="<?php echo htmlspecialchars($first_name . ' ' . $last_name); ?>">
                                  <?php else: ?>
                                      <i class="fas fa-user-tie"></i>
                                  <?php endif; ?>
                              </div>
                              <div class="photo-actions">
                                  <div class="photo-input-container">
                                      <button type="button" class="btn btn-secondary">
                                          <i class="fas fa-upload"></i> Choose Photo
                                      </button>
                                      <input type="file" name="profile_photo" id="profile_photo" accept="image/jpeg, image/png, image/gif">
                                  </div>
                                  <div class="photo-requirements">
                                      Recommended: Square image, at least 300x300 pixels.<br>
                                      Max size: 5MB. Formats: JPG, PNG, GIF
                                  </div>
                                  <?php if (!empty($profile_photo)): ?>
                                      <div class="remove-photo-checkbox">
                                          <input type="checkbox" id="remove_photo" name="remove_photo" value="1">
                                          <label for="remove_photo">Remove current photo</label>
                                      </div>
                                  <?php endif; ?>
                              </div>
                          </div>
                          <div id="photo-preview-container">
                              <p>New photo preview:</p>
                              <img id="photo-preview" src="#" alt="Preview">
                          </div>
                      </div>
                      
                      <div class="form-row">
                          <div class="form-col">
                              <div class="form-group">
                                  <label for="first_name" class="form-label">First Name *</label>
                                  <input type="text" id="first_name" name="first_name" class="form-control" value="<?php echo htmlspecialchars($first_name); ?>" required>
                              </div>
                          </div>
                          <div class="form-col">
                              <div class="form-group">
                                  <label for="last_name" class="form-label">Last Name *</label>
                                  <input type="text" id="last_name" name="last_name" class="form-control" value="<?php echo htmlspecialchars($last_name); ?>" required>
                              </div>
                          </div>
                      </div>
                      
                      <div class="form-row">
                          <div class="form-col">
                              <div class="form-group">
                                  <label for="email" class="form-label">Email *</label>
                                  <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required>
                              </div>
                          </div>
                          <div class="form-col">
                              <div class="form-group">
                                  <label for="phone" class="form-label">Phone *</label>
                                  <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($phone); ?>" required>
                              </div>
                          </div>
                      </div>
                      
                      <div class="form-row">
                          <div class="form-col">
                              <div class="form-group">
                                  <label for="specialization" class="form-label">Specialization</label>
                                  <input type="text" id="specialization" name="specialization" class="form-control" value="<?php echo htmlspecialchars($specialization); ?>" placeholder="e.g., Yoga, Weight Training, Cardio">
                              </div>
                          </div>
                          <div class="form-col">
                              <div class="form-group">
                                  <label for="status" class="form-label">Status</label>
                                  <select id="status" name="status" class="form-control">
                                      <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                      <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                      <option value="on_leave" <?php echo $status === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                                  </select>
                              </div>
                          </div>
                      </div>
                      
                      <div class="form-group">
                          <label for="bio" class="form-label">Bio</label>
                          <textarea id="bio" name="bio" class="form-control" placeholder="Enter trainer bio and qualifications..."><?php echo htmlspecialchars($bio); ?></textarea>
                      </div>
                      
                      <div class="form-actions">
                          <a href="trainers.php" class="btn btn-secondary">Cancel</a>
                          <button type="submit" class="btn btn-primary">Update Trainer</button>
                      </div>
                  </form>
              </div>
          </div>
      </div>
  </div>
  
  <script>
      // Preview uploaded image
      document.getElementById('profile_photo').addEventListener('change', function(e) {
          const file = e.target.files[0];
          if (file) {
              const reader = new FileReader();
              reader.onload = function(e) {
                  document.getElementById('photo-preview').src = e.target.result;
                  document.getElementById('photo-preview-container').style.display = 'block';
              }
              reader.readAsDataURL(file);
              
              // Uncheck remove photo if checked
              const removePhotoCheckbox = document.getElementById('remove_photo');
              if (removePhotoCheckbox) {
                  removePhotoCheckbox.checked = false;
              }
          }
      });
      
      // Handle remove photo checkbox
      const removePhotoCheckbox = document.getElementById('remove_photo');
      if (removePhotoCheckbox) {
          removePhotoCheckbox.addEventListener('change', function() {
              if (this.checked) {
                  document.getElementById('profile_photo').value = '';
                  document.getElementById('photo-preview-container').style.display = 'none';
              }
          });
      }
      
      // Add direct AJAX upload functionality
      document.addEventListener('DOMContentLoaded', function() {
          const fileInput = document.getElementById('profile_photo');
          const form = document.querySelector('form');
          
          if (fileInput && form) {
              fileInput.addEventListener('change', function() {
                  if (this.files.length > 0) {
                      const file = this.files[0];
                      
                      // Validate file size and type
                      const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                      const maxSize = 5 * 1024 * 1024; // 5MB
                      
                      if (!allowedTypes.includes(file.type)) {
                          alert('Invalid file type. Only JPG, PNG, and GIF files are allowed.');
                          this.value = '';
                          return;
                      }
                      
                      if (file.size > maxSize) {
                          alert('File size exceeds the maximum limit of 5MB.');
                          this.value = '';
                          return;
                      }
                      
                      // Show loading indicator
                      let loadingIndicator = document.getElementById('upload-loading');
                      if (!loadingIndicator) {
                          loadingIndicator = document.createElement('div');
                          loadingIndicator.id = 'upload-loading';
                          loadingIndicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading photo...';
                          loadingIndicator.style.marginTop = '10px';
                          loadingIndicator.style.color = '#3498db';
                          
                          const photoPreviewContainer = document.getElementById('photo-preview-container');
                          if (photoPreviewContainer) {
                              photoPreviewContainer.parentNode.insertBefore(loadingIndicator, photoPreviewContainer.nextSibling);
                          }
                      }
                      
                      // Create FormData for AJAX upload
                      const formData = new FormData();
                      formData.append('profile_photo', file);
                      formData.append('ajax_upload', '1');
                      formData.append('trainer_id', <?php echo $trainer_id; ?>);
                      
                      // Use Fetch API for upload
                      fetch(window.location.href, {
                          method: 'POST',
                          body: formData
                      })
                      .then(response => response.json())
                      .then(data => {
                          if (loadingIndicator) loadingIndicator.remove();
                          
                          if (data.success) {
                              // Update preview with the new image path
                              const previewImg = document.querySelector('.photo-preview img');
                              if (previewImg) {
                                  previewImg.src = '../../' + data.file_path;
                              } else {
                                  const photoPreview = document.querySelector('.photo-preview');
                                  if (photoPreview) {
                                      photoPreview.innerHTML = '<img src="../../' + data.file_path + '" alt="Trainer Photo">';
                                  }
                              }
                              
                              // Add success message
                              const successMsg = document.createElement('div');
                              successMsg.className = 'success-message';
                              successMsg.style.backgroundColor = '#d4edda';
                              successMsg.style.color = '#155724';
                              successMsg.style.padding = '10px';
                              successMsg.style.marginTop = '10px';
                              successMsg.style.borderRadius = '4px';
                              successMsg.innerHTML = 'Photo uploaded successfully!';
                              
                              const photoActions = document.querySelector('.photo-actions');
                              if (photoActions) {
                                  photoActions.appendChild(successMsg);
                                  setTimeout(() => {
                                      successMsg.remove();
                                  }, 3000);
                              }
                              
                              // Add a hidden input with the file path
                              let hiddenInput = document.getElementById('uploaded_photo_path');
                              if (!hiddenInput) {
                                  hiddenInput = document.createElement('input');
                                  hiddenInput.type = 'hidden';
                                  hiddenInput.id = 'uploaded_photo_path';
                                  hiddenInput.name = 'uploaded_photo_path';
                                  form.appendChild(hiddenInput);
                              }
                              hiddenInput.value = data.file_path;
                          } else {
                              // Show error message
                              alert('Upload failed: ' + data.message);
                          }
                      })
                      .catch(error => {
                          if (loadingIndicator) loadingIndicator.remove();
                          console.error('Error:', error);
                          alert('An error occurred during upload. Please try again.');
                      });
                  }
              });
          }
      });
  </script>
</body>
</html>

