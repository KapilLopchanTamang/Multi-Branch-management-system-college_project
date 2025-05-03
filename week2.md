# Weekly Progress Report

**Student Name:** Kapil Thamar  
**Week Number:** 2  
**Report Duration:** From April 21, 2025 To April 27, 2025  
**Report Submission Date:** April 28, 2025  

---

### ✅ Completed on Time?
Yes

---

### ❗ Challenges Faced:
- Integrating QR code generation and scanning into the attendance system
- Handling real-time QR code scanning through the device camera
- Validating QR codes securely and mapping them to customer records
- Ensuring no duplicate check-ins and enforcing attendance rules
- Connecting frontend QR scanning to backend attendance recording

---

### 📚 What I Learned:
- Implementing CRUD operations for customer management
- Generating and decoding QR codes dynamically
- Using JavaScript libraries like `jsQR` for real-time QR scanning
- Connecting QR scanning results with backend PHP logic
- Managing permission-based access control for different admin roles
- Structuring attendance logic with auto-checkout and reporting

---

### 🗓️ Plan for Next Week:
- Add export functionality for attendance reports (CSV)
- Implement attendance statistics dashboard (daily/weekly/monthly graphs)
- Enhance admin override features for attendance exceptions
- Start integrating SMS/email notifications for membership and attendance alerts

---

### 📝 Task Summary:

| Task Description                                 | Estimated Completion Time |
|-------------------------------------------------|--------------------------|
| Added CRUD operations for customer management    | 3 hours                  |
| Built manual attendance system                   | 2.5 hours                |
| Implemented QR code generation for customers     | 2 hours                  |
| Integrated QR code scanning with webcam          | 3.5 hours                |
| Linked QR scan result to auto check-in process   | 2 hours                  |
| Developed attendance rules & database logic      | 2 hours                  |
| Permission checks for attendance access          | 1 hour                   |

---

## 🏋️ Attendance System Features Implemented:

1. **Manual Check-in/Check-out**: Admin can manually record attendance via dropdown selection
2. **QR Code Scanning**: Customers can check in by showing their QR code to the device camera
3. **Unique QR Codes**: Each customer receives a unique QR code in the format `GYM_CUSTOMER_ID:{id}`
4. **Automatic Check-in**: Scanned QR codes auto-submit attendance without manual selection
5. **Attendance Rules**: Enforced max check-ins per day, no duplicate active check-ins
6. **Attendance Records**: Logged customer ID, check-in time, branch, admin user, and method (QR/manual)
7. **Permission Integration**: Attendance feature shown only to admins with `attendance` permission
8. **Auto-checkout**: Customers auto-checked out after configurable time

---

## 📌 Key Code Snippets:

### QR Code Generation:

```javascript
function generateCustomerQRCode(customerId) {
    const qrData = `GYM_CUSTOMER_ID:${customerId}`;
    const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(qrData)}`;
    return qrCodeUrl;
}
