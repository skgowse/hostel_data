<?php
/**
 * Global Header Component (Admin Portal)
 * Mess & Hostel Management System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$isLoggedIn = !empty($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? null;
$userName = $_SESSION['name'] ?? 'Administrator';

$pageTitle = $pageTitle ?? 'Mess & Hostel Management System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | Mess & Hostel Portal</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom Theme CSS -->
    <link href="/assets/css/style.css" rel="stylesheet">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('app_theme') || 'dark';
            document.documentElement.setAttribute('data-bs-theme', savedTheme);
        })();
    </script>
</head>
<body>

<!-- Top Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-custom sticky-top">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
            <?php if ($isLoggedIn): ?>
                <button class="btn btn-light rounded-circle p-2 border shadow-sm theme-toggle-btn d-inline-flex align-items-center justify-content-center" 
                        id="sidebarToggle" type="button" title="Toggle Sidebar Menu" aria-label="Toggle Menu Bar">
                    <i class="bi bi-list fs-5 text-dark" id="sidebarToggleIcon"></i>
                </button>
            <?php endif; ?>

            <a class="brand-title" href="/admin/dashboard.php">
                <i class="bi bi-building-check text-primary fs-4"></i>
                <span>Mess & Hostel Portal</span>
            </a>
        </div>

        <div class="ms-auto d-flex align-items-center gap-2 gap-md-3">
            <!-- Dark / Light Mode Toggle Button -->
            <button id="themeToggleBtn" class="btn btn-light rounded-circle p-2 border shadow-sm theme-toggle-btn" type="button" title="Toggle Dark / Light Mode" aria-label="Toggle Theme">
                <i class="bi bi-moon-stars-fill text-primary" id="themeIcon"></i>
            </button>

            <?php if ($isLoggedIn): ?>
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2 py-1 px-2 border shadow-sm" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle fs-5 text-primary"></i>
                        <span class="d-none d-md-inline fw-semibold text-dark"><?= htmlspecialchars($userName) ?></span>
                        <span class="badge bg-secondary-subtle text-secondary-emphasis border ms-1 text-uppercase" style="font-size: 0.7rem;">ADMIN</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item text-danger" href="/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            <?php else: ?>
                <a href="/login.php" class="btn btn-primary btn-sm"><i class="bi bi-box-arrow-in-right me-1"></i>Admin Login</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<?php if ($isLoggedIn): ?>
<div class="app-wrapper">
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>
    <main class="main-content">
<?php else: ?>
<main class="py-4">
<?php endif; ?>

<?php
// Render Flash message if set
$flash = get_flash();
if ($flash): ?>
    <div class="container-fluid px-0 mb-3">
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : ($flash['type'] === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill') ?> fs-5"></i>
                <div><?= htmlspecialchars($flash['message']) ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
<?php endif; ?>
