<?php
// Main entry point - redirects to public/index.php
header("Location: public/index.php");
exit();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym Network - Login Portal</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .portal-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background-color: #FFE8E6;
            padding: 20px;
        }
        
        .portal-header {
            text-align: center;
            margin-bottom: 50px;
        }
        
        .portal-header h1 {
            font-size: 48px;
            color: #000;
            margin-bottom: 20px;
        }
        
        .portal-header p {
            font-size: 18px;
            color: #333;
            max-width: 600px;
        }
        
        .portal-options {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .portal-card {
            background-color: #FFF;
            border-radius: 10px;
            padding: 30px;
            width: 300px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .portal-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        .portal-card i {
            font-size: 48px;
            color: #FF6B45;
            margin-bottom: 20px;
        }
        
        .portal-card h2 {
            font-size: 24px;
            margin-bottom: 15px;
            color: #000;
        }
        
        .portal-card p {
            font-size: 16px;
            color: #555;
            margin-bottom: 25px;
        }
        
        .portal-btn {
            display: inline-block;
            padding: 12px 30px;
            background-color: #000;
            color: #FFF;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
            transition: background-color 0.3s;
        }
        
        .portal-btn:hover {
            background-color: #333;
        }
        
        @media (max-width: 768px) {
            .portal-options {
                flex-direction: column;
                align-items: center;
            }
            
            .portal-card {
                width: 100%;
                max-width: 300px;
            }
        }
    </style>
</head>
<body>
    <div class="portal-container">
        <div class="portal-header">
            <h1>Gym Network Portal</h1>
            <p>Welcome to our multi-branch fitness network. Choose your login portal below.</p>
        </div>
        
        <div class="portal-options">
            <div class="portal-card">
                <i class="fas fa-user"></i>
                <h2>Customer Login</h2>
                <p>Access your membership, book classes, and track your fitness progress.</p>
                <a href="customer-login.php" class="portal-btn">Customer Login</a>
            </div>
            
            <div class="portal-card">
                <i class="fas fa-user-shield"></i>
                <h2>Admin Login</h2>
                <p>Manage gym branches, staff, and member information.</p>
                <a href="admin-login.php" class="portal-btn">Admin Login</a>
            </div>
            
            <div class="portal-card">
                <i class="fas fa-user-plus"></i>
                <h2>New Member?</h2>
                <p>Join our fitness community and start your wellness journey today.</p>
                <a href="register.php" class="portal-btn">Register Now</a>
            </div>
        </div>
    </div>
</body>
</html>

