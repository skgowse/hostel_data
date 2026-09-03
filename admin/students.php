<?php
/**
 * Admin Student Management
 * Mess & Hostel Management System
 */

$pageTitle = 'Student Records';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth/middleware.php';

require_admin();

$pdo = get_db_connection();
$adminId = $_SESSION['user_id'];

// Handle Admin Permanent Student Deletion / Dropout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_student') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        set_flash('danger', 'Security token expired. Please try again.');
        header('Location: /admin/students.php');
        exit;
    }

    $delId = (int)($_POST['student_id'] ?? 0);
    $dropoutReason = sanitize_input($_POST['dropout_reason'] ?? 'Student dropped out / withdrew enrollment');

    $chkStmt = $pdo->prepare("SELECT s.*, u.id as user_id FROM students s LEFT JOIN users u ON u.student_id = s.id WHERE s.id = ? LIMIT 1");
    $chkStmt->execute([$delId]);
    $stToDel = $chkStmt->fetch();

    if (!$stToDel) {
        set_flash('danger', 'Student record not found.');
        header('Location: /admin/students.php');
        exit;
    }

    $pdo->beginTransaction();
    try {
        // 1. Delete payment allocations
        $pdo->prepare("DELETE FROM student_payment_allocations WHERE student_id = ?")->execute([$delId]);

        // 2. Delete name change requests
        $pdo->prepare("DELETE FROM name_change_requests WHERE student_id = ?")->execute([$delId]);

        // 3. Delete user account and notifications if user exists
        if (!empty($stToDel['user_id'])) {
            $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$stToDel['user_id']]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$stToDel['user_id']]);
        }

        // 4. Delete student master record
        $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$delId]);

        // 6. Audit Log
        log_audit($pdo, $adminId, 'DELETE_STUDENT', 'STUDENT', $delId, "Admin permanently removed student {$stToDel['student_code']} ({$stToDel['name']}). Reason: {$dropoutReason}");

        $pdo->commit();
        set_flash('success', "Student {$stToDel['student_code']} ({$stToDel['name']}) has been permanently deleted from the database.");
        header('Location: /admin/students.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        set_flash('danger', 'Error deleting student: ' . $e->getMessage());
        header('Location: /admin/students.php');
        exit;
    }
}

// Read Filter and Sort Parameters
$search = sanitize_input($_GET['q'] ?? '');
$messFilter = sanitize_input($_GET['mess'] ?? '');
$hostelFilter = sanitize_input($_GET['hostel'] ?? '');
$statusFilter = sanitize_input($_GET['status'] ?? '');
$serviceShortcut = sanitize_input($_GET['filter_service'] ?? '');
$fromDate = sanitize_input($_GET['from_date'] ?? '');
$toDate = sanitize_input($_GET['to_date'] ?? '');
$preset = sanitize_input($_GET['preset'] ?? '');
$sort = sanitize_input($_GET['sort'] ?? 'date_asc');

// Handle Presets
$today = date('Y-m-d');
if ($preset === 'today') {
    $fromDate = $today;
    $toDate = $today;
} elseif ($preset === 'week') {
    $fromDate = date('Y-m-d', strtotime('monday this week'));
    $toDate = date('Y-m-d', strtotime('sunday this week'));
} elseif ($preset === 'month') {
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-t');
}

// Build SQL Query
$query = "SELECT s.*, u.id as user_id FROM students s LEFT JOIN users u ON u.student_id = s.id WHERE 1=1";
$params = [];

// Quick Service Shortcuts
if ($serviceShortcut === 'mess') {
    $query .= " AND s.mess_status = 'ACTIVE'";
} elseif ($serviceShortcut === 'hostel') {
    $query .= " AND s.hostel_status = 'ACTIVE'";
} elseif ($serviceShortcut === 'both') {
    $query .= " AND s.mess_status = 'ACTIVE' AND s.hostel_status = 'ACTIVE'";
}

if (!empty($search)) {
    $query .= " AND (s.name LIKE ? OR s.mobile LIKE ? OR s.student_code LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if (!empty($messFilter)) {
    $query .= " AND s.mess_status = ?";
    $params[] = $messFilter;
}

if (!empty($hostelFilter)) {
    $query .= " AND s.hostel_status = ?";
    $params[] = $hostelFilter;
}

if (!empty($statusFilter)) {
    $query .= " AND s.status = ?";
    $params[] = $statusFilter;
}

if (!empty($fromDate)) {
    $query .= " AND s.joining_date >= ?";
    $params[] = $fromDate;
}

if (!empty($toDate)) {
    $query .= " AND s.joining_date <= ?";
    $params[] = $toDate;
}

// SQL Sorting
switch ($sort) {
    case 'name_desc':
        $query .= " ORDER BY s.name DESC";
        break;
    case 'date_desc':
        $query .= " ORDER BY s.joining_date DESC, s.id DESC";
        break;
    case 'id_desc':
        $query .= " ORDER BY s.id DESC";
        break;
    case 'id_asc':
        $query .= " ORDER BY s.id ASC";
        break;
    case 'name_asc':
        $query .= " ORDER BY s.name ASC";
        break;
    case 'date_asc':
    default:
        $query .= " ORDER BY s.joining_date ASC, s.id ASC";
        break;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();
$totalFound = count($students);

// Counts for Badges
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$messOnlyCount = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE mess_status = 'ACTIVE' AND hostel_status != 'ACTIVE'")->fetchColumn();
$bothCount = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE mess_status = 'ACTIVE' AND hostel_status = 'ACTIVE'")->fetchColumn();
$hostelOnlyCount = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE hostel_status = 'ACTIVE' AND mess_status != 'ACTIVE'")->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <!-- Header -->
    <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <span class="text-uppercase text-muted fw-bold small tracking-wide">Registry & Database</span>
                <h3 class="fw-bold text-dark mb-1">Student Management</h3>
                <p class="text-muted small mb-0">Official student registry with administrative edit and dropout deletion controls</p>
            </div>
            <div class="d-flex gap-2">
                <a href="/admin/reports.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-download me-1"></i> Export Data
                </a>
                <a href="/admin/student-add.php" class="btn btn-primary btn-sm px-3 fw-semibold">
                    <i class="bi bi-person-plus-fill me-1"></i> Add Student
                </a>
            </div>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <a href="/admin/students.php" class="text-decoration-none">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-title">All Students</div>
                        <div class="stat-value"><?= $totalCount ?></div>
                    </div>
                    <div class="stat-icon bg-primary text-white">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="/admin/students.php?filter_service=mess" class="text-decoration-none">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-title">Mess Only</div>
                        <div class="stat-value"><?= $messOnlyCount ?></div>
                    </div>
                    <div class="stat-icon bg-info text-white">
                        <i class="bi bi-cup-hot"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="/admin/students.php?filter_service=both" class="text-decoration-none">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-title">Mess + Hostel</div>
                        <div class="stat-value"><?= $bothCount ?></div>
                    </div>
                    <div class="stat-icon bg-success text-white">
                        <i class="bi bi-layers"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="/admin/students.php?filter_service=hostel" class="text-decoration-none">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-title">Hostel Only</div>
                        <div class="stat-value"><?= $hostelOnlyCount ?></div>
                    </div>
                    <div class="stat-icon bg-secondary text-white">
                        <i class="bi bi-houses"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Filter & Search Card -->
    <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
        <form method="GET" action="/admin/students.php">
            <?php if (!empty($serviceShortcut)): ?>
                <input type="hidden" name="filter_service" value="<?= htmlspecialchars($serviceShortcut) ?>">
            <?php endif; ?>

            <div class="row g-3">
                <!-- Search Keyword -->
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small">Search Student</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" name="q" placeholder="Name, Mobile, ID..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>

                <!-- Mess Filter -->
                <div class="col-md-4 col-lg-2">
                    <label class="form-label small">Mess Status</label>
                    <select class="form-select" name="mess">
                        <option value="">All</option>
                        <option value="ACTIVE" <?= $messFilter === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                        <option value="INACTIVE" <?= $messFilter === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <!-- Hostel Filter -->
                <div class="col-md-4 col-lg-2">
                    <label class="form-label small">Hostel Status</label>
                    <select class="form-select" name="hostel">
                        <option value="">All</option>
                        <option value="ACTIVE" <?= $hostelFilter === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                        <option value="NOT_APPLICABLE" <?= $hostelFilter === 'NOT_APPLICABLE' ? 'selected' : '' ?>>Not Applicable</option>
                        <option value="INACTIVE" <?= $hostelFilter === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <!-- Status Filter -->
                <div class="col-md-4 col-lg-2">
                    <label class="form-label small">Enrollment</label>
                    <select class="form-select" name="status">
                        <option value="">All</option>
                        <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                        <option value="INACTIVE" <?= $statusFilter === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <!-- Sort Order -->
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small">Sort By</label>
                    <select class="form-select" name="sort">
                        <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Joining Date (Oldest first)</option>
                        <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Joining Date (Newest first)</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A &rarr; Z</option>
                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z &rarr; A</option>
                        <option value="id_asc" <?= $sort === 'id_asc' ? 'selected' : '' ?>>Student ID (Low to High)</option>
                        <option value="id_desc" <?= $sort === 'id_desc' ? 'selected' : '' ?>>Student ID (High to Low)</option>
                    </select>
                </div>

                <!-- Date Range: From Date & To Date -->
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small">From Joining Date</label>
                    <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                </div>

                <div class="col-md-4 col-lg-3">
                    <label class="form-label small">To Joining Date</label>
                    <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
                </div>

                <!-- Quick Date Presets & Actions -->
                <div class="col-md-4 col-lg-6 d-flex align-items-end gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary px-3">
                        <i class="bi bi-funnel-fill me-1"></i> Apply
                    </button>
                    <a href="/admin/students.php?preset=today" class="btn btn-outline-secondary btn-sm">Today</a>
                    <a href="/admin/students.php?preset=week" class="btn btn-outline-secondary btn-sm">This Week</a>
                    <a href="/admin/students.php?preset=month" class="btn btn-outline-secondary btn-sm">This Month</a>
                    <a href="/admin/students.php" class="btn btn-light border btn-sm text-danger">Clear All</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Students Table Card -->
    <div class="card border-0 shadow-sm rounded-4 bg-white p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="text-muted small">Showing <strong><?= $totalFound ?></strong> student records</span>
            <?php if (!empty($serviceShortcut)): ?>
                <span class="badge bg-primary-subtle text-primary border text-uppercase">Shortcut Filter: <?= htmlspecialchars($serviceShortcut) ?></span>
            <?php endif; ?>
        </div>

        <?php if (empty($students)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-people fs-1 d-block mb-2 text-secondary"></i>
                <h5>No students found</h5>
                <p class="small mb-0">Try clearing or adjusting your search and filter criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table custom-table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Mobile</th>
                            <th>Mess</th>
                            <th>Hostel</th>
                            <th>Room</th>
                            <th>Joining Date</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                            <tr>
                                <td class="font-monospace fw-bold text-primary">
                                    <a href="/admin/student-view.php?id=<?= $s['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($s['student_code']) ?>
                                    </a>
                                </td>
                                <td class="fw-semibold text-dark"><?= htmlspecialchars($s['name']) ?></td>
                                <td class="font-monospace">
                                    <?= htmlspecialchars($s['mobile'] ?: '—') ?>
                                </td>
                                <td>
                                    <?php if ($s['mess_status'] === 'ACTIVE'): ?>
                                        <span class="badge badge-status-active px-2 py-1">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="badge badge-status-inactive px-2 py-1">INACTIVE</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['hostel_status'] === 'ACTIVE'): ?>
                                        <span class="badge badge-status-active px-2 py-1">ACTIVE</span>
                                    <?php elseif ($s['hostel_status'] === 'NOT_APPLICABLE'): ?>
                                        <span class="badge badge-status-na px-2 py-1">N/A</span>
                                    <?php else: ?>
                                        <span class="badge badge-status-inactive px-2 py-1">INACTIVE</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($s['room_no'] ?: '—') ?>
                                </td>
                                <td class="fw-semibold text-secondary">
                                    <?= format_display_date($s['joining_date']) ?>
                                </td>
                                <td>
                                    <?php if ($s['status'] === 'ACTIVE'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="/admin/student-view.php?id=<?= $s['id'] ?>" class="btn btn-outline-secondary" title="View Full Record">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="/admin/student-edit.php?id=<?= $s['id'] ?>" class="btn btn-outline-primary" title="Edit Student">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" title="Delete / Drop Out Student"
                                                onclick="openDeleteStudentModal('<?= $s['id'] ?>', '<?= htmlspecialchars(addslashes($s['student_code'])) ?>', '<?= htmlspecialchars(addslashes($s['name'])) ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Permanently Delete / Drop Out Student -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form method="POST" action="/admin/students.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_student">
                <input type="hidden" name="student_id" id="del_modal_student_id">

                <div class="modal-header bg-danger text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete / Drop Out Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="bi bi-shield-exclamation me-1"></i>
                        <strong>Warning:</strong> This action permanently removes the student from the database, including login credentials, registrations, and payment allocations.
                    </div>
                    <p class="text-secondary mb-3">
                        Are you sure you want to permanently delete student:
                        <br>
                        <strong id="del_modal_student_name" class="fs-6 text-dark"></strong>
                        (<span id="del_modal_student_code" class="font-monospace text-primary fw-bold"></span>)?
                    </p>
                    <div class="mb-3">
                        <label for="dropout_reason" class="form-label fw-bold">Reason for Deletion / Dropout <span class="text-danger">*</span></label>
                        <select class="form-select mb-2" id="dropout_reason_select" onchange="document.getElementById('dropout_reason').value = this.value;">
                            <option value="Student dropped out / discontinued course">Student dropped out / discontinued course</option>
                            <option value="Completed course & vacated campus">Completed course & vacated campus</option>
                            <option value="Transferred to other institution">Transferred to other institution</option>
                            <option value="Disciplinary withdrawal">Disciplinary withdrawal</option>
                            <option value="Duplicate or test entry removal">Duplicate or test entry removal</option>
                        </select>
                        <input type="text" class="form-control" id="dropout_reason" name="dropout_reason" 
                               value="Student dropped out / discontinued course" required placeholder="Specify reason for audit logs">
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 pt-0">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4 fw-semibold">
                        <i class="bi bi-trash-fill me-1"></i> Confirm Permanent Deletion
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openDeleteStudentModal(studentId, studentCode, studentName) {
    document.getElementById('del_modal_student_id').value = studentId;
    document.getElementById('del_modal_student_code').textContent = studentCode;
    document.getElementById('del_modal_student_name').textContent = studentName;

    const modal = new bootstrap.Modal(document.getElementById('deleteStudentModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
