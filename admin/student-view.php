<?php
/**
 * Admin: Student Details View & Direct Payment Management
 * Mess & Hostel Management System
 */

$pageTitle = 'Student Details';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth/middleware.php';

require_admin();

$pdo = get_db_connection();
$adminId = $_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

// Handle Admin Permanent Student Deletion / Dropout from View Page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_student') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        set_flash('danger', 'Security token expired. Please try again.');
        header("Location: /admin/student-view.php?id={$id}");
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
        $pdo->prepare("DELETE FROM student_payment_allocations WHERE student_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM name_change_requests WHERE student_id = ?")->execute([$delId]);

        if (!empty($stToDel['user_id'])) {
            $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$stToDel['user_id']]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$stToDel['user_id']]);
        }

        if (!empty($stToDel['mobile'])) {
            $pdo->prepare("DELETE FROM registration_requests WHERE mobile = ?")->execute([$stToDel['mobile']]);
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
        header("Location: /admin/student-view.php?id={$id}");
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT s.*, u.id as user_id, u.mobile as user_mobile, u.first_login, u.status as user_status, u.created_at as user_created_at
    FROM students s
    LEFT JOIN users u ON u.student_id = s.id
    WHERE s.id = ? LIMIT 1
");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    set_flash('danger', 'Student record not found.');
    header('Location: /admin/students.php');
    exit;
}

// Fetch Active Recurring Payment Cycle
$currentCycle = get_student_current_cycle($pdo, $id);

// Fetch Payment History for this Student
$payHistoryStmt = $pdo->prepare("
    SELECT a.*, t.transaction_code, t.payment_method, t.utr_number, t.notes
    FROM student_payment_allocations a
    JOIN payment_transactions t ON a.payment_transaction_id = t.id
    WHERE a.student_id = ?
    ORDER BY a.cycle_number DESC
");
$payHistoryStmt->execute([$id]);
$payHistory = $payHistoryStmt->fetchAll();

// Fetch audit logs related to this student
$auditStmt = $pdo->prepare("
    SELECT a.*, u.name as admin_name 
    FROM audit_logs a 
    LEFT JOIN users u ON a.admin_id = u.id 
    WHERE a.target_type = 'STUDENT' AND a.target_id = ? 
    ORDER BY a.id DESC LIMIT 10
");
$auditStmt->execute([$id]);
$auditLogs = $auditStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="row g-4 justify-content-center">
        <div class="col-lg-9">
            <!-- Student Header Card -->
            <div class="card border-0 shadow-sm rounded-4 bg-white p-4 p-md-5 mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 border-bottom mb-4 gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <a href="/admin/students.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> All Students</a>
                        <div>
                            <span class="text-uppercase text-muted fw-bold small">Student File</span>
                            <h3 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($student['name']) ?></h3>
                            <span class="badge bg-light text-primary border font-monospace"><?= htmlspecialchars($student['student_code']) ?></span>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if ($currentCycle): ?>
                            <button type="button" class="btn btn-success btn-sm px-3 fw-semibold"
                                    onclick="openDirectPaymentModal('<?= $student['id'] ?>', '<?= htmlspecialchars(addslashes($student['student_code'])) ?>', '<?= htmlspecialchars(addslashes($student['name'])) ?>', '<?= $currentCycle['amount_due'] ?>', '<?= $currentCycle['cycle_number'] ?>', '<?= htmlspecialchars(addslashes($currentCycle['cycle_label'])) ?>', '<?= $currentCycle['due_date_formatted'] ?>')">
                                <i class="bi bi-cash-stack me-1"></i> Mark Paid / Record Payment
                            </button>
                        <?php endif; ?>
                        <a href="/admin/student-edit.php?id=<?= $student['id'] ?>" class="btn btn-primary btn-sm px-3 fw-semibold">
                            <i class="bi bi-pencil me-1"></i> Edit
                        </a>
                        <button type="button" class="btn btn-outline-danger btn-sm px-3 fw-semibold"
                                onclick="openDeleteStudentModal('<?= $student['id'] ?>', '<?= htmlspecialchars(addslashes($student['student_code'])) ?>', '<?= htmlspecialchars(addslashes($student['name'])) ?>')">
                            <i class="bi bi-trash-fill me-1"></i> Delete / Drop Out
                        </button>
                    </div>
                </div>

                <!-- Active Cycle Billing Banner -->
                <?php if ($currentCycle): ?>
                    <div class="alert alert-light border rounded-3 p-4 mb-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <small class="text-uppercase text-muted fw-bold d-block">Active Recurring Billing Cycle</small>
                                <h5 class="fw-bold text-dark mb-1">
                                    Cycle <?= $currentCycle['cycle_number'] ?>: <?= $currentCycle['cycle_label'] ?>
                                </h5>
                                <div class="small text-muted">
                                    Joining Date Anchor: <strong class="text-dark"><?= $currentCycle['joining_date_formatted'] ?></strong>
                                    &bull; Due Date: <strong class="text-danger font-monospace"><?= $currentCycle['due_date_formatted'] ?></strong>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fs-4 fw-bold text-success font-monospace"><?= $currentCycle['amount_due_formatted'] ?></div>
                                <div>
                                    <?php if ($currentCycle['status'] === 'OVERDUE'): ?>
                                        <span class="badge bg-danger"><i class="bi bi-exclamation-octagon me-1"></i>OVERDUE (<?= $currentCycle['days_text'] ?>)</span>
                                    <?php elseif ($currentCycle['status'] === 'DUE'): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-bell-fill me-1"></i>DUE TODAY</span>
                                    <?php elseif ($currentCycle['status'] === 'PENDING_VERIFICATION'): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border"><i class="bi bi-hourglass-split me-1"></i>PENDING REVIEW</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-calendar-check me-1"></i>UPCOMING (<?= $currentCycle['days_text'] ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <small class="text-muted text-uppercase fw-semibold d-block mb-1">Mess Service</small>
                            <?php if ($student['mess_status'] === 'ACTIVE'): ?>
                                <span class="badge badge-status-active fs-6 px-3 py-2"><i class="bi bi-check-circle-fill me-1"></i>ACTIVE</span>
                            <?php else: ?>
                                <span class="badge badge-status-inactive fs-6 px-3 py-2">INACTIVE</span>
                            <?php endif; ?>
                            <div class="small text-muted mt-2">Dining membership status (₹2,500/mo)</div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <small class="text-muted text-uppercase fw-semibold d-block mb-1">Hostel Residence</small>
                            <?php if ($student['hostel_status'] === 'ACTIVE'): ?>
                                <span class="badge badge-status-active fs-6 px-3 py-2"><i class="bi bi-house-check-fill me-1"></i>ACTIVE</span>
                                <div class="mt-2 fw-bold text-dark">Room: <?= htmlspecialchars($student['room_no'] ?: 'Allocated') ?></div>
                            <?php else: ?>
                                <span class="badge badge-status-na fs-6 px-3 py-2">NOT APPLICABLE</span>
                                <div class="small text-muted mt-2">Mess-only / Day-scholar</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <h5 class="fw-bold mb-3 pb-2 border-bottom">Student Particulars</h5>
                <div class="row g-3 mb-4" style="font-size: 0.95rem;">
                    <div class="col-sm-6">
                        <span class="text-muted d-block small">Student Code / ID</span>
                        <strong class="font-monospace text-primary"><?= htmlspecialchars($student['student_code']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <span class="text-muted d-block small">Joining Date (Billing Anchor)</span>
                        <strong><?= format_display_date($student['joining_date']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <span class="text-muted d-block small">Contact Mobile Number</span>
                        <span class="font-monospace"><?= htmlspecialchars($student['mobile'] ?: 'Not Provided') ?></span>
                    </div>
                    <div class="col-sm-6">
                        <span class="text-muted d-block small">Enrollment Status</span>
                        <?php if ($student['status'] === 'ACTIVE'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">ACTIVE</span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= $student['status'] ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Ledger History -->
                <h5 class="fw-bold mb-3 pb-2 border-bottom"><i class="bi bi-clock-history text-primary me-2"></i>Payment History</h5>
                <?php if (empty($payHistory)): ?>
                    <p class="text-muted small mb-4">No completed payments recorded for this student yet.</p>
                <?php else: ?>
                    <div class="table-responsive mb-4">
                        <table class="table custom-table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                            <thead>
                                <tr>
                                    <th>Cycle</th>
                                    <th>Period</th>
                                    <th>Due Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>UTR / Reference</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payHistory as $ph): ?>
                                    <tr>
                                        <td class="font-monospace fw-bold">Cycle <?= $ph['cycle_number'] ?></td>
                                        <td class="small text-muted"><?= format_display_date($ph['cycle_start_date']) ?> &rarr; <?= format_display_date($ph['cycle_end_date']) ?></td>
                                        <td class="font-monospace text-danger"><?= format_display_date($ph['due_date']) ?></td>
                                        <td class="fw-bold text-success font-monospace">₹<?= number_format($ph['allocated_amount'], 2) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($ph['payment_method']) ?></span></td>
                                        <td class="font-monospace small"><?= htmlspecialchars($ph['utr_number']) ?></td>
                                        <td>
                                            <?php if ($ph['status'] === 'COMPLETED'): ?>
                                                <span class="badge badge-status-active"><i class="bi bi-check-circle-fill me-1"></i>COMPLETED</span>
                                            <?php elseif ($ph['status'] === 'PENDING'): ?>
                                                <span class="badge badge-status-pending">PENDING</span>
                                            <?php else: ?>
                                                <span class="badge badge-status-rejected">REJECTED</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <h5 class="fw-bold mb-3 pb-2 border-bottom">Audit Activity Log</h5>
                <?php if (empty($auditLogs)): ?>
                    <p class="text-muted small">No administrative changes recorded for this student.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush border-top">
                        <?php foreach ($auditLogs as $log): ?>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="text-dark small"><?= htmlspecialchars($log['action']) ?></strong>
                                    <span class="text-muted small"><?= format_display_date($log['created_at']) ?> <?= date('H:i', strtotime($log['created_at'])) ?></span>
                                </div>
                                <div class="small text-secondary"><?= htmlspecialchars($log['description']) ?></div>
                                <small class="text-muted">By: <?= htmlspecialchars($log['admin_name'] ?: 'System') ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Direct / Offline Payment Entry (Mark Student Paid Directly) -->
<div class="modal fade" id="directPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="directPaymentForm">
                <?= csrf_field() ?>
                <input type="hidden" id="directPayStudentId">

                <div class="modal-header bg-success text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="bi bi-cash-stack me-2"></i>Record Payment / Mark as Paid</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong id="directPayStudentName" class="fs-6 text-dark"></strong>
                            <span id="directPayStudentCode" class="font-monospace text-primary fw-bold"></span>
                        </div>
                        <div class="small text-muted">
                            Active Cycle: <span id="directPayCycleLabel" class="fw-semibold text-dark"></span>
                            <br>
                            Due Date: <span id="directPayDueDate" class="text-danger fw-bold"></span>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="directPayMethod" class="form-label fw-bold">Payment Method</label>
                            <select class="form-select" id="directPayMethod" required>
                                <option value="CASH" selected>Cash Payment</option>
                                <option value="UPI">UPI / GPay / PhonePe</option>
                                <option value="BANK_TRANSFER">Direct Bank Transfer / NEFT</option>
                                <option value="POS">Card / POS Swipe</option>
                                <option value="CHEQUE">Cheque / Demand Draft</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="directPayAmount" class="form-label fw-bold">Amount Paid (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control font-monospace fw-bold text-success" id="directPayAmount" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="directPayNotes" class="form-label fw-bold">Receipt Reference / Note</label>
                        <input type="text" class="form-control" id="directPayNotes" placeholder="e.g. Cash collected in Warden Office, Receipt #102">
                    </div>

                    <div class="mb-3">
                        <label for="directPayDate" class="form-label fw-bold">Payment Date</label>
                        <input type="date" class="form-control" id="directPayDate" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 pt-0">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="confirmDirectPayBtn" class="btn btn-success px-4 fw-semibold">
                        <i class="bi bi-check-circle-fill me-1"></i> Confirm & Mark Completed
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Permanently Delete / Drop Out Student -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form method="POST" action="/admin/student-view.php?id=<?= $student['id'] ?>">
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
