# Mess & Hostel Management System

A complete, modern, responsive, institutional-grade web application built with **PHP 8.2**, **MySQL / MariaDB**, and **Bootstrap 5** for managing student registrations, mess dining memberships, residential hostel allotments, administrative approvals, date-wise records, and exportable reports.

---

## 🚀 Key Features

### 1. Two Dedicated Roles with RBAC
* **Student/User**:
  - Self-service registration with Request ID generation (`REQ-00001`).
  - Public status tracker for pending, approved, or rejected applications.
  - Login via registered 10-digit mobile number.
  - Initial password defaults to the last 6 digits of mobile.
  - **Mandatory password change enforced on first login**.
  - Student Dashboard showing Mess Status, Hostel Status, Joining Date, Room Number (only if residential), and masked phone number.
  - Profile view with contact number updates.
  - Dedicated Dining & Mess portal with schedule and guidelines.
  - Dedicated Hostel portal with room details and warden contacts.
  - Notifications center with unread badges.
  - Role guards preventing any access to administrative pages or other students' data.

* **Admin**:
  - Full-featured Dashboard with real-time KPI cards & Chart.js visualizations (Mess vs Hostel breakdown, date-wise registration trends, application status counts).
  - Application Management: View, Approve, and Reject requests with confirmation modals and custom rejection reasons.
  - Student Directory: Search by Name, Mobile, or Student Code; filter by Mess Status, Hostel Status, Enrolment Status, and Joining Date ranges (with presets: Today, This Week, This Month).
  - Date-aware SQL sorting (Never string sorting on date strings).
  - **Date-wise Records**: Dedicated page allowing administrators to select any date (e.g., `05-Aug-2026`) to inspect all enrolled students.
  - Manual Student Registration and comprehensive editing (Mess/Hostel activation, room assignments, mobile updates, and password resets).
  - Comprehensive Reports: Daily, Date Range, Mess Members, Hostel Residents, Combined Mess+Hostel, and Registration Period.
  - One-click print views and instant CSV / Excel export.
  - Administrative Audit Trail recording all critical actions.

### 2. Pre-loaded Dataset
* Pre-seeded with the **113 initial student records** from `entries_1_to_113.xlsx` (including `Sudhay`, `Sk. Mehammmud`, `M. Jeevan Sai`, `D. Manikanta` with residential hostel rooms, and 109 mess-only students).
* Normalized SQL `DATE` storage formatted as `02-Aug-2026` across all views.

---

## 🛠️ Technology Stack & Requirements

* **Backend**: PHP 8.2+
* **Database**: MySQL / MariaDB (Port `3307`, default user: `root`, password: empty)
* **Frontend**: HTML5, CSS3, JavaScript (ES6), Bootstrap 5.3.3, Bootstrap Icons, Chart.js
* **Security**: PDO prepared statements, `password_hash()` / `password_verify()`, CSRF tokens, session fixation prevention via `session_regenerate_id()`, brute-force login rate limiting, and input sanitization.

---

## 🏃 Running the Application

### 1. Database Configuration
Database credentials in `config/database.php`:
* **Host**: `127.0.0.1`
* **Port**: `3307`
* **Database**: `mess_hostel_db`
* **Username**: `root`
* **Password**: `""` (empty)

To re-run migrations and reload the 113-student dataset at any time:
```bash
php database/seed_data.php
```

### 2. Start the Web Server
Run the built-in PHP web server from the project root:
```bash
php -S 127.0.0.1:8000
```
Open your browser and navigate to:
```
http://127.0.0.1:8000
```

---

## 🔐 Default Credentials

### Administrator
* **Mobile / User ID**: `9999999999`
* **Password**: `admin123`

### Approved Student (Example from Registration Flow)
* **Mobile / User ID**: `9876543210`
* **Initial Password**: `543210` (last 6 digits of mobile)
* *(Upon initial sign-in, the system automatically redirects to set a custom personal password)*

---

## 📁 Project Structure

```text
hostel/
├── index.php                 # Institutional landing page
├── login.php                 # Unified authentication portal
├── register.php              # Student self-registration form
├── check-status.php          # Public application status tracker
├── logout.php                # Secure logout
├── test_system_workflows.py  # Automated E2E test suite (14 checks)
├── config/
│   └── database.php          # PDO database connection
├── database/
│   ├── database.sql          # Schema, tables, triggers & indexes
│   ├── seed_data.php         # Importer for the 113 students
│   └── entries_1_to_113.xlsx # Original source spreadsheet
├── auth/
│   ├── auth.php              # Login logic & rate limiting
│   └── middleware.php        # Role guards & first-login redirects
├── includes/
│   ├── functions.php         # Security & formatting utilities
│   ├── header.php            # Global header & topbar
│   ├── footer.php            # Global footer & scripts
│   ├── student_sidebar.php   # Student navigation menu
│   └── admin_sidebar.php     # Admin navigation menu
├── assets/
│   ├── css/
│   │   └── style.css         # Modern institutional theme
│   └── js/
│       └── app.js            # Dynamic forms & modals
├── student/
│   ├── dashboard.php         # Student overview cards
│   ├── profile.php           # Profile & mobile updates
│   ├── mess.php              # Dining hours & meal info
│   ├── hostel.php            # Residential room details
│   ├── notifications.php     # Notifications & alerts
│   └── change-password.php   # Mandatory password change
└── admin/
    ├── dashboard.php         # KPI stats & visual charts
    ├── applications.php      # Application queue (Pending/Approved/Rejected)
    ├── application-view.php  # Review modal, Approve & Reject logic
    ├── students.php          # Student management with search, filter, sort
    ├── student-add.php       # Manual student enrollment
    ├── student-edit.php      # Edit info, reset password, change status
    ├── student-view.php      # Full student file & history
    ├── date-records.php      # Dedicated Date-wise roll viewer
    ├── reports.php           # Daily, range, mess, hostel reports
    ├── export.php            # CSV data export engine
    ├── audit-logs.php        # Administrative activity history
    └── settings.php          # Diagnostics & admin password update
```
