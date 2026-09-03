<?php
/**
 * Admin AJAX Payment Actions (Verify, Reject, Direct Mark Paid, Bulk Mark Paid)
 * Mess & Hostel Management System
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth/middleware.php';

if (!is_logged_in() || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$pdo = get_db_connection();
$adminId = (int)$_SESSION['user_id'];
$action = sanitize_input($_POST['action'] ?? '');

// -------------------------------------------------------------
// 1. Direct Single Student Payment Recording
// -------------------------------------------------------------
if ($action === 'direct_student_payment') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $paymentMethod = sanitize_input($_POST['payment_method'] ?? 'CASH');
    $paidDate = sanitize_input($_POST['paid_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidDate)) {
        $paidDate = date('Y-m-d');
    }
    $paidTimestamp = $paidDate . ' ' . date('H:i:s');
    $notes = sanitize_input($_POST['notes'] ?? 'Direct offline payment recorded by Administration');
    $customRef = sanitize_input($_POST['reference_no'] ?? '');

    $stStmt = $pdo->prepare("SELECT s.*, u.id as user_id FROM students s LEFT JOIN users u ON u.student_id = s.id WHERE s.id = ? AND s.status = 'ACTIVE' LIMIT 1");
    $stStmt->execute([$studentId]);
    $student = $stStmt->fetch();

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Active student record not found.']);
        exit;
    }

    $cycle = get_student_current_cycle($pdo, $studentId, $paidDate);
    if (!$cycle) {
        echo json_encode(['success' => false, 'message' => 'Could not resolve student payment cycle.']);
        exit;
    }

    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : (float)$cycle['amount_due'];
    if ($amount <= 0) {
        $amount = (float)$cycle['amount_due'];
    }

    $serviceCovered = ($student['mess_status'] === 'ACTIVE' && $student['hostel_status'] === 'ACTIVE') ? 'BOTH' : ($student['hostel_status'] === 'ACTIVE' ? 'HOSTEL' : 'MESS');

    $pdo->beginTransaction();
    try {
        $utrNumber = !empty($customRef) ? $customRef : ('ADM-' . strtoupper($paymentMethod) . '-' . date('ymd', strtotime($paidDate)) . '-' . rand(1000, 9999));
        
        $chkUtr = $pdo->prepare("SELECT id FROM payment_transactions WHERE utr_number = ? LIMIT 1");
        $chkUtr->execute([$utrNumber]);
        if ($chkUtr->fetch()) {
            $utrNumber .= '-' . time();
        }

        $stmtMax = $pdo->query("SELECT MAX(id) as max_id FROM payment_transactions");
        $rowMax = $stmtMax->fetch();
        $nextId = ($rowMax && $rowMax['max_id']) ? ((int)$rowMax['max_id'] + 1) : 1;
        $txnCode = sprintf('TXN-%05d', $nextId);

        $payerUserId = !empty($student['user_id']) ? (int)$student['user_id'] : $adminId;

        // 1. Insert Payment Transaction
        $insTxn = $pdo->prepare("
            INSERT INTO payment_transactions (
                transaction_code, payer_user_id, billing_period, total_amount, payment_method, utr_number,
                cycle_summary, status, verified_by, verified_at, notes, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'COMPLETED', ?, ?, ?, ?, ?)
        ");
        $insTxn->execute([
            $txnCode,
            $payerUserId,
            "Cycle {$cycle['cycle_number']}",
            $amount,
            $paymentMethod,
            $utrNumber,
            "Cycle {$cycle['cycle_number']} ({$cycle['cycle_label']})",
            $adminId,
            $paidTimestamp,
            $notes,
            $paidTimestamp,
            $paidTimestamp
        ]);
        $newTxnId = (int)$pdo->lastInsertId();

        // 2. Insert or Update Student Payment Allocation
        $pendAllocStmt = $pdo->prepare("SELECT id FROM student_payment_allocations WHERE student_id = ? AND cycle_number = ? AND status = 'PENDING' LIMIT 1");
        $pendAllocStmt->execute([$studentId, $cycle['cycle_number']]);
        $existingPendAlloc = $pendAllocStmt->fetch();

        if ($existingPendAlloc) {
            $updAllocStmt = $pdo->prepare("
                UPDATE student_payment_allocations 
                SET payment_transaction_id = ?, amount_due = ?, allocated_amount = ?, status = 'COMPLETED', updated_at = ?
                WHERE id = ?
            ");
            $updAllocStmt->execute([$newTxnId, $amount, $amount, $paidTimestamp, $existingPendAlloc['id']]);
        } else {
            $insAlloc = $pdo->prepare("
                INSERT INTO student_payment_allocations (
                    payment_transaction_id, student_id, cycle_number, cycle_start_date, cycle_end_date,
                    due_date, amount_due, allocated_amount, service_covered, status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', ?, ?)
            ");
            $insAlloc->execute([
                $newTxnId,
                $studentId,
                $cycle['cycle_number'],
                $cycle['cycle_start_date'],
                $cycle['cycle_end_date'],
                $cycle['due_date'],
                $amount,
                $amount,
                $serviceCovered,
                $paidTimestamp,
                $paidTimestamp
            ]);
        }

        // 3. Update student's recorded monthly fee
        $updFee = $pdo->prepare("UPDATE students SET monthly_fee = ?, updated_at = NOW() WHERE id = ?");
        $updFee->execute([$amount, $studentId]);

        // 4. Send Notification to Student
        if (!empty($student['user_id'])) {
            add_notification(
                $pdo,
                $student['user_id'],
                'Direct Payment Confirmed',
                "Your payment of ₹" . number_format($amount, 2) . " for Cycle {$cycle['cycle_number']} ({$cycle['cycle_label']}) on " . format_display_date($paidDate) . " has been directly recorded and marked COMPLETED by Administration (Method: {$paymentMethod}, Ref: {$utrNumber})."
            );
        }

        // 5. Audit Log
        log_audit(
            $pdo,
            $adminId,
            'DIRECT_PAYMENT_ENTRY',
            'STUDENT',
            $studentId,
            "Admin directly marked payment completed for student {$student['student_code']} ({$student['name']}) - Cycle {$cycle['cycle_number']} ({$cycle['cycle_label']}) [₹" . number_format($amount, 2) . ", Method: {$paymentMethod}, Paid Date: {$paidDate}, Ref: {$utrNumber}]"
        );

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Payment of ₹" . number_format($amount, 2) . " for Cycle {$cycle['cycle_number']} recorded successfully on " . format_display_date($paidDate) . " and marked COMPLETED.",
            'transaction_code' => $txnCode,
            'utr_number' => $utrNumber,
            'amount' => $amount,
            'paid_date' => format_display_date($paidDate)
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// 2. Bulk Multi-Student Direct Payment Recording
// -------------------------------------------------------------
if ($action === 'bulk_direct_payment') {
    $rawIds = $_POST['student_ids'] ?? [];
    if (is_string($rawIds)) {
        $studentIds = json_decode($rawIds, true);
        if (!is_array($studentIds)) {
            $studentIds = array_filter(array_map('intval', explode(',', $rawIds)));
        }
    } else {
        $studentIds = array_filter(array_map('intval', (array)$rawIds));
    }

    if (empty($studentIds)) {
        echo json_encode(['success' => false, 'message' => 'Please select at least one student.']);
        exit;
    }

    $paymentMethod = sanitize_input($_POST['payment_method'] ?? 'CASH');
    $paidDate = sanitize_input($_POST['paid_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidDate)) {
        $paidDate = date('Y-m-d');
    }
    $paidTimestamp = $paidDate . ' ' . date('H:i:s');
    $notes = sanitize_input($_POST['notes'] ?? 'Bulk offline fee payment marked by Admin');
    $customRef = sanitize_input($_POST['reference_no'] ?? '');

    $pdo->beginTransaction();
    try {
        $processedCount = 0;
        $totalCollected = 0.00;

        foreach ($studentIds as $sId) {
            $stStmt = $pdo->prepare("SELECT s.*, u.id as user_id FROM students s LEFT JOIN users u ON u.student_id = s.id WHERE s.id = ? AND s.status = 'ACTIVE' LIMIT 1");
            $stStmt->execute([$sId]);
            $student = $stStmt->fetch();
            if (!$student) continue;

            $cycle = get_student_current_cycle($pdo, $sId, $paidDate);
            if (!$cycle) continue;

            $amount = (float)$cycle['amount_due'];
            if ($amount <= 0) $amount = 2500.00;

            $serviceCovered = ($student['mess_status'] === 'ACTIVE' && $student['hostel_status'] === 'ACTIVE') ? 'BOTH' : ($student['hostel_status'] === 'ACTIVE' ? 'HOSTEL' : 'MESS');

            $utrNumber = !empty($customRef) ? ($customRef . '-' . $sId) : ('ADM-' . strtoupper($paymentMethod) . '-' . date('ymd', strtotime($paidDate)) . '-' . rand(1000, 9999));
            
            $stmtMax = $pdo->query("SELECT MAX(id) as max_id FROM payment_transactions");
            $rowMax = $stmtMax->fetch();
            $nextId = ($rowMax && $rowMax['max_id']) ? ((int)$rowMax['max_id'] + 1) : 1;
            $txnCode = sprintf('TXN-%05d', $nextId);

            $payerUserId = !empty($student['user_id']) ? (int)$student['user_id'] : $adminId;

            // 1. Insert Transaction
            $insTxn = $pdo->prepare("
                INSERT INTO payment_transactions (
                    transaction_code, payer_user_id, billing_period, total_amount, payment_method, utr_number,
                    cycle_summary, status, verified_by, verified_at, notes, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'COMPLETED', ?, ?, ?, ?, ?)
            ");
            $insTxn->execute([
                $txnCode,
                $payerUserId,
                "Cycle {$cycle['cycle_number']}",
                $amount,
                $paymentMethod,
                $utrNumber,
                "Cycle {$cycle['cycle_number']} ({$cycle['cycle_label']})",
                $adminId,
                $paidTimestamp,
                $notes,
                $paidTimestamp,
                $paidTimestamp
            ]);
            $newTxnId = (int)$pdo->lastInsertId();

            // 2. Insert or Update Allocation
            $pendAllocStmt = $pdo->prepare("SELECT id FROM student_payment_allocations WHERE student_id = ? AND cycle_number = ? AND status = 'PENDING' LIMIT 1");
            $pendAllocStmt->execute([$sId, $cycle['cycle_number']]);
            $existingPendAlloc = $pendAllocStmt->fetch();

            if ($existingPendAlloc) {
                $updAllocStmt = $pdo->prepare("
                    UPDATE student_payment_allocations 
                    SET payment_transaction_id = ?, amount_due = ?, allocated_amount = ?, status = 'COMPLETED', updated_at = ?
                    WHERE id = ?
                ");
                $updAllocStmt->execute([$newTxnId, $amount, $amount, $paidTimestamp, $existingPendAlloc['id']]);
            } else {
                $insAlloc = $pdo->prepare("
                    INSERT INTO student_payment_allocations (
                        payment_transaction_id, student_id, cycle_number, cycle_start_date, cycle_end_date,
                        due_date, amount_due, allocated_amount, service_covered, status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', ?, ?)
                ");
                $insAlloc->execute([
                    $newTxnId,
                    $sId,
                    $cycle['cycle_number'],
                    $cycle['cycle_start_date'],
                    $cycle['cycle_end_date'],
                    $cycle['due_date'],
                    $amount,
                    $amount,
                    $serviceCovered,
                    $paidTimestamp,
                    $paidTimestamp
                ]);
            }

            // 3. Update student monthly fee
            $updFee = $pdo->prepare("UPDATE students SET monthly_fee = ?, updated_at = NOW() WHERE id = ?");
            $updFee->execute([$amount, $sId]);

            // 4. Send Notification
            if (!empty($student['user_id'])) {
                add_notification(
                    $pdo,
                    $student['user_id'],
                    'Direct Payment Confirmed',
                    "Your payment of ₹" . number_format($amount, 2) . " for Cycle {$cycle['cycle_number']} ({$cycle['cycle_label']}) on " . format_display_date($paidDate) . " has been recorded as COMPLETED by Administration."
                );
            }

            $processedCount++;
            $totalCollected += $amount;
        }

        log_audit(
            $pdo,
            $adminId,
            'BULK_DIRECT_PAYMENT',
            'STUDENTS',
            0,
            "Admin marked {$processedCount} students as paid on {$paidDate} [Total: ₹" . number_format($totalCollected, 2) . ", Method: {$paymentMethod}]"
        );

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => "Successfully marked {$processedCount} student(s) as PAID on " . format_display_date($paidDate) . " (Total: ₹" . number_format($totalCollected, 2) . ").",
            'processed_count' => $processedCount,
            'total_amount' => $totalCollected,
            'paid_date' => format_display_date($paidDate)
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// 3. Verify / Reject Student Submitted Payment Receipts
// -------------------------------------------------------------
$txnId = (int)($_POST['transaction_id'] ?? 0);
if ($txnId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id = ? LIMIT 1");
$stmt->execute([$txnId]);
$txn = $stmt->fetch();

if (!$txn) {
    echo json_encode(['success' => false, 'message' => 'Transaction not found.']);
    exit;
}

if ($txn['status'] !== 'PENDING') {
    echo json_encode(['success' => false, 'message' => 'Transaction has already been processed. Current status: ' . $txn['status']]);
    exit;
}

if ($action === 'mark_verified' || $action === 'mark_completed') {
    $pdo->beginTransaction();
    try {
        $updTxn = $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'COMPLETED', verified_by = ?, verified_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $updTxn->execute([$adminId, $txnId]);

        $updAlloc = $pdo->prepare("
            UPDATE student_payment_allocations 
            SET status = 'COMPLETED', updated_at = NOW()
            WHERE payment_transaction_id = ? AND status = 'PENDING'
        ");
        $updAlloc->execute([$txnId]);
        $affectedCount = $updAlloc->rowCount();

        add_notification(
            $pdo,
            $txn['payer_user_id'],
            'Payment Verified & Approved',
            "Your payment submission ({$txn['transaction_code']}, UTR: {$txn['utr_number']}, Amount: ₹{$txn['total_amount']}) has been verified and confirmed by Administration."
        );

        log_audit(
            $pdo,
            $adminId,
            'VERIFY_PAYMENT',
            'PAYMENT_TXN',
            $txnId,
            "Verified payment {$txn['transaction_code']} (UTR: {$txn['utr_number']}, Amount: ₹{$txn['total_amount']}) for {$affectedCount} student cycle(s)"
        );

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => "Payment verified successfully. {$affectedCount} student payment cycle(s) updated to COMPLETED.",
            'updated_count' => $affectedCount,
            'transaction_code' => $txn['transaction_code'],
            'verified_at' => format_display_date(date('Y-m-d'))
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
} elseif ($action === 'mark_rejected') {
    $reason = sanitize_input($_POST['rejection_reason'] ?? 'Transaction details or screenshot could not be verified.');
    $pdo->beginTransaction();
    try {
        $updTxn = $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'REJECTED', rejection_reason = ?, verified_by = ?, verified_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $updTxn->execute([$reason, $adminId, $txnId]);

        $updAlloc = $pdo->prepare("
            UPDATE student_payment_allocations 
            SET status = 'REJECTED', updated_at = NOW()
            WHERE payment_transaction_id = ?
        ");
        $updAlloc->execute([$txnId]);

        add_notification(
            $pdo,
            $txn['payer_user_id'],
            'Payment Submission Rejected',
            "Your payment submission ({$txn['transaction_code']}, UTR: {$txn['utr_number']}) was rejected. Reason: {$reason}."
        );

        log_audit(
            $pdo,
            $adminId,
            'REJECT_PAYMENT',
            'PAYMENT_TXN',
            $txnId,
            "Rejected payment {$txn['transaction_code']} (UTR: {$txn['utr_number']}). Reason: {$reason}"
        );

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Payment transaction has been rejected.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
