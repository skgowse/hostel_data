<?php
/**
 * Admin: Edit Student & Manage Services
 * Mess & Hostel Management System
 */

$pageTitle = 'Edit Student';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth/middleware.php';

require_admin();

$pdo = get_db_connection();
$adminId = $_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

// Handle Admin Permanent Student Deletion / Dropout from Edit Page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'delete_student' || $_POST['action'] === 'delete_student_dropout')) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        set_flash('danger', 'Security token expired. Please try again.');
        header("Location: /admin/student-edit.php?id={$id}");
        exit;
    }

    $delId = (int)($_POST['student_id'] ?? $id);
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
        $pdo->prepare("DELETE FROM student_payment_allocations WHERE student_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM name_change_requests WHERE student_id = ?")->execute([$delId]);

        if (!empty($stToDel['user_id'])) {
            $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$stToDel['user_id']]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$stToDel['user_id']]);
        }

        $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$delId]);
        log_audit($pdo, $adminId, 'DELETE_STUDENT', 'STUDENT', $delId, "Admin permanently removed student {$stToDel['student_code']} ({$stToDel['name']}). Reason: {$dropoutReason}");

        $pdo->commit();
        set_flash('success', "Student {$stToDel['student_code']} ({$stToDel['name']}) has been permanently deleted from the database.");
        header('Location: /admin/students.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        set_flash('danger', 'Error deleting student: ' . $e->getMessage());
        header("Location: /admin/student-edit.php?id={$id}");
        exit;
    }
}

$stmt = $pdo->prepare("SELECT s.*, u.id as user_id, u.mobile as user_mobile, u.status as user_status FROM students s LEFT JOIN users u ON u.student_id = s.id WHERE s.id = ? LIMIT 1");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    set_flash('danger', 'Student not found.');
    header('Location: /admin/students.php');
    exit;
}

$errors = [];

// Handle Password Reset
if (isset($_POST['reset_password'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $mobile = $student['mobile'];
        if (empty($mobile) || strlen($mobile) < 6) {
            set_flash('danger', 'Cannot reset password: student has no valid mobile number on file.');
        } else {
            $last6 = substr($mobile, -6);
            $hash = password_hash($last6, PASSWORD_DEFAULT);

            // Check if user exists or create one
            if ($student['user_id']) {
                $uStmt = $pdo->prepare("UPDATE users SET password_hash = ?, first_login = 0, updated_at = NOW() WHERE id = ?");
                $uStmt->execute([$hash, $student['user_id']]);
            } else {
                $uStmt = $pdo->prepare("INSERT INTO users (student_id, mobile, password_hash, role, first_login, status, created_at) VALUES (?, ?, ?, 'student', 0, 'active', NOW())");
                $uStmt->execute([$id, $mobile, $hash]);
            }

            log_audit($pdo, $adminId, 'RESET_PASSWORD', 'STUDENT', $id, "Reset password for student {$student['name']} ({$student['student_code']}) to default last 6 digits of mobile");
            set_flash('success', "Password reset to default (last 6 digits: {$last6}).");
            header("Location: /admin/student-edit.php?id={$id}");
            exit;
        }
    }
}

// Handle Student Details Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['reset_password']) && (!isset($_POST['action']) || $_POST['action'] === 'update_particulars')) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        $errors[] = 'Security token expired. Please try again.';
    } else {
        $name = sanitize_input($_POST['name'] ?? '');
        $mobile = sanitize_input($_POST['mobile'] ?? '');
        $cleanMobile = preg_replace('/[^0-9]/', '', $mobile);
        $joining_date = sanitize_input($_POST['joining_date'] ?? '');
        $mess_status = sanitize_input($_POST['mess_status'] ?? 'ACTIVE');
        $hostel_status = sanitize_input($_POST['hostel_status'] ?? 'NOT_APPLICABLE');
        $room_no = sanitize_input($_POST['room_no'] ?? '');
        $status = sanitize_input($_POST['status'] ?? 'ACTIVE');
        $monthly_fee = isset($_POST['monthly_fee']) && $_POST['monthly_fee'] !== '' ? (float)$_POST['monthly_fee'] : null;

        if (empty($name)) {
            $errors[] = 'Full name is required.';
        }

        if (!empty($cleanMobile) && strlen($cleanMobile) !== 10) {
            $errors[] = 'Mobile number must be 10 digits if provided.';
        }

        if (empty($joining_date)) {
            $errors[] = 'Joining date is required.';
        }

        if ($hostel_status !== 'ACTIVE') {
            $room_no = null;
        }

        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare("
                    UPDATE students 
                    SET name = ?, mobile = ?, joining_date = ?, mess_status = ?, hostel_status = ?, room_no = ?, monthly_fee = ?, status = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $upd->execute([
                    $name,
                    $cleanMobile ?: null,
                    $joining_date,
                    $mess_status,
                    $hostel_status,
                    $room_no,
                    $monthly_fee,
                    $status,
                    $id
                ]);

                // Update user account name & mobile if exists
                if ($student['user_id']) {
                    $uUpd = $pdo->prepare("UPDATE users SET name = ?, mobile = ?, updated_at = NOW() WHERE id = ?");
                    $uUpd->execute([$name, $cleanMobile ?: $student['user_mobile'], $student['user_id']]);
                }

                log_audit($pdo, $adminId, 'UPDATE_STUDENT', 'STUDENT', $id, "Admin updated particulars for student {$student['student_code']} ({$name})");

                $pdo->commit();
                set_flash('success', "Student particulars for {$student['student_code']} have been successfully updated.");
                header("Location: /admin/student-view.php?id={$id}");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 bg-white p-4 p-md-5 mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 border-bottom mb-4 gap-3">
                    <div>
                        <span class="text-uppercase text-muted fw-bold small">Administrator Controls</span>
                        <h3 class="fw-bold mb-0 text-dark">Edit Student Record</h3>
                        <span class="badge bg-light text-primary border font-monospace"><?= htmlspecialchars($student['student_code']) ?></span>
                    </div>
                    <div>
                        <a href="/admin/student-view.php?id=<?= $student['id'] ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left me-1"></i> Back to File
                        </a>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/admin/student-edit.php?id=<?= $student['id'] ?>">
                    <?= csrf_field() ?>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($_POST['name'] ?? $student['name']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label for="mobile" class="form-label">Mobile Number</label>
                            <input type="tel" class="form-control font-monospace" id="mobile" name="mobile" maxlength="10" placeholder="10-digit number" value="<?= htmlspecialchars($_POST['mobile'] ?? $student['mobile']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="joining_date" class="form-label">Joining Date (Billing Anchor) <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="joining_date" name="joining_date" value="<?= htmlspecialchars($_POST['joining_date'] ?? $student['joining_date']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label for="status" class="form-label">Enrollment Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="ACTIVE" <?= ($_POST['status'] ?? $student['status']) === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                                <option value="INACTIVE" <?= ($_POST['status'] ?? $student['status']) === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                                <option value="SUSPENDED" <?= ($_POST['status'] ?? $student['status']) === 'SUSPENDED' ? 'selected' : '' ?>>Suspended</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="mess_status" class="form-label">Mess Service</label>
                            <select class="form-select" id="mess_status" name="mess_status">
                                <option value="ACTIVE" <?= ($_POST['mess_status'] ?? $student['mess_status']) === 'ACTIVE' ? 'selected' : '' ?>>Active (₹2,500/mo)</option>
                                <option value="INACTIVE" <?= ($_POST['mess_status'] ?? $student['mess_status']) === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="hostel_status" class="form-label">Hostel Service</label>
                            <select class="form-select" id="hostel_status" name="hostel_status">
                                <option value="ACTIVE" <?= ($_POST['hostel_status'] ?? $student['hostel_status']) === 'ACTIVE' ? 'selected' : '' ?>>Active (₹3,000/mo)</option>
                                <option value="NOT_APPLICABLE" <?= ($_POST['hostel_status'] ?? $student['hostel_status']) === 'NOT_APPLICABLE' ? 'selected' : '' ?>>Not Applicable</option>
                                <option value="INACTIVE" <?= ($_POST['hostel_status'] ?? $student['hostel_status']) === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="col-md-4" id="room_number_container">
                            <label for="room_no" class="form-label">Hostel Room Number</label>
                            <input type="text" class="form-control font-monospace" id="room_no" name="room_no" placeholder="e.g. Room 101" value="<?= htmlspecialchars($_POST['room_no'] ?? $student['room_no']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="monthly_fee" class="form-label">Custom Monthly Fee (₹)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">₹</span>
                                <input type="number" step="0.01" class="form-control font-monospace fw-semibold" id="monthly_fee" name="monthly_fee" placeholder="Leave empty for plan default" value="<?= htmlspecialchars($_POST['monthly_fee'] ?? $student['monthly_fee'] ?? '') ?>">
                            </div>
                            <small class="text-muted">Override the standard monthly fee plan for this student</small>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                        <a href="/admin/student-view.php?id=<?= $student['id'] ?>" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4 fw-semibold">
                            <i class="bi bi-check-lg me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Danger Zone: Permanent Deletion / Dropout -->
            <div class="card border-danger shadow-sm rounded-4 bg-white p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h5 class="fw-bold text-danger mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Danger Zone: Dropout / Delete Student</h5>
                        <p class="text-muted small mb-0">Permanently remove this student from database records if they have dropped out or vacated the campus.</p>
                    </div>
                    <button type="button" class="btn btn-danger fw-semibold px-3"
                            onclick="openDeleteStudentModal('<?= $student['id'] ?>', '<?= htmlspecialchars(addslashes($student['student_code'])) ?>', '<?= htmlspecialchars(addslashes($student['name'])) ?>')">
                        <i class="bi bi-trash-fill me-1"></i> Delete Student
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Permanently Delete / Drop Out Student -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form method="POST" action="/admin/student-edit.php?id=<?= $student['id'] ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_student">
                <input type="hidden" name="student_id" id="del_modal_student_id" value="<?= $student['id'] ?>">

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
                        <strong id="del_modal_student_name" class="fs-6 text-dark"><?= htmlspecialchars($student['name']) ?></strong>
                        (<span id="del_modal_student_code" class="font-monospace text-primary fw-bold"><?= htmlspecialchars($student['student_code']) ?></span>)?
                    </p>
                    <div class="mb-3">
                        <label for="dropout_reason" class="form-label fw-bold">Reason for Deletion / Dropout <span class="text-danger">*</span></label>
                        <select class="form-select mb-2" onchange="document.getElementById('dropout_reason').value = this.value;">
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
