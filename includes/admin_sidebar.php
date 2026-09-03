<?php
/**
 * Admin Sidebar Navigation
 * Mess & Hostel Management System
 */

$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar">
    <div class="sidebar-heading">Navigation</div>
    <a href="/admin/dashboard.php" class="sidebar-nav-link <?= in_array($current_page, ['dashboard.php', 'payments.php', 'payment-view.php']) ? 'active' : '' ?>">
        <i class="bi bi-grid-1x2-fill"></i>
        <span>Dashboard</span>
    </a>

    <a href="/admin/students.php" class="sidebar-nav-link <?= in_array($current_page, ['students.php', 'student-view.php', 'student-edit.php', 'student-add.php']) ? 'active' : '' ?>">
        <i class="bi bi-people-fill"></i>
        <span>Student List</span>
    </a>

    <div class="sidebar-heading mt-4">Account</div>
    <a href="/logout.php" class="sidebar-nav-link text-danger">
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>
</aside>
