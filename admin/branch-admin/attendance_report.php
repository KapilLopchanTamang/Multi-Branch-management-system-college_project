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

$branch_name = $admin['branch_name'];

// Get report parameters
$report_type = isset($_GET['type']) ? $_GET['type'] : 'daily';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

// Prepare query based on report type
$query = "";
$params = [];
$types = "";

if ($report_type === 'daily') {
    $query = "SELECT DATE(a.check_in) as date, COUNT(*) as total_visits, 
              COUNT(DISTINCT a.customer_id) as unique_visitors,
              AVG(TIMESTAMPDIFF(MINUTE, a.check_in, IFNULL(a.check_out, NOW()))) as avg_duration
              FROM attendance a 
              WHERE a.branch = ? 
              AND DATE(a.check_in) BETWEEN ? AND ?";
    $params = [$branch_name, $start_date, $end_date];
    $types = "sss";
    
    if ($customer_id > 0) {
        $query .= " AND a.customer_id = ?";
        $params[] = $customer_id;
        $types .= "i";
    }
    
    $query .= " GROUP BY DATE(a.check_in) ORDER BY DATE(a.check_in) DESC";
} elseif ($report_type === 'weekly') {
    $query = "SELECT YEARWEEK(a.check_in, 1) as week, 
              MIN(DATE(a.check_in)) as week_start,
              MAX(DATE(a.check_in)) as week_end,
              COUNT(*) as total_visits, 
              COUNT(DISTINCT a.customer_id) as unique_visitors,
              AVG(TIMESTAMPDIFF(MINUTE, a.check_in, IFNULL(a.check_out, NOW()))) as avg_duration
              FROM attendance a 
              WHERE a.branch = ? 
              AND DATE(a.check_in) BETWEEN ? AND ?";
    $params = [$branch_name, $start_date, $end_date];
    $types = "sss";
    
    if ($customer_id > 0) {
        $query .= " AND a.customer_id = ?";
        $params[] = $customer_id;
        $types .= "i";
    }
    
    $query .= " GROUP BY YEARWEEK(a.check_in, 1) ORDER BY YEARWEEK(a.check_in, 1) DESC";
} elseif ($report_type === 'monthly') {
    $query = "SELECT YEAR(a.check_in) as year, MONTH(a.check_in) as month, 
              DATE_FORMAT(a.check_in, '%M %Y') as month_name,
              COUNT(*) as total_visits, 
              COUNT(DISTINCT a.customer_id) as unique_visitors,
              AVG(TIMESTAMPDIFF(MINUTE, a.check_in, IFNULL(a.check_out, NOW()))) as avg_duration
              FROM attendance a 
              WHERE a.branch = ? 
              AND DATE(a.check_in) BETWEEN ? AND ?";
    $params = [$branch_name, $start_date, $end_date];
    $types = "sss";
    
    if ($customer_id > 0) {
        $query .= " AND a.customer_id = ?";
        $params[] = $customer_id;
        $types .= "i";
    }
    
    $query .= " GROUP BY YEAR(a.check_in), MONTH(a.check_in) ORDER BY YEAR(a.check_in) DESC, MONTH(a.check_in) DESC";
} elseif ($report_type === 'customer') {
    $query = "SELECT c.id, c.first_name, c.last_name, c.email,
              COUNT(a.id) as total_visits,
              MIN(a.check_in) as first_visit,
              MAX(a.check_in) as last_visit,
              AVG(TIMESTAMPDIFF(MINUTE, a.check_in, IFNULL(a.check_out, NOW()))) as avg_duration
              FROM customers c
              LEFT JOIN attendance a ON c.id = a.customer_id AND a.branch = ? AND DATE(a.check_in) BETWEEN ? AND ?
              WHERE c.branch = ?";
    $params = [$branch_name, $start_date, $end_date, $branch_name];
    $types = "ssss";
    
    if ($customer_id > 0) {
        $query .= " AND c.id = ?";
        $params[] = $customer_id;
        $types .= "i";
    }
    
    $query .= " GROUP BY c.id ORDER BY total_visits DESC";
}

// Execute query
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$report_data = $stmt->get_result();

// Get all customers for filter dropdown
$stmt = $conn->prepare("SELECT id, first_name, last_name FROM customers WHERE branch = ? ORDER BY first_name, last_name");
$stmt->bind_param("s", $branch_name);
$stmt->execute();
$customers = $stmt->get_result();

// Get summary statistics
$stmt = $conn->prepare("SELECT 
                      COUNT(*) as total_visits,
                      COUNT(DISTINCT customer_id) as unique_visitors,
                      AVG(TIMESTAMPDIFF(MINUTE, check_in, IFNULL(check_out, NOW()))) as avg_duration
                      FROM attendance 
                      WHERE branch = ? AND DATE(check_in) BETWEEN ? AND ?");
$stmt->bind_param("sss", $branch_name, $start_date, $end_date);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

// Get busiest day
$stmt = $conn->prepare("SELECT DATE(check_in) as date, COUNT(*) as visit_count
                      FROM attendance 
                      WHERE branch = ? AND DATE(check_in) BETWEEN ? AND ?
                      GROUP BY DATE(check_in)
                      ORDER BY visit_count DESC
                      LIMIT 1");
$stmt->bind_param("sss", $branch_name, $start_date, $end_date);
$stmt->execute();
$busiest_day = $stmt->get_result()->fetch_assoc();

// Get busiest hour
$stmt = $conn->prepare("SELECT HOUR(check_in) as hour, COUNT(*) as visit_count
                      FROM attendance 
                      WHERE branch = ? AND DATE(check_in) BETWEEN ? AND ?
                      GROUP BY HOUR(check_in)
                      ORDER BY visit_count DESC
                      LIMIT 1");
$stmt->bind_param("sss", $branch_name, $start_date, $end_date);
$stmt->execute();
$busiest_hour = $stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports - Gym Network</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .report-container {
            margin-bottom: 30px;
        }
        
        .report-filters {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .filter-btn {
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .filter-btn.apply {
            background-color: #ff6b45;
            color: white;
        }
        
        .filter-btn.reset {
            background-color: #f5f5f5;
            color: #333;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .summary-card {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .summary-card h3 {
            margin-top: 0;
            margin-bottom: 5px;
            color: #333;
            font-size: 16px;
        }
        
        .summary-value {
            font-size: 24px;
            font-weight: 600;
            color: #ff6b45;
            margin-bottom: 5px;
        }
        
        .summary-label {
            font-size: 14px;
            color: #777;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .report-table th,
        .report-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .report-table th {
            background-color: #f9f9f9;
            color: #555;
            font-weight: 600;
        }
        
        .report-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .chart-container {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            height: 300px;
        }
        
        .chart-placeholder {
            width: 100%;
            height: 100%;
            background-color: #f5f5f5;
            border: 2px dashed #ddd;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .chart-placeholder i {
            font-size: 48px;
            color: #aaa;
            margin-bottom: 10px;
        }
        
        .chart-placeholder p {
            color: #777;
            font-size: 14px;
        }
        
        .export-options {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .export-btn {
            padding: 8px 15px;
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .export-btn:hover {
            background-color: #eee;
        }
        
        @media (max-width: 992px) {
            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }
            
            .export-options {
                flex-direction: column;
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
                    <li class="active"><a href="attendance.php"><i class="fas fa-clipboard-check"></i> Attendance</a></li>
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
                    <h1>Attendance Reports</h1>
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
                <div class="report-container">
                    <!-- Report Filters -->
                    <div class="report-filters">
                        <form action="attendance_report.php" method="get">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="type">Report Type</label>
                                    <select id="type" name="type">
                                        <option value="daily" <?php if ($report_type === 'daily') echo 'selected'; ?>>Daily Report</option>
                                        <option value="weekly" <?php if ($report_type === 'weekly') echo 'selected'; ?>>Weekly Report</option>
                                        <option value="monthly" <?php if ($report_type === 'monthly') echo 'selected'; ?>>Monthly Report</option>
                                        <option value="customer" <?php if ($report_type === 'customer') echo 'selected'; ?>>Customer Report</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="start_date">Start Date</label>
                                    <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="end_date">End Date</label>
                                    <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="customer_id">Customer (Optional)</label>
                                    <select id="customer_id" name="customer_id">
                                        <option value="0">All Customers</option>
                                        <?php while ($customer = $customers->fetch_assoc()): ?>
                                            <option value="<?php echo $customer['id']; ?>" <?php if ($customer_id === $customer['id']) echo 'selected'; ?>>
                                                <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="button" class="filter-btn reset" onclick="resetFilters()">
                                    <i class="fas fa-undo"></i> Reset
                                </button>
                                <button type="submit" class="filter-btn apply">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Export Options -->
                    <div class="export-options">
                        <button class="export-btn" onclick="exportToPDF()">
                            <i class="fas fa-file-pdf"></i> Export to PDF
                        </button>
                        <button class="export-btn" onclick="exportToCSV()">
                            <i class="fas fa-file-csv"></i> Export to CSV
                        </button>
                        <button class="export-btn" onclick="printReport()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="summary-cards">
                        <div class="summary-card">
                            <h3>Total Visits</h3>
                            <div class="summary-value"><?php echo number_format($summary['total_visits']); ?></div>
                            <div class="summary-label">From <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?></div>
                        </div>
                        
                        <div class="summary-card">
                            <h3>Unique Visitors</h3>
                            <div class="summary-value"><?php echo number_format($summary['unique_visitors']); ?></div>
                            <div class="summary-label">Distinct customers</div>
                        </div>
                        
                        <div class="summary-card">
                            <h3>Average Duration</h3>
                            <div class="summary-value"><?php echo floor($summary['avg_duration'] / 60) . 'h ' . ($summary['avg_duration'] % 60) . 'm'; ?></div>
                            <div class="summary-label">Per visit</div>
                        </div>
                        
                        <div class="summary-card">
                            <h3>Busiest Day</h3>
                            <div class="summary-value">
                                <?php 
                                if ($busiest_day) {
                                    echo date('D, M j', strtotime($busiest_day['date']));
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </div>
                            <div class="summary-label">
                                <?php 
                                if ($busiest_day) {
                                    echo $busiest_day['visit_count'] . ' visits';
                                } else {
                                    echo 'No data available';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Chart -->
                    <div class="chart-container">
                        <canvas id="attendanceChart"></canvas>
                    </div>
                    
                    <!-- Report Data -->
                    <div class="section">
                        <div class="section-header">
                            <h2>
                                <?php 
                                if ($report_type === 'daily') echo 'Daily Attendance Report';
                                elseif ($report_type === 'weekly') echo 'Weekly Attendance Report';
                                elseif ($report_type === 'monthly') echo 'Monthly Attendance Report';
                                else echo 'Customer Attendance Report';
                                ?>
                            </h2>
                        </div>
                        
                        <div class="table-container">
                            <table class="report-table" id="reportTable">
                                <thead>
                                    <tr>
                                        <?php if ($report_type === 'daily'): ?>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Total Visits</th>
                                            <th>Unique Visitors</th>
                                            <th>Average Duration</th>
                                        <?php elseif ($report_type === 'weekly'): ?>
                                            <th>Week</th>
                                            <th>Period</th>
                                            <th>Total Visits</th>
                                            <th>Unique Visitors</th>
                                            <th>Average Duration</th>
                                        <?php elseif ($report_type === 'monthly'): ?>
                                            <th>Month</th>
                                            <th>Total Visits</th>
                                            <th>Unique Visitors</th>
                                            <th>Average Duration</th>
                                        <?php else: ?>
                                            <th>Customer</th>
                                            <th>Email</th>
                                            <th>Total Visits</th>
                                            <th>First Visit</th>
                                            <th>Last Visit</th>
                                            <th>Average Duration</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($report_data->num_rows > 0): ?>
                                        <?php while ($row = $report_data->fetch_assoc()): ?>
                                            <tr>
                                                <?php if ($report_type === 'daily'): ?>
                                                    <td><?php echo date('Y-m-d', strtotime($row['date'])); ?></td>
                                                    <td><?php echo date('l', strtotime($row['date'])); ?></td>
                                                    <td><?php echo $row['total_visits']; ?></td>
                                                    <td><?php echo $row['unique_visitors']; ?></td>
                                                    <td><?php echo floor($row['avg_duration'] / 60) . 'h ' . ($row['avg_duration'] % 60) . 'm'; ?></td>
                                                <?php elseif ($report_type === 'weekly'): ?>
                                                    <td>Week <?php echo date('W', strtotime($row['week_start'])); ?></td>
                                                    <td><?php echo date('M j', strtotime($row['week_start'])); ?> - <?php echo date('M j', strtotime($row['week_end'])); ?></td>
                                                    <td><?php echo $row['total_visits']; ?></td>
                                                    <td><?php echo $row['unique_visitors']; ?></td>
                                                    <td><?php echo floor($row['avg_duration'] / 60) . 'h ' . ($row['avg_duration'] % 60) . 'm'; ?></td>
                                                <?php elseif ($report_type === 'monthly'): ?>
                                                    <td><?php echo $row['month_name']; ?></td>
                                                    <td><?php echo $row['total_visits']; ?></td>
                                                    <td><?php echo $row['unique_visitors']; ?></td>
                                                    <td><?php echo floor($row['avg_duration'] / 60) . 'h ' . ($row['avg_duration'] % 60) . 'm'; ?></td>
                                                <?php else: ?>
                                                    <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                                    <td><?php echo $row['total_visits']; ?></td>
                                                    <td><?php echo $row['first_visit'] ? date('M j, Y', strtotime($row['first_visit'])) : 'N/A'; ?></td>
                                                    <td><?php echo $row['last_visit'] ? date('M j, Y', strtotime($row['last_visit'])) : 'N/A'; ?></td>
                                                    <td><?php echo $row['avg_duration'] ? floor($row['avg_duration'] / 60) . 'h ' . ($row['avg_duration'] % 60) . 'm' : 'N/A'; ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="no-data">No data available for the selected criteria</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Reset filters
        function resetFilters() {
            document.getElementById('type').value = 'daily';
            document.getElementById('start_date').value = '<?php echo date('Y-m-d', strtotime('-7 days')); ?>';
            document.getElementById('end_date').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('customer_id').value = '0';
        }
        
        // Export to PDF
        function exportToPDF() {
            alert('PDF export functionality will be implemented here');
            // In a real implementation, you would use a library like jsPDF or make an AJAX call to a server-side PDF generator
        }
        
        // Export to CSV
        function exportToCSV() {
            // Get the table
            const table = document.getElementById('reportTable');
            
            // Create CSV content
            let csv = [];
            
            // Add header row
            const headerRow = [];
            const headers = table.querySelectorAll('th');
            for (let i = 0; i < headers.length; i++) {
                headerRow.push(headers[i].textContent);
            }
            csv.push(headerRow.join(','));
            
            // Add data rows
            const rows = table.querySelectorAll('tbody tr');
            for (let i = 0; i < rows.length; i++) {
                // Skip the "No data available" row
                if (rows[i].cells.length === 1 && rows[i].cells[0].classList.contains('no-data')) {
                    continue;
                }
                
                const row = [];
                const cells = rows[i].querySelectorAll('td');
                for (let j = 0; j < cells.length; j++) {
                    // Get text content and clean it up
                    let cellText = cells[j].textContent.trim().replace(/,/g, ' ');
                    row.push(cellText);
                }
                csv.push(row.join(','));
            }
            
            // Create CSV file
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            
            // Create download link
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', 'attendance_report.csv');
            document.body.appendChild(link);
            
            // Trigger download
            link.click();
            
            // Clean up
            document.body.removeChild(link);
        }
        
        // Print report
        function printReport() {
            window.print();
        }
    </script>
    <script>
    // Prepare chart data based on report type
    const reportType = '<?php echo $report_type; ?>';
    let chartData = {
        labels: [],
        datasets: [{
            label: 'Total Visits',
            data: [],
            backgroundColor: 'rgba(255, 107, 69, 0.5)',
            borderColor: 'rgba(255, 107, 69, 1)',
            borderWidth: 1
        }, {
            label: 'Unique Visitors',
            data: [],
            backgroundColor: 'rgba(54, 162, 235, 0.5)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1
        }]
    };

    // Populate chart data based on report type
    <?php if ($report_data->num_rows > 0): ?>
        <?php 
        // Reset the result pointer to the beginning
        $report_data->data_seek(0);
        
        // Prepare arrays to hold the data
        $labels = [];
        $totalVisits = [];
        $uniqueVisitors = [];
        
        while ($row = $report_data->fetch_assoc()) {
            if ($report_type === 'daily') {
                $labels[] = date('M j', strtotime($row['date']));
                $totalVisits[] = $row['total_visits'];
                $uniqueVisitors[] = $row['unique_visitors'];
            } elseif ($report_type === 'weekly') {
                $labels[] = 'Week ' . date('W', strtotime($row['week_start']));
                $totalVisits[] = $row['total_visits'];
                $uniqueVisitors[] = $row['unique_visitors'];
            } elseif ($report_type === 'monthly') {
                $labels[] = $row['month_name'];
                $totalVisits[] = $row['total_visits'];
                $uniqueVisitors[] = $row['unique_visitors'];
            } else { // customer report
                $labels[] = $row['first_name'] . ' ' . $row['last_name'];
                $totalVisits[] = $row['total_visits'];
                // No unique visitors for customer report
            }
        }
        
        // Reset the result pointer again for the table display
        $report_data->data_seek(0);
        ?>
        
        // Set the chart data
        chartData.labels = <?php echo json_encode($labels); ?>;
        chartData.datasets[0].data = <?php echo json_encode($totalVisits); ?>;
        <?php if ($report_type !== 'customer'): ?>
            chartData.datasets[1].data = <?php echo json_encode($uniqueVisitors); ?>;
        <?php else: ?>
            // For customer report, we don't need the second dataset
            chartData.datasets.pop();
        <?php endif; ?>
    <?php endif; ?>

    // Create the chart
    const ctx = document.getElementById('attendanceChart').getContext('2d');
    const attendanceChart = new Chart(ctx, {
        type: <?php echo $report_type === 'daily' ? "'line'" : "'bar'"; ?>,
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Visits'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: <?php 
                            if ($report_type === 'daily') echo "'Date'";
                            elseif ($report_type === 'weekly') echo "'Week'";
                            elseif ($report_type === 'monthly') echo "'Month'";
                            else echo "'Customer'";
                        ?>
                    }
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: <?php 
                        if ($report_type === 'daily') echo "'Daily Attendance'";
                        elseif ($report_type === 'weekly') echo "'Weekly Attendance'";
                        elseif ($report_type === 'monthly') echo "'Monthly Attendance'";
                        else echo "'Customer Attendance'";
                    ?>,
                    font: {
                        size: 16
                    }
                },
                legend: {
                    position: 'top',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += context.parsed.y;
                            return label;
                        }
                    }
                }
            }
        }
    });

    // Add a second chart for time distribution if we have hourly data
    <?php if ($report_type === 'daily' && isset($busiest_hour)): ?>
    // Get hourly distribution data
    <?php
    $hourly_query = "SELECT HOUR(check_in) as hour, COUNT(*) as visit_count
                    FROM attendance 
                    WHERE branch = ? AND DATE(check_in) BETWEEN ? AND ?
                    GROUP BY HOUR(check_in)
                    ORDER BY HOUR(check_in)";
    $stmt = $conn->prepare($hourly_query);
    $stmt->bind_param("sss", $branch_name, $start_date, $end_date);
    $stmt->execute();
    $hourly_data = $stmt->get_result();
    
    $hours = [];
    $counts = [];
    
    while ($row = $hourly_data->fetch_assoc()) {
        // Convert 24-hour format to 12-hour format with AM/PM
        $hour_display = date('g A', strtotime($row['hour'] . ':00'));
        $hours[] = $hour_display;
        $counts[] = $row['visit_count'];
    }
    ?>
    
    // Create a container for the hourly chart
    const hourlyChartContainer = document.createElement('div');
    hourlyChartContainer.className = 'chart-container';
    hourlyChartContainer.style.marginTop = '20px';
    
    const hourlyCanvas = document.createElement('canvas');
    hourlyCanvas.id = 'hourlyChart';
    hourlyChartContainer.appendChild(hourlyCanvas);
    
    // Insert after the main chart
    document.querySelector('.chart-container').after(hourlyChartContainer);
    
    // Create the hourly chart
    const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
    const hourlyChart = new Chart(hourlyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($hours); ?>,
            datasets: [{
                label: 'Visits by Hour of Day',
                data: <?php echo json_encode($counts); ?>,
                backgroundColor: 'rgba(75, 192, 192, 0.5)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Visits'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Hour of Day'
                    }
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Attendance by Time of Day',
                    font: {
                        size: 16
                    }
                }
            }
        }
    });
    <?php endif; ?>
</script>
</body>
</html>

