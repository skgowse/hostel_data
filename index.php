<?php
/**
 * Root Landing Redirection -> Admin Management Portal
 * Mess & Hostel Management System
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/auth/middleware.php';

if (is_logged_in() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: /admin/dashboard.php');
} else {
    header('Location: /login.php');
}
exit;
