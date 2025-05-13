<?php
// Start session
session_start();

// Include database connection and auth functions
require_once '../../includes/db_connect.php';
require_once '../includes/auth_functions.php';

// Check if user is logged in and is a branch admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'branch_admin') {
    // Redirect to login page if not logged in or not a branch admin
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to access this page
requirePermission('trainers', 'dashboard.php');

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

// Process search query
$search_query = '';
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_query = trim($_GET['search']);
}

// Process status filter
$status_filter = '';
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = trim($_GET['status']);
}

// Get trainers for this branch with search and filter
try {
    $query = "SELECT * FROM trainers WHERE branch = ?";
    $params = [$branch_name];
    $types = "s";
    
    if (!empty($search_query)) {
        $query .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ? OR specialization LIKE ?)";
        $search_param = "%$search_query%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
        $types .= "sssss";
    }
    
    if (!empty($status_filter)) {
        $query .= " AND status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }
    
    $query .= " ORDER BY first_name, last_name";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $trainers = [];
    
    while ($row = $result->fetch_assoc()) {
        $trainers[] = $row;
    }
} catch (Exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Check for success or error messages
$success_message = '';
$error_message = '';

if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Trainers - <?php echo htmlspecialchars($branch_name); ?></title>
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
      
      .dashboard-content {
          padding: 20px;
      }
      
      .page-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 20px;
      }
      
      .page-title {
          font-size: 24px;
          color: var(--text-dark);
          margin: 0;
          font-weight: 600;
      }
      
      .add-trainer-btn {
          background-color: var(--primary-color);
          color: white;
          padding: 10px 16px;
          border-radius: 6px;
          text-decoration: none;
          font-size: 14px;
          font-weight: 500;
          display: inline-flex;
          align-items: center;
          transition: background-color 0.2s;
      }
      
      .add-trainer-btn i {
          margin-right: 8px;
      }
      
      .add-trainer-btn:hover {
          background-color: var(--primary-hover);
      }
      
      .filters-row {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 20px;
          background-color: #fff;
          padding: 15px;
          border-radius: 8px;
          box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      }
      
      .search-box {
          flex: 1;
          max-width: 400px;
          position: relative;
      }
      
      .search-box input {
          width: 100%;
          padding: 10px 12px 10px 36px;
          border: 1px solid var(--border-color);
          border-radius: 6px;
          font-size: 14px;
          color: var(--text-dark);
          transition: border-color 0.2s, box-shadow 0.2s;
      }
      
      .search-box input:focus {
          border-color: var(--primary-color);
          outline: none;
          box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
      }
      
      .search-box i {
          position: absolute;
          left: 12px;
          top: 50%;
          transform: translateY(-50%);
          color: var(--text-muted);
      }
      
      .filter-options {
          display: flex;
          gap: 15px;
      }
      
      .filter-select {
          padding: 10px 12px;
          border: 1px solid var(--border-color);
          border-radius: 6px;
          font-size: 14px;
          color: var(--text-dark);
          background-color: white;
          cursor: pointer;
          transition: border-color 0.2s, box-shadow 0.2s;
      }
      
      .filter-select:focus {
          border-color: var(--primary-color);
          outline: none;
          box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
      }
      
      .trainers-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
          gap: 20px;
      }
      
      .trainer-card {
          background-color: #fff;
          border-radius: 8px;
          overflow: hidden;
          box-shadow: 0 2px 10px rgba(0,0,0,0.05);
          transition: transform 0.2s, box-shadow 0.2s;
      }
      
      .trainer-card:hover {
          transform: translateY(-5px);
          box-shadow: 0 5px 15px rgba(0,0,0,0.1);
      }
      
      .trainer-header {
          padding: 20px;
          display: flex;
          align-items: center;
          border-bottom: 1px solid var(--border-color);
      }
      
      .trainer-avatar {
          width: 60px;
          height: 60px;
          border-radius: 50%;
          background-color: var(--primary-color);
          display: flex;
          align-items: center;
          justify-content: center;
          margin-right: 15px;
          color: white;
          overflow: hidden;
      }
      
      .trainer-avatar img {
          width: 100%;
          height: 100%;
          object-fit: cover;
      }
      
      .trainer-avatar i {
          font-size: 24px;
      }
      
      .trainer-name {
          flex: 1;
      }
      
      .trainer-name h3 {
          font-size: 18px;
          color: var(--text-dark);
          margin: 0 0 5px 0;
          font-weight: 600;
      }
      
      .trainer-title {
          font-size: 14px;
          color: var(--text-muted);
          margin: 0;
      }
      
      .trainer-body {
          padding: 20px;
      }
      
      .trainer-info {
          margin-bottom: 15px;
      }
      
      .info-item {
          display: flex;
          align-items: center;
          margin-bottom: 10px;
      }
      
      .info-item:last-child {
          margin-bottom: 0;
      }
      
      .info-item i {
          width: 16px;
          margin-right: 10px;
          color: var(--primary-color);
      }
      
      .info-text {
          font-size: 14px;
          color: var(--text-dark);
      }
      
      .trainer-status {
          font-size: 12px;
          padding: 4px 10px;
          border-radius: 20px;
          font-weight: 500;
          display: inline-block;
          margin-bottom: 15px;
      }
      
      .status-active {
          background-color: rgba(46, 204, 113, 0.15);
          color: var(--secondary-color);
      }
      
      .status-inactive {
          background-color: rgba(108, 117, 125, 0.15);
          color: var(--text-muted);
      }
      
      .status-on_leave {
          background-color: rgba(243, 156, 18, 0.15);
          color: var(--warning-color);
      }
      
      .trainer-actions {
          display: flex;
          gap: 10px;
      }
      
      .action-btn {
          flex: 1;
          padding: 8px 0;
          border-radius: 6px;
          font-size: 13px;
          text-align: center;
          text-decoration: none;
          transition: background-color 0.2s;
          font-weight: 500;
      }
      
      .btn-view {
          background-color: rgba(52, 152, 219, 0.1);
          color: var(--primary-color);
      }
      
      .btn-view:hover {
          background-color: rgba(52, 152, 219, 0.2);
      }
      
      .btn-edit {
          background-color: rgba(46, 204, 113, 0.1);
          color: var(--secondary-color);
      }
      
      .btn-edit:hover {
          background-color: rgba(46, 204, 113, 0.2);
      }
      
      .btn-schedule {
          background-color: rgba(243, 156, 18, 0.1);
          color: var(--warning-color);
      }
      
      .btn-schedule:hover {
          background-color: rgba(243, 156, 18, 0.2);
      }
      
      .empty-state {
          text-align: center;
          padding: 40px 20px;
          background-color: #fff;
          border-radius: 8px;
          box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      }
      
      .empty-state i {
          font-size: 48px;
          color: var(--text-muted);
          margin-bottom: 15px;
          opacity: 0.7;
      }
      
      .empty-state h3 {
          font-size: 18px;
          color: var(--text-dark);
          margin: 0 0 10px 0;
          font-weight: 500;
      }
      
      .empty-state p {
          font-size: 14px;
          color: var(--text-muted);
          margin-bottom: 20px;
          max-width: 400px;
          margin-left: auto;
          margin-right: auto;
      }
      
      .alert {
          padding: 15px;
          border-radius: 8px;
          margin-bottom: 20px;
          font-size: 14px;
          display: flex;
          align-items: center;
      }
      
      .alert i {
          margin-right: 10px;
          font-size: 18px;
      }
      
      .alert-success {
          background-color: rgba(46, 204, 113, 0.15);
          color: var(--secondary-color);
          border-left: 4px solid var(--secondary-color);
      }
      
      .alert-error {
          background-color: rgba(231, 76, 60, 0.15);
          color: var(--danger-color);
          border-left: 4px solid var(--danger-color);
      }
      
      /* Responsive adjustments */
      @media (max-width: 768px) {
          .filters-row {
              flex-direction: column;
              align-items: stretch;
              gap: 15px;
          }
          
          .search-box {
              max-width: none;
          }
          
          .filter-options {
              justify-content: space-between;
          }
          
          .trainers-grid {
              grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
          }
      }
      
      @media (max-width: 576px) {
          .trainers-grid {
              grid-template-columns: 1fr;
          }
          
          .page-header {
              flex-direction: column;
              align-items: flex-start;
              gap: 15px;
          }
          
          .add-trainer-btn {
              width: 100%;
              justify-content: center;
          }
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
                  <h1>Trainers</h1>
              </div>
              <div class="user-info">
                  <span><?php echo htmlspecialchars($admin['name']); ?></span>
              </div>
          </div>
          
          <!-- Dashboard Content -->
          <div class="dashboard-content">
              <?php if (!empty($success_message)): ?>
                  <div class="alert alert-success">
                      <i class="fas fa-check-circle"></i>
                      <?php echo $success_message; ?>
                  </div>
              <?php endif; ?>
              
              <?php if (!empty($error_message)): ?>
                  <div class="alert alert-error">
                      <i class="fas fa-exclamation-circle"></i>
                      <?php echo $error_message; ?>
                  </div>
              <?php endif; ?>
              
              <div class="page-header">
                  <h2 class="page-title">Manage Trainers</h2>
                  <a href="add_trainer.php" class="add-trainer-btn">
                      <i class="fas fa-plus"></i> Add New Trainer
                  </a>
              </div>
              
              <div class="filters-row">
                  <form action="" method="GET" class="search-box">
                      <i class="fas fa-search"></i>
                      <input type="text" name="search" placeholder="Search trainers..." value="<?php echo htmlspecialchars($search_query); ?>">
                  </form>
                  
                  <div class="filter-options">
                      <form action="" method="GET" id="statusFilterForm">
                          <?php if (!empty($search_query)): ?>
                              <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
                          <?php endif; ?>
                          
                          <select name="status" class="filter-select" onchange="document.getElementById('statusFilterForm').submit()">
                              <option value="">All Statuses</option>
                              <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                              <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                              <option value="on_leave" <?php echo $status_filter === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                          </select>
                      </form>
                  </div>
              </div>
              
              <?php if (count($trainers) > 0): ?>
                  <div class="trainers-grid">
                      <?php foreach ($trainers as $trainer): ?>
                          <div class="trainer-card">
                              <div class="trainer-header">
                                  <div class="trainer-avatar">
                                      <?php if (!empty($trainer['profile_photo']) && file_exists('../../' . $trainer['profile_photo'])): ?>
                                          <img src="../../<?php echo htmlspecialchars($trainer['profile_photo']); ?>" alt="<?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?>">
                                      <?php else: ?>
                                          <i class="fas fa-user-tie"></i>
                                      <?php endif; ?>
                                  </div>
                                  <div class="trainer-name">
                                      <h3><?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?></h3>
                                      <p class="trainer-title"><?php echo !empty($trainer['specialization']) ? htmlspecialchars($trainer['specialization']) : 'Fitness Trainer'; ?></p>
                                  </div>
                              </div>
                              <div class="trainer-body">
                                  <span class="trainer-status status-<?php echo $trainer['status']; ?>">
                                      <?php 
                                          $status_text = ucfirst($trainer['status']);
                                          if ($trainer['status'] === 'on_leave') {
                                              $status_text = 'On Leave';
                                          }
                                          echo $status_text;
                                      ?>
                                  </span>
                                  
                                  <div class="trainer-info">
                                      <div class="info-item">
                                          <i class="fas fa-envelope"></i>
                                          <span class="info-text"><?php echo htmlspecialchars($trainer['email']); ?></span>
                                      </div>
                                      <div class="info-item">
                                          <i class="fas fa-phone"></i>
                                          <span class="info-text"><?php echo htmlspecialchars($trainer['phone']); ?></span>
                                      </div>
                                  </div>
                                  
                                  <div class="trainer-actions">
                                      <a href="trainer_details.php?id=<?php echo $trainer['id']; ?>" class="action-btn btn-view">
                                          <i class="fas fa-eye"></i> View
                                      </a>
                                      <a href="edit_trainer.php?id=<?php echo $trainer['id']; ?>" class="action-btn btn-edit">
                                          <i class="fas fa-edit"></i> Edit
                                      </a>
                                      <a href="trainer_schedule.php?trainer_id=<?php echo $trainer['id']; ?>" class="action-btn btn-schedule">
                                          <i class="fas fa-calendar"></i> Schedule
                                      </a>
                                  </div>
                              </div>
                          </div>
                      <?php endforeach; ?>
                  </div>
              <?php else: ?>
                  <div class="empty-state">
                      <i class="fas fa-user-tie"></i>
                      <h3>No Trainers Found</h3>
                      <?php if (!empty($search_query) || !empty($status_filter)): ?>
                          <p>No trainers match your search criteria. Try adjusting your filters or search query.</p>
                          <a href="trainers.php" class="add-trainer-btn">
                              <i class="fas fa-undo"></i> Clear Filters
                          </a>
                      <?php else: ?>
                          <p>You haven't added any trainers to your branch yet. Get started by adding your first trainer.</p>
                          <a href="add_trainer.php" class="add-trainer-btn">
                              <i class="fas fa-plus"></i> Add New Trainer
                          </a>
                      <?php endif; ?>
                  </div>
              <?php endif; ?>
          </div>
      </div>
  </div>
</body>
</html>

