<?php
// Start session
session_start();

// Include database connection
require_once '../../includes/db_connect.php';

// Check if user is logged in and is a super admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'super_admin') {
    // Redirect to login page if not logged in or not a super admin
    header("Location: ../login.php");
    exit();
}

// Get admin ID from URL
$admin_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Validate admin exists and is a branch admin
$stmt = $conn->prepare("SELECT id, name, email FROM admins WHERE id = ? AND role = 'branch_admin'");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = "Invalid branch admin selected.";
    header("Location: dashboard.php");
    exit();
}

$admin = $result->fetch_assoc();

// Get all features
$stmt = $conn->prepare("SELECT id, name, code, description FROM features WHERE is_active = 1");
$stmt->execute();
$features = $stmt->get_result();

// Get admin's current features
$stmt = $conn->prepare("SELECT feature_id FROM admin_features WHERE admin_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

$admin_features = [];
while ($row = $result->fetch_assoc()) {
    $admin_features[] = $row['feature_id'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Delete all existing permissions for this admin
        $stmt = $conn->prepare("DELETE FROM admin_features WHERE admin_id = ?");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        
        // Insert new permissions
        if (isset($_POST['features']) && is_array($_POST['features'])) {
            $stmt = $conn->prepare("INSERT INTO admin_features (admin_id, feature_id) VALUES (?, ?)");
            
            foreach ($_POST['features'] as $feature_id) {
                $stmt->bind_param("ii", $admin_id, $feature_id);
                $stmt->execute();
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Permissions updated successfully for " . htmlspecialchars($admin['name']);
        header("Location: dashboard.php");
        exit();
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error updating permissions: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Permissions - Gym Network</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .permissions-container {
            background-color: #fff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .permission-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .permission-item:last-child {
            border-bottom: none;
        }
        
        .permission-info {
            flex: 1;
        }
        
        .permission-name {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .permission-description {
            color: #666;
            font-size: 14px;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #3498db;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .actions-row {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }
        
        .select-all-btn {
            background: none;
            border: none;
            color: #3498db;
            cursor: pointer;
            font-size: 14px;
            padding: 0;
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
                    <li class="active"><a href="#"><i class="fas fa-users"></i> Branch Admins</a></li>
                    <li><a href="#"><i class="fas fa-dumbbell"></i> Branches</a></li>
                    <li><a href="#"><i class="fas fa-user-friends"></i> Customers</a></li>
                    <li><a href="#"><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="<?php echo isset($_SESSION['base_path']) ? $_SESSION['base_path'] : ''; ?>/admin/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="page-title">
                    <h1>Manage Permissions</h1>
                </div>
                <div class="user-info">
                    <span>Admin: <?php echo htmlspecialchars($admin['name']); ?></span>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="permissions-container">
                    <h2>Feature Access for <?php echo htmlspecialchars($admin['name']); ?></h2>
                    <p>Enable or disable access to specific features for this branch admin.</p>
                    
                    <form method="post" action="">
                        <?php if ($features->num_rows > 0): ?>
                            <?php while ($feature = $features->fetch_assoc()): ?>
                                <div class="permission-item">
                                    <div class="permission-info">
                                        <div class="permission-name"><?php echo htmlspecialchars($feature['name']); ?></div>
                                        <div class="permission-description"><?php echo htmlspecialchars($feature['description']); ?></div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="features[]" value="<?php echo $feature['id']; ?>" 
                                            <?php echo in_array($feature['id'], $admin_features) ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p>No features available.</p>
                        <?php endif; ?>
                        
                        <div class="actions-row">
                            <div>
                                <button type="button" class="select-all-btn" id="selectAllBtn">Select All</button>
                                <button type="button" class="select-all-btn" id="deselectAllBtn">Deselect All</button>
                            </div>
                            <div>
                                <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('selectAllBtn').addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        });
        
        document.getElementById('deselectAllBtn').addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
        });
    </script>
</body>
</html>

