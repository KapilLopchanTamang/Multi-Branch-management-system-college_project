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

// Get admin data
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name FROM admins WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

// Get all branch admins
$stmt = $conn->prepare("SELECT a.id, a.name, a.email, a.role, b.name as branch_name 
                        FROM admins a 
                        LEFT JOIN branches b ON a.id = b.admin_id 
                        WHERE a.role = 'branch_admin'");
$stmt->execute();
$branch_admins = $stmt->get_result();

// Get all branches
$stmt = $conn->prepare("SELECT id, name, location, admin_id FROM branches");
$stmt->execute();
$branches = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - Gym Network</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
                    <li class="active"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="#"><i class="fas fa-users"></i> Branch Admins</a></li>
                    <li><a href="#"><i class="fas fa-dumbbell"></i> Branches</a></li>
                    <li><a href="#"><i class="fas fa-user-friends"></i> Customers</a></li>
                    <li><a href="#"><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="page-title">
                    <h1>Super Admin Dashboard</h1>
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
                <!-- Stats Cards -->
                <div class="stats-cards">
                    <div class="card">
                        <div class="card-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="card-info">
                            <h3><?php echo $branch_admins->num_rows; ?></h3>
                            <p>Branch Admins</p>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-icon">
                            <i class="fas fa-dumbbell"></i>
                        </div>
                        <div class="card-info">
                            <h3><?php echo $branches->num_rows; ?></h3>
                            <p>Total Branches</p>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-icon">
                            <i class="fas fa-user-friends"></i>
                        </div>
                        <div class="card-info">
                            <h3>0</h3>
                            <p>Total Customers</p>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="card-info">
                            <h3>0</h3>
                            <p>Active Memberships</p>
                        </div>
                    </div>
                </div>
                
                <!-- Branch Admins Section -->
                <div class="section">
                    <div class="section-header">
                        <h2>Branch Admins</h2>
                        <button class="add-btn" onclick="showAddAdminForm()">
                            <i class="fas fa-plus"></i> Add Branch Admin
                        </button>
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Branch</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($branch_admins->num_rows > 0): ?>
                                    <?php while ($admin = $branch_admins->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($admin['name']); ?></td>
                                            <td><?php echo htmlspecialchars($admin['email']); ?></td>
                                            <td><?php echo $admin['branch_name'] ? htmlspecialchars($admin['branch_name']) : 'Not Assigned'; ?></td>
                                            <td>
                                                <button class="action-btn edit"><i class="fas fa-edit"></i></button>
                                                <button class="action-btn delete"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="no-data">No branch admins found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Branches Section -->
                <div class="section">
                    <div class="section-header">
                        <h2>Branches</h2>
                        <button class="add-btn">
                            <i class="fas fa-plus"></i> Add Branch
                        </button>
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Branch Name</th>
                                    <th>Location</th>
                                    <th>Admin</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($branches->num_rows > 0): ?>
                                    <?php 
                                    // Reset branch admins result pointer
                                    $branch_admins->data_seek(0);
                                    $admins_array = [];
                                    while ($admin = $branch_admins->fetch_assoc()) {
                                        $admins_array[$admin['id']] = $admin['name'];
                                    }
                                    
                                    while ($branch = $branches->fetch_assoc()): 
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($branch['name']); ?></td>
                                            <td><?php echo htmlspecialchars($branch['location']); ?></td>
                                            <td>
                                                <?php 
                                                if ($branch['admin_id'] && isset($admins_array[$branch['admin_id']])) {
                                                    echo htmlspecialchars($admins_array[$branch['admin_id']]);
                                                } else {
                                                    echo 'Not Assigned';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <button class="action-btn edit"><i class="fas fa-edit"></i></button>
                                                <button class="action-btn delete"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="no-data">No branches found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Admin Modal -->
    <div id="addAdminModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Add Branch Admin</h2>
            <form id="addAdminForm" action="add_admin.php" method="post">
                <div class="form-group">
                    <label for="admin_name">Name</label>
                    <input type="text" id="admin_name" name="admin_name" required>
                </div>
                
                <div class="form-group">
                    <label for="admin_email">Email</label>
                    <input type="email" id="admin_email" name="admin_email" required>
                </div>
                
                <div class="form-group">
                    <label for="admin_password">Password</label>
                    <div class="password-container">
                        <input type="password" id="admin_password" name="admin_password" required>
                        <span class="toggle-password" onclick="togglePasswordVisibility('admin_password')">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="admin_branch">Assign Branch</label>
                    <select id="admin_branch" name="admin_branch">
                        <option value="">Select Branch</option>
                        <?php 
                        $branches->data_seek(0);
                        while ($branch = $branches->fetch_assoc()): 
                            if (!$branch['admin_id']):
                        ?>
                            <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                        <?php 
                            endif;
                        endwhile; 
                        ?>
                    </select>
                </div>
                
                <button type="submit" class="submit-btn">Add Admin</button>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function showAddAdminForm() {
            document.getElementById('addAdminModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('addAdminModal').style.display = 'none';
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('addAdminModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
        
        // Toggle password visibility
        function togglePasswordVisibility(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const toggleIcon = document.querySelector(`#${fieldId} + .toggle-password i`);
            
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

