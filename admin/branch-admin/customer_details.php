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

// Check if customer ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
  $_SESSION['customer_message'] = "No customer selected";
  $_SESSION['customer_message_type'] = "error";
  header("Location: customers.php");
  exit();
}

$customer_id = intval($_GET['id']);

// Get customer data
$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? AND branch = ?");
$stmt->bind_param("is", $customer_id, $admin['branch_name']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
  $_SESSION['customer_message'] = "Customer not found or doesn't belong to your branch";
  $_SESSION['customer_message_type'] = "error";
  header("Location: customers.php");
  exit();
}

$customer = $result->fetch_assoc();

// Get membership info
$stmt = $conn->prepare("SELECT * FROM memberships WHERE customer_id = ? ORDER BY end_date DESC LIMIT 1");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$membership_result = $stmt->get_result();
$membership = $membership_result->fetch_assoc();

// If no membership found, create default values
if (!$membership) {
  $membership = [
    'membership_type' => $customer['subscription_type'] ?? 'monthly',
    'status' => 'Active',
    'start_date' => date('Y-m-d'),
    'end_date' => date('Y-m-d', strtotime('+1 month'))
  ];
  
  // Adjust end date based on subscription type
  if ($membership['membership_type'] === 'six_months') {
    $membership['end_date'] = date('Y-m-d', strtotime('+6 months'));
  } elseif ($membership['membership_type'] === 'yearly') {
    $membership['end_date'] = date('Y-m-d', strtotime('+1 year'));
  }
}

// Format subscription type for display
$subscription_types = [
  'monthly' => 'Monthly',
  'six_months' => '6 Months',
  'yearly' => 'Yearly'
];

$subscription_text = $subscription_types[$customer['subscription_type'] ?? 'monthly'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Details - Gym Network</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    .customer-profile {
      display: flex;
      gap: 30px;
      margin-bottom: 30px;
    }
    
    .profile-sidebar {
      flex: 1;
      max-width: 300px;
    }
    
    .profile-content {
      flex: 2;
    }
    
    .profile-card {
      background-color: #fff;
      border-radius: 8px;
      padding: 20px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      margin-bottom: 20px;
    }
    
    .profile-header {
      text-align: center;
      margin-bottom: 20px;
    }
    
    .profile-avatar {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      background-color: #f0f0f0;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 15px;
      color: #777;
      font-size: 40px;
    }
    
    .profile-name {
      font-size: 20px;
      font-weight: 600;
      margin-bottom: 5px;
    }
    
    .profile-email {
      color: #777;
      font-size: 14px;
    }
    
    .profile-actions {
      display: flex;
      justify-content: center;
      gap: 10px;
      margin-top: 15px;
    }
    
    .profile-btn {
      padding: 8px 15px;
      border-radius: 4px;
      font-size: 14px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: none;
    }
    
    .profile-btn.edit {
      background-color: #f0f8ff;
      color: #4caf50;
    }
    
    .profile-btn.delete {
      background-color: #fff5f5;
      color: #f44336;
    }
    
    .profile-btn i {
      margin-right: 5px;
    }
    
    .info-section {
      margin-bottom: 20px;
    }
    
    .info-section h3 {
      font-size: 16px;
      margin-bottom: 10px;
      color: #555;
      border-bottom: 1px solid #eee;
      padding-bottom: 5px;
    }
    
    .info-item {
      display: flex;
      margin-bottom: 10px;
    }
    
    .info-label {
      width: 120px;
      color: #777;
      font-size: 14px;
    }
    
    .info-value {
      flex: 1;
      font-weight: 500;
    }
    
    .subscription-badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 600;
    }
    
    .subscription-monthly {
      background-color: #e3f2fd;
      color: #1976d2;
    }
    
    .subscription-six_months {
      background-color: #e8f5e9;
      color: #388e3c;
    }
    
    .subscription-yearly {
      background-color: #fff8e1;
      color: #f57c00;
    }
    
    .tab-navigation {
      display: flex;
      border-bottom: 1px solid #eee;
      margin-bottom: 20px;
    }
    
    .tab-item {
      padding: 10px 20px;
      cursor: pointer;
      border-bottom: 2px solid transparent;
      font-weight: 500;
    }
    
    .tab-item.active {
      border-bottom-color: #ff6b45;
      color: #ff6b45;
    }
    
    .tab-content {
      display: none;
    }
    
    .tab-content.active {
      display: block;
    }
    
    .activity-item {
      display: flex;
      align-items: center;
      padding: 15px;
      border-radius: 8px;
      background-color: #f9f9f9;
      margin-bottom: 10px;
    }
    
    .activity-icon {
      width: 40px;
      height: 40px;
      border-radius: 8px;
      background-color: #f0f8ff;
      color: #ff6b45;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      margin-right: 15px;
    }
    
    .activity-details {
      flex: 1;
    }
    
    .activity-text {
      color: #333;
      margin-bottom: 5px;
    }
    
    .activity-time {
      color: #777;
      font-size: 12px;
    }
    
    .membership-card {
      background-color: #fff;
      border-radius: 8px;
      padding: 20px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      margin-bottom: 20px;
      border-top: 4px solid #ff6b45;
    }
    
    .membership-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px;
    }
    
    .membership-title {
      font-size: 18px;
      font-weight: 600;
    }
    
    .membership-status {
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 600;
      background-color: #e8f5e9;
      color: #388e3c;
    }
    
    .membership-status.inactive {
      background-color: #ffebee;
      color: #d32f2f;
    }
    
    .membership-details {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
    }
    
    .membership-detail {
      flex: 1;
      min-width: 120px;
    }
    
    .detail-label {
      font-size: 12px;
      color: #777;
      margin-bottom: 5px;
    }
    
    .detail-value {
      font-size: 16px;
      font-weight: 500;
    }
    
    .back-btn {
      display: inline-flex;
      align-items: center;
      color: #555;
      text-decoration: none;
      margin-bottom: 20px;
    }
    
    .back-btn i {
      margin-right: 5px;
    }
    
    @media (max-width: 768px) {
      .customer-profile {
        flex-direction: column;
      }
      
      .profile-sidebar {
        max-width: 100%;
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
                  <li class="active"><a href="customers.php"><i class="fas fa-user-friends"></i> Customers</a></li>
                  <li><a href="#"><i class="fas fa-calendar-alt"></i> Classes</a></li>
                  <li><a href="#"><i class="fas fa-dumbbell"></i> Equipment</a></li>
                  <li><a href="#"><i class="fas fa-cog"></i> Settings</a></li>
                  <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
              </ul>
          </div>
      </div>
      
      <!-- Main Content -->
      <div class="main-content">
          <div class="header">
              <div class="page-title">
                  <h1>Customer Details</h1>
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
              <a href="customers.php" class="back-btn">
                  <i class="fas fa-arrow-left"></i> Back to Customers
              </a>
              
              <div class="customer-profile">
                  <div class="profile-sidebar">
                      <div class="profile-card">
                          <div class="profile-header">
                              <div class="profile-avatar">
                                  <?php echo strtoupper(substr($customer['first_name'], 0, 1)); ?>
                              </div>
                              <h2 class="profile-name"><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></h2>
                              <p class="profile-email"><?php echo htmlspecialchars($customer['email']); ?></p>
                              
                              <div class="profile-actions">
                                  <button class="profile-btn edit" onclick="editCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars(addslashes($customer['first_name'])); ?>', '<?php echo htmlspecialchars(addslashes($customer['last_name'])); ?>', '<?php echo htmlspecialchars(addslashes($customer['email'])); ?>', '<?php echo htmlspecialchars(addslashes($customer['phone'])); ?>', '<?php echo htmlspecialchars(addslashes($customer['address'] ?? '')); ?>', '<?php echo $customer['subscription_type'] ?? 'monthly'; ?>', '<?php echo $customer['fitness_goal'] ?? ''; ?>', '<?php echo $customer['weight'] ?? ''; ?>')">
                                      <i class="fas fa-edit"></i> Edit
                                  </button>
                                  <button class="profile-btn delete" onclick="confirmDelete(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars(addslashes($customer['first_name'] . ' ' . $customer['last_name'])); ?>')">
                                      <i class="fas fa-trash"></i> Delete
                                  </button>
                              </div>
                          </div>
                          
                          <div class="info-section">
                              <h3>Contact Information</h3>
                              <div class="info-item">
                                  <div class="info-label">Phone:</div>
                                  <div class="info-value"><?php echo htmlspecialchars($customer['phone']); ?></div>
                              </div>
                              <div class="info-item">
                                  <div class="info-label">Email:</div>
                                  <div class="info-value"><?php echo htmlspecialchars($customer['email']); ?></div>
                              </div>
                              <div class="info-item">
                                  <div class="info-label">Address:</div>
                                  <div class="info-value"><?php echo htmlspecialchars($customer['address'] ?? 'Not provided'); ?></div>
                              </div>
                          </div>
                          
                          <div class="info-section">
                              <h3>Membership</h3>
                              <div class="info-item">
                                  <div class="info-label">Branch:</div>
                                  <div class="info-value"><?php echo htmlspecialchars($customer['branch']); ?></div>
                              </div>
                              <div class="info-item">
                                  <div class="info-label">Subscription:</div>
                                  <div class="info-value">
                                      <span class="subscription-badge subscription-<?php echo $customer['subscription_type'] ?? 'monthly'; ?>">
                                          <?php echo $subscription_text; ?>
                                      </span>
                                  </div>
                              </div>
                              <div class="info-item">
                                  <div class="info-label">Status:</div>
                                  <div class="info-value"><?php echo $membership['status']; ?></div>
                              </div>
                              <div class="info-item">
                                  <div class="info-label">Expiry Date:</div>
                                  <div class="info-value"><?php echo date('M d, Y', strtotime($membership['end_date'])); ?></div>
                              </div>
                          </div>
                      </div>
                  </div>
                  
                  <div class="profile-content">
                      <div class="tab-navigation">
                          <div class="tab-item active" onclick="showTab('overview')">Overview</div>
                          <div class="tab-item" onclick="showTab('activity')">Activity</div>
                          <div class="tab-item" onclick="showTab('membership')">Membership</div>
                      </div>
                      
                      <div id="overview" class="tab-content active">
                          <div class="profile-card">
                              <div class="info-section">
                                  <h3>Personal Information</h3>
                                  <div class="info-item">
                                      <div class="info-label">Full Name:</div>
                                      <div class="info-value"><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></div>
                                  </div>
                                  <div class="info-item">
                                      <div class="info-label">Fitness Goal:</div>
                                      <div class="info-value"><?php echo ucwords(str_replace('_', ' ', $customer['fitness_goal'] ?? 'Not specified')); ?></div>
                                  </div>
                                  <div class="info-item">
                                      <div class="info-label">Weight:</div>
                                      <div class="info-value"><?php echo $customer['weight'] ? htmlspecialchars($customer['weight']) . ' kg' : 'Not specified'; ?></div>
                                  </div>
                                  <div class="info-item">
                                      <div class="info-label">Joined:</div>
                                      <div class="info-value"><?php echo date('M d, Y', strtotime($customer['created_at'])); ?></div>
                                  </div>
                              </div>
                          </div>
                          
                          <div class="profile-card">
                              <div class="info-section">
                                  <h3>Customer QR Code</h3>
                                  <div style="text-align: center; margin: 20px 0;">
                                      <div style="border: 1px solid #ddd; padding: 15px; border-radius: 8px; display: inline-block; background-color: white;">
                                          <img id="customer-qr-code" src="/placeholder.svg" alt="Customer QR Code" style="max-width: 200px; height: auto;">
                                          <p style="margin-top: 10px; font-weight: bold;"><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></p>
                                          <p style="margin-top: 5px; font-size: 12px; color: #777;">ID: <?php echo $customer['id']; ?></p>
                                      </div>
                                      <p style="margin-top: 15px; font-size: 14px; color: #555;">
                                          <i class="fas fa-info-circle"></i> Scan this QR code at the reception desk for quick check-in
                                      </p>
                                      <button onclick="printQRCode()" style="margin-top: 10px; padding: 8px 15px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">
                                          <i class="fas fa-print"></i> Print QR Code
                                      </button>
                                      <button onclick="generateQRCode()" style="margin-top: 10px; margin-left: 10px; padding: 8px 15px; background-color: #e8f5e9; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; color: #388e3c;">
                                          <i class="fas fa-sync-alt"></i> Regenerate QR Code
                                      </button>
                                  </div>
                              </div>
                          </div>
                          
                          <div class="profile-card">
                              <div class="info-section">
                                  <h3>Fitness Summary</h3>
                                  <div class="info-item">
                                      <div class="info-label">Total Workouts:</div>
                                      <div class="info-value">0</div>
                                  </div>
                                  <div class="info-item">
                                      <div class="info-label">Classes Attended:</div>
                                      <div class="info-value">0</div>
                                  </div>
                                  <div class="info-item">
                                      <div class="info-label">Last Workout:</div>
                                      <div class="info-value">N/A</div>
                                  </div>
                              </div>
                          </div>
                      </div>
                      
                      <div id="activity" class="tab-content">
                          <div class="profile-card">
                              <div class="info-section">
                                  <h3>Recent Activity</h3>
                                  
                                  <div class="activity-item">
                                      <div class="activity-icon">
                                          <i class="fas fa-user-plus"></i>
                                      </div>
                                      <div class="activity-details">
                                          <p class="activity-text">Account created</p>
                                          <p class="activity-time"><?php echo date('M d, Y', strtotime($customer['created_at'])); ?></p>
                                      </div>
                                  </div>
                                  
                                  <div class="activity-item">
                                      <div class="activity-icon">
                                          <i class="fas fa-credit-card"></i>
                                      </div>
                                      <div class="activity-details">
                                          <p class="activity-text">Membership activated</p>
                                          <p class="activity-time"><?php echo date('M d, Y', strtotime($membership['start_date'])); ?></p>
                                      </div>
                                  </div>
                                  
                                  <p style="text-align: center; color: #777; margin-top: 20px;">No more activity to show</p>
                              </div>
                          </div>
                      </div>
                      
                      <div id="membership" class="tab-content">
                          <div class="membership-card">
                              <div class="membership-header">
                                  <h3 class="membership-title"><?php echo $subscription_text; ?> Membership</h3>
                                  <span class="membership-status"><?php echo $membership['status']; ?></span>
                              </div>
                              
                              <div class="membership-details">
                                  <div class="membership-detail">
                                      <div class="detail-label">Start Date</div>
                                      <div class="detail-value"><?php echo date('M d, Y', strtotime($membership['start_date'])); ?></div>
                                  </div>
                                  
                                  <div class="membership-detail">
                                      <div class="detail-label">End Date</div>
                                      <div class="detail-value"><?php echo date('M d, Y', strtotime($membership['end_date'])); ?></div>
                                  </div>
                                  
                                  <div class="membership-detail">
                                      <div class="detail-label">Duration</div>
                                      <div class="detail-value"><?php echo $subscription_text; ?></div>
                                  </div>
                              </div>
                          </div>
                          
                          <div class="profile-card">
                              <div class="info-section">
                                  <h3>Membership History</h3>
                                  <p style="text-align: center; color: #777; margin-top: 20px;">No previous memberships found</p>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>
          </div>
      </div>
  </div>

  <!-- Edit Customer Modal -->
  <div id="editCustomerModal" class="modal">
      <div class="modal-content">
          <span class="close" onclick="closeEditCustomerModal()">&times;</span>
          <h2>Edit Customer</h2>
          <form id="editCustomerForm" action="edit_customer.php" method="post">
              <input type="hidden" id="edit_customer_id" name="customer_id">
              
              <div class="form-row">
                  <div class="form-group half">
                      <label for="edit_first_name">First Name</label>
                      <input type="text" id="edit_first_name" name="first_name" required>
                  </div>
                  <div class="form-group half">
                      <label for="edit_last_name">Last Name</label>
                      <input type="text" id="edit_last_name" name="last_name" required>
                  </div>
              </div>
              
              <div class="form-group">
                  <label for="edit_email">Email</label>
                  <input type="email" id="edit_email" name="email" required>
              </div>
              
              <div class="form-group">
                  <label for="edit_phone">Phone Number</label>
                  <input type="tel" id="edit_phone" name="phone" required>
              </div>
              
              <div class="form-group">
                  <label for="edit_address">Address</label>
                  <input type="text" id="edit_address" name="address">
              </div>
              
              <div class="form-row">
                  <div class="form-group half">
                      <label for="edit_subscription_type">Subscription Type</label>
                      <select id="edit_subscription_type" name="subscription_type" required>
                          <option value="monthly">Monthly</option>
                          <option value="six_months">6 Months</option>
                          <option value="yearly">Yearly</option>
                      </select>
                  </div>
                  
                  <div class="form-group half">
                      <label for="edit_fitness_goal">Fitness Goal</label>
                      <select id="edit_fitness_goal" name="fitness_goal">
                          <option value="">Select a goal</option>
                          <option value="weight_loss">Weight Loss</option>
                          <option value="muscle_gain">Muscle Gain</option>
                          <option value="endurance">Endurance</option>
                          <option value="flexibility">Flexibility</option>
                          <option value="general_fitness">General Fitness</option>
                      </select>
                  </div>
              </div>
              
              <div class="form-group">
                  <label for="edit_weight">Weight (kg)</label>
                  <input type="number" id="edit_weight" name="weight" step="0.01" min="0">
              </div>
              
              <div class="form-group">
                  <label for="edit_password">New Password (leave blank to keep current)</label>
                  <div class="password-container">
                      <input type="password" id="edit_password" name="password">
                      <span class="toggle-password" onclick="togglePasswordVisibility('edit_password')">
                          <i class="fas fa-eye"></i>
                      </span>
                  </div>
                  <p class="password-hint">Must be at least 8 characters</p>
              </div>
              
              <!-- Hidden field for branch -->
              <input type="hidden" name="branch" value="<?php echo htmlspecialchars($admin['branch_name']); ?>">
              
              <button type="submit" class="submit-btn">Update Customer</button>
          </form>
      </div>
  </div>

   Delete Confirmation Modal -->
  <div id="deleteConfirmModal" class="modal">
      <div class="modal-content" style="max-width: 400px;">
          <span class="close" onclick="closeDeleteConfirmModal()">&times;</span>
          <h2>Confirm Delete</h2>
          <p id="deleteConfirmText">Are you sure you want to delete this customer?</p>
          <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
              <button onclick="closeDeleteConfirmModal()" style="padding: 8px 15px; background-color: #f5f5f5; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
              <a id="deleteCustomerLink" href="#" class="submit-btn" style="padding: 8px 15px; text-decoration: none; display: inline-block; text-align: center;">Delete</a>
          </div>
      </div>
  </div>

  <script>
      // Tab navigation
      function showTab(tabId) {
          // Hide all tabs
          const tabContents = document.querySelectorAll('.tab-content');
          tabContents.forEach(tab => {
              tab.classList.remove('active');
          });
          
          // Remove active class from all tab items
          const tabItems = document.querySelectorAll('.tab-item');
          tabItems.forEach(item => {
              item.classList.remove('active');
          });
          
          // Show selected tab
          document.getElementById(tabId).classList.add('active');
          
          // Add active class to clicked tab item
          document.querySelector(`.tab-item[onclick="showTab('${tabId}')"]`).classList.add('active');
      }
      
      // Modal functions
      function editCustomer(id, firstName, lastName, email, phone, address, subscription, goal, weight) {
          document.getElementById('edit_customer_id').value = id;
          document.getElementById('edit_first_name').value = firstName;
          document.getElementById('edit_last_name').value = lastName;
          document.getElementById('edit_email').value = email;
          document.getElementById('edit_phone').value = phone;
          document.getElementById('edit_address').value = address;
          document.getElementById('edit_subscription_type').value = subscription;
          document.getElementById('edit_fitness_goal').value = goal;
          document.getElementById('edit_weight').value = weight;
          document.getElementById('edit_password').value = '';
          
          document.getElementById('editCustomerModal').style.display = 'block';
      }
      
      function closeEditCustomerModal() {
          document.getElementById('editCustomerModal').style.display = 'none';
      }
      
      function confirmDelete(id, name) {
          document.getElementById('deleteConfirmText').innerText = `Are you sure you want to delete ${name}?`;
          document.getElementById('deleteCustomerLink').href = `customers.php?delete=${id}`;
          document.getElementById('deleteConfirmModal').style.display = 'block';
      }
      
      function closeDeleteConfirmModal() {
          document.getElementById('deleteConfirmModal').style.display = 'none';
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
      
      // Close modal when clicking outside of it
      window.onclick = function(event) {
          if (event.target.classList.contains('modal')) {
              event.target.style.display = 'none';
          }
      }

      // Generate and display QR code for this customer
      function generateQRCode() {
          const customerId = <?php echo $customer['id']; ?>;
          const customerName = "<?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>";
          const qrData = `GYM_CUSTOMER_ID:${customerId}`;
          
          // Use a more reliable QR code generation service
          const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(qrData)}&margin=10`;
          
          // Set the QR code image source
          const qrImage = document.getElementById('customer-qr-code');
          qrImage.src = qrCodeUrl;
          qrImage.alt = `${customerName} QR Code`;
      }

      // Print QR code
function printQRCode() {
    const customerId = <?php echo $customer['id']; ?>;
    const customerName = "<?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>";
    const branchName = "<?php echo htmlspecialchars($admin['branch_name']); ?>";
    const qrCodeImg = document.getElementById('customer-qr-code').src;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Gym Membership QR Code - ${customerName}</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    text-align: center; 
                    padding: 20px;
                    margin: 0;
                }
                .qr-container { 
                    margin: 0 auto; 
                    max-width: 400px; 
                    padding: 20px;
                    border: 2px solid #ddd;
                    border-radius: 10px;
                }
                .logo {
                    font-size: 24px;
                    font-weight: bold;
                    margin-bottom: 5px;
                    color: #ff6b45;
                }
                .branch {
                    font-size: 14px;
                    color: #555;
                    margin-bottom: 20px;
                }
                img { 
                    max-width: 100%;
                    border: 1px solid #eee;
                    padding: 10px;
                    background: white;
                }
                h2 { 
                    margin-bottom: 5px; 
                    color: #333;
                }
                .member-id {
                    font-size: 14px;
                    color: #777;
                    margin-bottom: 20px;
                }
                .instructions {
                    font-size: 14px;
                    color: #555;
                    margin-top: 20px;
                    padding: 10px;
                    background-color: #f9f9f9;
                    border-radius: 5px;
                }
                @media print {
                    .no-print {
                        display: none;
                    }
                }
            </style>
        </head>
        <body>
            <div class="qr-container">
                <div class="logo">Gym Network</div>
                <div class="branch">${branchName} Branch</div>
                <h2>${customerName}</h2>
                <div class="member-id">Member ID: ${customerId}</div>
                <img src="${qrCodeImg}" alt="Customer QR Code">
                <div class="instructions">
                    <p>Scan this code at the reception desk for quick check-in</p>
                </div>
            </div>
            <div class="no-print" style="margin-top: 20px;">
                <button onclick="window.print()" style="padding: 10px 20px; background-color: #ff6b45; color: white; border: none; border-radius: 4px; cursor: pointer;">
                    Print QR Code
                </button>
                <button onclick="window.close()" style="padding: 10px 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; margin-left: 10px; cursor: pointer;">
                    Close
                </button>
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
}
      // Initialize QR code on page load
      document.addEventListener('DOMContentLoaded', function() {
          generateQRCode();
      });
  </script>
</body>
</html>

