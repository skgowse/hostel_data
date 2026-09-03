<?php
/**
 * Authentication Middleware & Role Guard
 * Mess & Hostel Management System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

function current_user() {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'          => $_SESSION['user_id'],
        'student_id'  => $_SESSION['student_id'] ?? null,
        'mobile'      => $_SESSION['mobile'] ?? '',
        'role'        => $_SESSION['role'] ?? '',
        'name'        => $_SESSION['name'] ?? 'Administrator'
    ];
}

function require_login() {
    if (!is_logged_in()) {
        set_flash('danger', 'Please login to access the administration portal.');
        header('Location: /login.php');
        exit;
    }
}

function require_admin() {
    require_login();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        set_flash('danger', 'Unauthorized access! Administrator privileges required.');
        header('Location: /login.php');
        exit;
    }
}

function require_student() {
    header('Location: /admin/dashboard.php');
    exit;
}
