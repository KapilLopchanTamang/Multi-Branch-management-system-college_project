# Weekly Progress Report

**Student Name:** Kapil Tamang
**Week Number:** 4  
**Report Duration:** From May 5, 2025 To May 11, 2025  
**Report Submission Date:** May 18, 2025  

---

### ✅ Completed on Time?  
Yes

---

### ❗ Challenges Faced:
- Creating a secure and efficient user authentication system  
- Managing different user types (customers, admins) under a unified login system  
- Handling forgotten passwords securely via tokenized reset links  
- Designing a clean and informative dashboard layout for branch admins  
- Ensuring sessions and redirections behave correctly for unauthorized access  

---

### 📚 What I Learned:
- Implementing login/logout/register systems using PHP and MySQL  
- Securing password storage using `password_hash()` and `password_verify()`  
- Managing session-based authentication and role-based redirection  
- Designing user-friendly forms with input validation and feedback  
- Generating and validating password reset tokens with expiry checks  
- Building dashboards with quick stats, charts, and navigation aids  

---

### 🗓️ Plan for Next Week:
- Implement customer membership module  
- Develop payment and invoice generation feature  
- Add dynamic dashboard charts using Chart.js  
- Start work on customer profile and progress tracking  

---

### 📝 Task Summary:

| Task Description                                    | Estimated Completion Time |
|----------------------------------------------------|----------------------------|
| Created secure login & logout functionality        | 3 hours                    |
| Developed registration with validation             | 2.5 hours                  |
| Built dashboard for branch admins                  | 3.5 hours                  |
| Integrated password change module                  | 1.5 hours                  |
| Implemented forgot password via email token        | 3 hours                    |
| Designed customer listing and CRUD operations      | 3 hours                    |
| Tested and debugged authentication flows           | 2 hours                    |

---

## 👥 Authentication & Dashboard Features Implemented:

1. **Login/Logout System**:  
   Secure login form with redirection based on user role. Sessions are validated on each page.

2. **Registration Form**:  
   Admins can register new customer accounts with basic details and password setup.

3. **Forgot Password Functionality**:  
   Sends a time-limited password reset link to registered email using secure token system.

4. **Change Password Module**:  
   Customers and admins can update passwords after validating current one.

5. **Customer Management Section**:  
   Admins can view, search, add, edit, and delete customers assigned to their branch.

6. **Dashboard Page**:  
   Admins see an overview of trainers, customers, sessions, and attendance at a glance. 

7. **Role-Based Access**:  
   All pages check user role and permission before allowing access or showing options.

---

## 🔐 Key Code Snippets:

### Password Hashing on Registration:
```php
$hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
