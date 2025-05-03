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
requirePermission('customers', 'dashboard.php');

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

// Store branch info in session if not already set
if (!isset($_SESSION['admin_branch_id']) || !isset($_SESSION['admin_branch_name'])) {
  $_SESSION['admin_branch_id'] = $admin['branch_id'];
  $_SESSION['admin_branch_name'] = $admin['branch_name'];
}

// Get all customers for this branch
$branch_name = $admin['branch_name'];
$stmt = $conn->prepare("SELECT * FROM customers WHERE branch = ? ORDER BY first_name, last_name");
$stmt->bind_param("s", $branch_name);
$stmt->execute();
$customers = $stmt->get_result();

// Check if we need to update the customers table structure
$result = $conn->query("SHOW COLUMNS FROM customers LIKE 'address'");
if ($result->num_rows == 0) {
  // Add missing columns to customers table
  $conn->query("ALTER TABLE customers ADD COLUMN address VARCHAR(255) AFTER last_name");
  $conn->query("ALTER TABLE customers ADD COLUMN subscription_type ENUM('monthly', 'six_months', 'yearly') DEFAULT 'monthly' AFTER branch");
  $conn->query("ALTER TABLE customers ADD COLUMN weight DECIMAL(5,2) AFTER fitness_goal");
}

// Handle delete customer request
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
  $customer_id = intval($_GET['delete']);
  
  // Check if customer belongs to this branch
  $stmt = $conn->prepare("SELECT id FROM customers WHERE id = ? AND branch = ?");
  $stmt->bind_param("is", $customer_id, $branch_name);
  $stmt->execute();
  $result = $stmt->get_result();
  
  if ($result->num_rows > 0) {
    // Delete customer
    $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    
    // Set success message
    $_SESSION['customer_message'] = "Customer deleted successfully!";
    $_SESSION['customer_message_type'] = "success";
  } else {
    // Set error message
    $_SESSION['customer_message'] = "You don't have permission to delete this customer.";
    $_SESSION['customer_message_type'] = "error";
  }
  
  // Redirect to avoid resubmission
  header("Location: customers.php");
  exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Customers - Gym Network</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    .filter-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }
    
    .search-box {
      display: flex;
      align-items: center;
      background-color: #f5f5f5;
      border-radius: 4px;
      padding: 0 15px;
      width: 300px;
    }
    
    .search-box input {
      border: none;
      background: transparent;
      padding: 10px;
      width: 100%;
      outline: none;
    }
    
    .search-box i {
      color: #777;
    }
    
    .filter-dropdown {
      padding: 10px;
      border: 1px solid #ddd;
      border-radius: 4px;
      background-color: #fff;
    }
    
    .customer-details {
      display: flex;
      align-items: center;
    }
    
    .customer-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background-color: #f0f0f0;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 10px;
      color: #777;
      font-size: 16px;
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
    
    .modal {
      z-index: 1050;
    }
    
    .modal-content {
      width: 600px;
      max-width: 90%;
    }
    
    .form-row {
      display: flex;
      gap: 15px;
      margin-bottom: 0;
    }
    
    .form-group.half {
      flex: 1;
    }
    
    .action-btn {
      width: auto;
      height: auto;
      padding: 6px 12px;
      margin-right: 5px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
      cursor: pointer;
      transition: background-color 0.3s;
    }
    
    .action-btn.edit {
      background-color: #f0f8ff;
      color: #4caf50;
    }
    
    .action-btn.delete {
      background-color: #fff5f5;
      color: #f44336;
    }
    
    .action-btn.view {
      background-color: #f5f5f5;
      color: #2196f3;
    }
    
    .action-btn i {
      margin-right: 5px;
    }
    
    .pagination {
      display: flex;
      justify-content: center;
      margin-top: 20px;
    }
    
    .pagination a {
      display: inline-block;
      padding: 8px 12px;
      margin: 0 5px;
      border-radius: 4px;
      background-color: #f5f5f5;
      color: #333;
      text-decoration: none;
    }
    
    .pagination a.active {
      background-color: #ff6b45;
      color: white;
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
                  <h1>Manage Customers</h1>
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
              <?php if (isset($_SESSION['customer_message'])): ?>
                  <div class="<?php echo $_SESSION['customer_message_type'] === 'success' ? 'success-message' : 'error-message'; ?>">
                      <?php echo $_SESSION['customer_message']; ?>
                  </div>
                  <?php unset($_SESSION['customer_message'], $_SESSION['customer_message_type']); ?>
              <?php endif; ?>
              
              <!-- Customers Section -->
              <div class="section">
                  <div class="section-header">
                      <h2>Customers at <?php echo htmlspecialchars($branch_name); ?></h2>
                      <button class="add-btn" onclick="showAddCustomerModal()">
                          <i class="fas fa-plus"></i> Add Customer
                      </button>
                  </div>
                  
                  <div class="filter-row">
                      <div class="search-box">
                          <i class="fas fa-search"></i>
                          <input type="text" id="customerSearch" placeholder="Search customers..." onkeyup="filterCustomers()">
                      </div>
                      
                      <div>
                          <select class="filter-dropdown" id="subscriptionFilter" onchange="filterCustomers()">
                              <option value="">All Subscriptions</option>
                              <option value="monthly">Monthly</option>
                              <option value="six_months">6 Months</option>
                              <option value="yearly">Yearly</option>
                          </select>
                          
                          <select class="filter-dropdown" id="goalFilter" onchange="filterCustomers()">
                              <option value="">All Goals</option>
                              <option value="weight_loss">Weight Loss</option>
                              <option value="muscle_gain">Muscle Gain</option>
                              <option value="endurance">Endurance</option>
                              <option value="flexibility">Flexibility</option>
                              <option value="general_fitness">General Fitness</option>
                          </select>
                      </div>
                  </div>
                  
                  <div class="table-container">
                      <table id="customersTable">
                          <thead>
                              <tr>
                                  <th>Customer</th>
                                  <th>Contact</th>
                                  <th>Address</th>
                                  <th>Subscription</th>
                                  <th>Goal</th>
                                  <th>Weight</th>
                                  <th>Actions</th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php if ($customers->num_rows > 0): ?>
                                  <?php while ($customer = $customers->fetch_assoc()): ?>
                                      <tr>
                                          <td>
                                              <div class="customer-details">
                                                  <div class="customer-avatar">
                                                      <?php echo strtoupper(substr($customer['first_name'], 0, 1)); ?>
                                                  </div>
                                                  <div>
                                                      <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                                                  </div>
                                              </div>
                                          </td>
                                          <td>
                                              <div><?php echo htmlspecialchars($customer['email']); ?></div>
                                              <div><?php echo htmlspecialchars($customer['phone']); ?></div>
                                          </td>
                                          <td><?php echo htmlspecialchars($customer['address'] ?? 'Not provided'); ?></td>
                                          <td>
                                              <?php 
                                              $subscription = $customer['subscription_type'] ?? 'monthly';
                                              $subscription_text = [
                                                  'monthly' => 'Monthly',
                                                  'six_months' => '6 Months',
                                                  'yearly' => 'Yearly'
                                              ][$subscription];
                                              ?>
                                              <span class="subscription-badge subscription-<?php echo $subscription; ?>">
                                                  <?php echo $subscription_text; ?>
                                              </span>
                                          </td>
                                          <td><?php echo ucwords(str_replace('_', ' ', $customer['fitness_goal'] ?? 'Not specified')); ?></td>
                                          <td><?php echo $customer['weight'] ? htmlspecialchars($customer['weight']) . ' kg' : 'Not specified'; ?></td>
                                          <td>
                                              <button class="action-btn view" onclick="viewCustomer(<?php echo $customer['id']; ?>)">
                                                  <i class="fas fa-eye"></i> View
                                              </button>
                                              <button class="action-btn edit" onclick="editCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars(addslashes($customer['first_name'])); ?>', '<?php echo htmlspecialchars(addslashes($customer['last_name'])); ?>', '<?php echo htmlspecialchars(addslashes($customer['email'])); ?>', '<?php echo htmlspecialchars(addslashes($customer['phone'])); ?>', '<?php echo htmlspecialchars(addslashes($customer['address'] ?? '')); ?>', '<?php echo $subscription; ?>', '<?php echo $customer['fitness_goal'] ?? ''; ?>', '<?php echo $customer['weight'] ?? ''; ?>')">
                                                  <i class="fas fa-edit"></i> Edit
                                              </button>
                                              <button class="action-btn delete" onclick="confirmDelete(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars(addslashes($customer['first_name'] . ' ' . $customer['last_name'])); ?>')">
                                                  <i class="fas fa-trash"></i> Delete
                                              </button>
                                          </td>
                                      </tr>
                                  <?php endwhile; ?>
                              <?php else: ?>
                                  <tr>
                                      <td colspan="7" class="no-data">No customers found</td>
                                  </tr>
                              <?php endif; ?>
                          </tbody>
                      </table>
                  </div>
                  
                  <div class="pagination">
                      <a href="#" class="active">1</a>
                      <a href="#">2</a>
                      <a href="#">3</a>
                      <a href="#">Next</a>
                  </div>
              </div>
          </div>
      </div>
  </div>

  <!-- Add Customer Modal -->
  <div id="addCustomerModal" class="modal">
      <div class="modal-content">
          <span class="close" onclick="closeAddCustomerModal()">&times;</span>
          <h2>Add New Customer</h2>
          <form id="addCustomerForm" action="add_customer.php" method="post">
              <div class="form-row">
                  <div class="form-group half">
                      <label for="first_name">First Name</label>
                      <input type="text" id="first_name" name="first_name" required>
                  </div>
                  <div class="form-group half">
                      <label for="last_name">Last Name</label>
                      <input type="text" id="last_name" name="last_name" required>
                  </div>
              </div>
              
              <div class="form-group">
                  <label for="email">Email</label>
                  <input type="email" id="email" name="email" required>
              </div>
              
              <div class="form-group">
                  <label for="phone">Phone Number</label>
                  <input type="tel" id="phone" name="phone" required>
              </div>
              
              <div class="form-group">
                  <label for="address">Address</label>
                  <input type="text" id="address" name="address">
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
              
              <div class="form-row">
                  <div class="form-group half">
                      <label for="subscription_type">Subscription Type</label>
                      <select id="subscription_type" name="subscription_type" required>
                          <option value="monthly">Monthly</option>
                          <option value="six_months">6 Months</option>
                          <option value="yearly">Yearly</option>
                      </select>
                  </div>
                  
                  <div class="form-group half">
                      <label for="fitness_goal">Fitness Goal</label>
                      <select id="fitness_goal" name="fitness_goal">
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
                  <label for="weight">Weight (kg)</label>
                  <input type="number" id="weight" name="weight" step="0.01" min="0">
              </div>
              
              <!-- Hidden field for branch -->
              <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch_name); ?>">
              
              <button type="submit" class="submit-btn">Add Customer</button>
          </form>
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
              <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch_name); ?>">
              
              <button type="submit" class="submit-btn">Update Customer</button>
          </form>
      </div>
  </div>

  <!-- Delete Confirmation Modal -->
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
      // Modal functions
      function showAddCustomerModal() {
          document.getElementById('addCustomerModal').style.display = 'block';
      }
      
      function closeAddCustomerModal() {
          document.getElementById('addCustomerModal').style.display = 'none';
      }
      
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
      
      function viewCustomer(id) {
          // Redirect to customer details page
          window.location.href = `customer_details.php?id=${id}`;
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
      
      // Filter customers
      function filterCustomers() {
          const searchInput = document.getElementById('customerSearch').value.toLowerCase();
          const subscriptionFilter = document.getElementById('subscriptionFilter').value;
          const goalFilter = document.getElementById('goalFilter').value;
          
          const table = document.getElementById('customersTable');
          const rows = table.getElementsByTagName('tr');
          
          // Start from index 1 to skip the header row
          for (let i = 1; i < rows.length; i++) {
              const row = rows[i];
              
              // Skip the "No customers found" row
              if (row.cells.length === 1 && row.cells[0].classList.contains('no-data')) {
                  continue;
              }
              
              const customerName = row.cells[0].textContent.toLowerCase();
              const contactInfo = row.cells[1].textContent.toLowerCase();
              const address = row.cells[2].textContent.toLowerCase();
              
              // Get subscription from the badge class
              const subscriptionBadge = row.cells[3].querySelector('.subscription-badge');
              const subscription = subscriptionBadge ? subscriptionBadge.classList[1].replace('subscription-', '') : '';
              
              const goal = row.cells[4].textContent.toLowerCase();
              
              // Check if row matches all filters
              const matchesSearch = customerName.includes(searchInput) || 
                                   contactInfo.includes(searchInput) || 
                                   address.includes(searchInput);
              
              const matchesSubscription = subscriptionFilter === '' || subscription === subscriptionFilter;
              
              const matchesGoal = goalFilter === '' || goal.includes(goalFilter.replace('_', ' '));
              
              // Show/hide row based on filter matches
              if (matchesSearch && matchesSubscription && matchesGoal) {
                  row.style.display = '';
              } else {
                  row.style.display = 'none';
              }
          }
      }
      
      // Close modal when clicking outside of it
      window.onclick = function(event) {
          if (event.target.classList.contains('modal')) {
              event.target.style.display = 'none';
          }
      }
  </script>

<!-- Add this script at the end of the file, before the closing </body> tag -->
<script>
    // Check for URL parameters on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Get URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action');
        const id = urlParams.get('id');
        
        // If action is 'add', show add customer modal
        if (action === 'add') {
            showAddCustomerModal();
        }
        
        // If action is 'edit' and id is provided, fetch customer data and show edit modal
        if (action === 'edit' && id) {
            // Fetch customer data via AJAX or use data attributes
            // For now, we'll just show the edit modal if it exists
            const editModal = document.getElementById('editCustomerModal');
            if (editModal) {
                // Set customer ID
                document.getElementById('edit_customer_id').value = id;
                
                // Show modal
                editModal.style.display = 'block';
            }
        }
    });
</script>
</body>
</html>

