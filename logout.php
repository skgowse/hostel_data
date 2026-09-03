<?php
/**
 * Logout Handler
 * Mess & Hostel Management System
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/auth/auth.php';

process_logout();
set_flash('info', 'You have been logged out successfully.');
header('Location: /login.php');
exit;
