<?php
/**
 * Administrator Portal Login
 * Mess & Hostel Management System
 */

$pageTitle = 'Administrator Login';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/auth/auth.php';

// Redirect if already logged in as admin
if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $mobile = sanitize_input($_POST['mobile'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($mobile) || empty($password)) {
            $error = 'Please enter both your Admin ID/Mobile and password.';
        } else {
            $result = attempt_login($mobile, $password);
            if ($result['success']) {
                if ($result['role'] === 'admin') {
                    set_flash('success', 'Welcome back, Administrator!');
                    header('Location: /admin/dashboard.php');
                    exit;
                } else {
                    // Non-admin login attempts are blocked
                    $_SESSION = [];
                    session_destroy();
                    $error = 'Access restricted. Only administrators may log in to this portal.';
                }
            } else {
                $error = $result['error'];
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-lg rounded-4 overflow-hidden bg-white">
                <div class="card-header bg-primary text-white text-center py-4 border-0">
                    <div class="d-inline-flex p-3 rounded-circle bg-white bg-opacity-25 mb-2 shadow-sm">
                        <i class="bi bi-shield-lock-fill fs-2"></i>
                    </div>
                    <h4 class="fw-bold mb-1">Administration Portal</h4>
                    <p class="mb-0 text-white-50 small">Mess & Hostel Management System</p>
                </div>
                <div class="card-body p-4 p-md-4">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show small" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="/login.php" novalidate>
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label for="mobile" class="form-label small fw-bold">Admin ID / Mobile Number</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person-fill"></i></span>
                                <input type="text" class="form-control font-monospace" id="mobile" name="mobile" 
                                       placeholder="e.g. 9999999999" required autofocus
                                       value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="password" class="form-label small fw-bold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-key-fill"></i></span>
                                <input type="password" class="form-control" id="password" name="password" 
                                       placeholder="Enter administrator password" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to Dashboard
                        </button>
                    </form>
                </div>
                <div class="card-footer bg-light text-center py-3 border-0 small text-muted">
                    <i class="bi bi-lock-fill me-1"></i> Secure Administrative Access Only
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
