<?php
// Add debug information for upload directories
// Add this code at the beginning of the PHP section, after the session_start()

// Start session
session_start();

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

// Try to create directories on page load
$create_result = createUploadDirectories();
$errors = [];
if (isset($create_result) && !$create_result['success']) {
    foreach ($create_result['messages'] as $message) {
        $errors[] = $message;
    }
}

// Get directory information
$directory_info = checkUploadDirectories();

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

// Initialize variables
$first_name = '';
$last_name = '';
$email = '';
$phone = '';
$specialization = '';
$bio = '';
$status = 'active';

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
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM trainers WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = "Email already in use by another trainer";
        }
    }
    
    if (empty($phone)) {
        $errors[] = "Phone number is required";
    }
    
    // Handle profile photo upload (REQUIRED)
    $profile_photo = null;
    
    if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Profile photo is required";
    } else {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($_FILES['profile_photo']['type'], $allowed_types)) {
            $errors[] = "Invalid file type. Only JPG, PNG, and GIF files are allowed.";
        } elseif ($_FILES['profile_photo']['size'] > $max_size) {
            $errors[] = "File size exceeds the maximum limit of 5MB.";
        } else {
            // Create uploads directory structure
            $base_upload_dir = '../../uploads';
            $trainer_photos_dir = $base_upload_dir . '/trainer_photos';
            
            // First check if base uploads directory exists
            if (!file_exists($base_upload_dir)) {
                // Try to create the base uploads directory
                if (!@mkdir($base_upload_dir, 0777, true)) {
                    $errors[] = "Failed to create base upload directory. Please create the directory manually: " . $base_upload_dir;
                } else {
                    @chmod($base_upload_dir, 0777); // Set permissions
                }
            }
            
            // Then check if trainer_photos directory exists
            if (!file_exists($trainer_photos_dir) && empty($errors)) {
                // Try to create the trainer_photos directory
                if (!@mkdir($trainer_photos_dir, 0777, true)) {
                    $errors[] = "Failed to create trainer photos directory. Please create the directory manually: " . $trainer_photos_dir;
                } else {
                    @chmod($trainer_photos_dir, 0777); // Set permissions
                }
            }
            
            // Check if directories are writable
            if (empty($errors)) {
                if (!is_writable($base_upload_dir)) {
                    // Try to set permissions
                    @chmod($base_upload_dir, 0777);
                    if (!is_writable($base_upload_dir)) {
                        $errors[] = "Upload base directory is not writable. Please set permissions to 777 on: " . $base_upload_dir;
                    }
                }
                
                if (!is_writable($trainer_photos_dir)) {
                    // Try to set permissions
                    @chmod($trainer_photos_dir, 0777);
                    if (!is_writable($trainer_photos_dir)) {
                        $errors[] = "Trainer photos directory is not writable. Please set permissions to 777 on: " . $trainer_photos_dir;
                    }
                }
            }
        }
    }
    
    // If no errors, insert the trainer
    if (empty($errors)) {
        // First insert the trainer to get the ID
        $stmt = $conn->prepare("INSERT INTO trainers (first_name, last_name, email, phone, specialization, branch, bio, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", $first_name, $last_name, $email, $phone, $specialization, $branch_name, $bio, $status);
        
        if ($stmt->execute()) {
            $trainer_id = $conn->insert_id;
            
            // Now upload the photo with the trainer ID
            $file_extension = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
            $new_filename = 'trainer_' . $trainer_id . '_' . time() . '.' . $file_extension;
            $upload_path = $trainer_photos_dir . '/' . $new_filename;
            
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_path)) {
                $profile_photo = 'uploads/trainer_photos/' . $new_filename;
                
                // Update the trainer with the photo path
                $stmt = $conn->prepare("UPDATE trainers SET profile_photo = ? WHERE id = ?");
                $stmt->bind_param("si", $profile_photo, $trainer_id);
                $stmt->execute();
                
                // Redirect to trainers page with success message
                $_SESSION['success_message'] = "Trainer added successfully.";
                header("Location: trainers.php");
                exit();
            } else {
                // If photo upload fails, delete the trainer
                $stmt = $conn->prepare("DELETE FROM trainers WHERE id = ?");
                $stmt->bind_param("i", $trainer_id);
                $stmt->execute();
                
                $upload_error = error_get_last();
                $errors[] = "Failed to upload profile photo. Error: " . ($upload_error ? $upload_error['message'] : 'Unknown error');
            }
        } else {
            $errors[] = "Error adding trainer: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Trainer - <?php echo htmlspecialchars($branch_name); ?></title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .form-container {
            background-color: #fff;
            border-radius: 4px;
            padding: 30px;
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-title {
            font-size: 20px;
            color: #333;
            margin-top: 0;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f5f5f5;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: #333;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 14px;
            color: #333;
            transition: border-color 0.2s;
        }
        
        .form-control:focus {
            border-color: #3498db;
            outline: none;
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
            border-top: 1px solid #f5f5f5;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.2s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-primary {
            background-color: #3498db;
            color: white;
        }
        
        .btn-secondary {
            background-color: #f5f5f5;
            color: #333;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
        }
        
        .btn-secondary:hover {
            background-color: #e0e0e0;
        }
        
        .error-list {
            background-color: #ffebee;
            color: #d32f2f;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .error-list ul {
            margin: 0;
            padding-left: 20px;
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
          background-color: #f5f5f5;
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
          color: #aaa;
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
          color: #777;
          margin-top: 8px;
      }
      
      #photo-preview-container {
          margin-top: 15px;
          display: none;
      }
      
      #photo-preview-container img {
          max-width: 200px;
          max-height: 200px;
          border-radius: 4px;
          border: 1px solid #e0e0e0;
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
                    <h1>Add Trainer</h1>
                </div>
                <div class="user-info">
                    <span><?php echo htmlspecialchars($admin['name']); ?></span>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="form-container">
                    <h2 class="form-title">Add New Trainer</h2>
                    
                    <?php if (!empty($errors)): ?>
                        <div class="error-list">
                            <ul>
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo $error; ?></li>
                              <?php endforeach; ?>
                          </ul>
                      </div>
                  <?php endif; ?>
                  
                  <?php if (!empty($directory_info)): ?>
                    
                  <?php endif; ?>
                    
                    <form method="POST" action="add_trainer.php" enctype="multipart/form-data">
                      <div class="photo-upload-container">
                          <label class="form-label">Profile Photo *</label>
                          <div class="current-photo">
                              <div class="photo-preview">
                                  <i class="fas fa-user-tie"></i>
                              </div>
                              <div class="photo-actions">
                                  <div class="photo-input-container">
                                      <button type="button" class="btn btn-secondary">
                                          <i class="fas fa-upload"></i> Choose Photo
                                      </button>
                                      <input type="file" name="profile_photo" id="profile_photo" accept="image/jpeg, image/png, image/gif" required>
                                  </div>
                                  <div class="photo-requirements">
                                      Recommended: Square image, at least 300x300 pixels.<br>
                                      Max size: 5MB. Formats: JPG, PNG, GIF
                                  </div>
                              </div>
                          </div>
                          <div id="photo-preview-container">
                              <p>Photo preview:</p>
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
                            <button type="submit" class="btn btn-primary">Add Trainer</button>
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
          }
      });
  </script>
</body>
</html>

