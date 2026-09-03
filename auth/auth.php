<?php
/**
 * Authentication Logic (Login / Logout / Session Management)
 * Mess & Hostel Management System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

function attempt_login($mobile, $password) {
    $pdo = get_db_connection();

    // Basic brute-force throttling per session
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = time();
    }

    if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['last_attempt_time']) < 60) {
        $remaining = 60 - (time() - $_SESSION['last_attempt_time']);
        return ['success' => false, 'error' => "Too many failed attempts. Please wait {$remaining} seconds."];
    }

    $cleanIdentifier = trim($mobile);
    $stmt = $pdo->prepare("
        SELECT u.*, s.name as student_name, s.status as student_status
        FROM users u
        LEFT JOIN students s ON u.student_id = s.id
        WHERE u.mobile = ? OR s.student_code = ? LIMIT 1
    ");
    $stmt->execute([$cleanIdentifier, $cleanIdentifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $_SESSION['login_attempts']++;
        $_SESSION['last_attempt_time'] = time();
        return ['success' => false, 'error' => 'Invalid mobile number or password.'];
    }

    if ($user['status'] !== 'active') {
        return ['success' => false, 'error' => 'Your account is deactivated. Please contact the administrator.'];
    }

    // Reset rate limiter
    unset($_SESSION['login_attempts']);
    unset($_SESSION['last_attempt_time']);

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id']     = (int)$user['id'];
    $_SESSION['student_id']  = $user['student_id'] ? (int)$user['student_id'] : null;
    $_SESSION['mobile']      = $user['mobile'];
    $_SESSION['role']        = $user['role'];
    $_SESSION['first_login'] = (int)$user['first_login'];
    $_SESSION['name']        = ($user['role'] === 'admin') ? 'Administrator' : ($user['student_name'] ?: 'Student');

    // Audit log if admin
    if ($user['role'] === 'admin') {
        log_audit($pdo, $user['id'], 'ADMIN_LOGIN', 'USER', $user['id'], 'Admin logged in successfully');
    }

    return ['success' => true, 'role' => $user['role'], 'first_login' => (int)$user['first_login']];
}

function process_logout() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();
}
