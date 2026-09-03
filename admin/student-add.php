<?php
/**
 * Admin: Add Student Manually
 * Mess & Hostel Management System
 */

$pageTitle = 'Add Student';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth/middleware.php';

require_admin();

$pdo = get_db_connection();
$adminId = $_SESSION['user_id'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        if (empty($name) || strlen($name) < 2) {
            $errors[] = 'Full name is required.';
        }

        if (!empty($cleanMobile) && strlen($cleanMobile) !== 10) {
            $errors[] = 'Mobile number must be exactly 10 digits if provided.';
        }

        if (empty($joining_date)) {
            $errors[] = 'Joining date is required.';
        }

        if (!empty($cleanMobile)) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE mobile = ? LIMIT 1");
            $chk->execute([$cleanMobile]);
            if ($chk->fetch()) {
                $errors[] = 'A user account already exists with this mobile number.';
            }
        }

        if ($hostel_status !== 'ACTIVE') {
            $room_no = null;
        }

        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                $studentCode = generate_student_code($pdo);

                $insStmt = $pdo->prepare("
                    INSERT INTO students (student_code, name, mobile, joining_date, mess_status, hostel_status, room_no, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insStmt->execute([
                    $studentCode,
                    $name,
                    $cleanMobile ?: null,
                    $joining_date,
                    $mess_status,
                    $hostel_status,
                    $room_no,
                    $status
                ]);
                $newStudentId = $pdo->lastInsertId();

                // If mobile was provided, create user login account
                if (!empty($cleanMobile)) {
                    $last6 = substr($cleanMobile, -6);
                    $hash = password_hash($last6, PASSWORD_DEFAULT);
                    $uStmt = $pdo->prepare("
                        INSERT INTO users (student_id, mobile, password_hash, role, first_login, status, created_at)
                        VALUES (?, ?, ?, 'student', 1, 'active', NOW())
                    ");
                    $uStmt->execute([$newStudentId, $cleanMobile, $hash]);
                }

                log_audit(
                    $pdo,
                    $adminId,
                    'MANUAL_ADD_STUDENT',
                    'STUDENT',
                    $newStudentId,
                    "Added student {$name} ({$studentCode})"
                );

                $pdo->commit();
                set_flash('success', "Student {$name} ({$studentCode}) added successfully!");
                header("Location: /admin/student-view.php?id={$newStudentId}");
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
                <div class="d-flex align-items-center justify-content-between pb-3 border-bottom mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <a href="/admin/students.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
                        <div>
                            <h4 class="fw-bold mb-0">Add Student Record</h4>
                            <small class="text-muted">Register a new student directly into the management system</small>
                        </div>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $e): ?>
                                <li><?= htmlspecialchars($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/admin/student-add.php" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required placeholder="e.g. Sk. Vasim" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="mobile" class="form-label">Mobile Number</label>
                            <input type="tel" class="form-control" id="mobile" name="mobile" maxlength="10" placeholder="10-digit mobile number" value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="joining_date" class="form-label">Joining Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="joining_date" name="joining_date" required value="<?= htmlspecialchars($_POST['joining_date'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="mess_status" class="form-label">Mess Status</label>
                            <select class="form-select" id="mess_status" name="mess_status">
                                <option value="ACTIVE" selected>ACTIVE</option>
                                <option value="INACTIVE">INACTIVE</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="hostel_status" class="form-label">Hostel Status</label>
                            <select class="form-select" id="hostel_status" name="hostel_status">
                                <option value="NOT_APPLICABLE" selected>NOT APPLICABLE</option>
                                <option value="ACTIVE">ACTIVE (Residential)</option>
                                <option value="INACTIVE">INACTIVE</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3" id="room_number_container" style="display: none;">
                        <label for="room_no" class="form-label">Room Number</label>
                        <input type="text" class="form-control" id="room_no" name="room_no" placeholder="e.g. Room 204 or Block B-101" value="<?= htmlspecialchars($_POST['room_no'] ?? '') ?>">
                    </div>

                    <div class="mb-4">
                        <label for="status" class="form-label">Account Enrolment Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="ACTIVE" selected>ACTIVE</option>
                            <option value="INACTIVE">INACTIVE</option>
                        </select>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4 fw-semibold"><i class="bi bi-save me-1"></i> Save Student</button>
                        <a href="/admin/students.php" class="btn btn-light border px-3">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
