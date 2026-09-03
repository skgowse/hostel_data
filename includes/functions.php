<?php
/**
 * Helper Functions & Utilities
 * Mess & Hostel Management System
 */

require_once __DIR__ . '/../config/database.php';

// CSRF Protection
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Input Sanitization
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return trim(htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8'));
}

// Date Formatter: YYYY-MM-DD -> 02-Aug-2026
function format_display_date($dateStr) {
    if (empty($dateStr) || $dateStr === '0000-00-00') {
        return 'N/A';
    }
    $timestamp = strtotime($dateStr);
    if (!$timestamp) {
        return htmlspecialchars((string)$dateStr);
    }
    return date('d-M-Y', $timestamp);
}

// Phone Number Masking: 9876543210 -> 98******10
function mask_phone($phone) {
    $clean = preg_replace('/[^0-9]/', '', (string)$phone);
    $len = strlen($clean);
    if ($len < 6) {
        return $phone ?: 'Not provided';
    }
    $firstTwo = substr($clean, 0, 2);
    $lastTwo = substr($clean, -2);
    $stars = str_repeat('*', $len - 4);
    return $firstTwo . $stars . $lastTwo;
}

// Unique Request ID Generator: REQ-00001
function generate_request_id($pdo) {
    $stmt = $pdo->query("SELECT MAX(id) as max_id FROM registration_requests");
    $row = $stmt->fetch();
    $next = ($row && $row['max_id']) ? ((int)$row['max_id'] + 1) : 1;
    return sprintf('REQ-%05d', $next);
}

// Unique Student Code Generator: STU-00001
function generate_student_code($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(student_code, 5) AS UNSIGNED)) as max_code, MAX(id) as max_id FROM students WHERE student_code LIKE 'STU-%'");
    $row = $stmt->fetch();
    $maxCode = ($row && $row['max_code']) ? (int)$row['max_code'] : 0;
    $maxId = ($row && $row['max_id']) ? (int)$row['max_id'] : 0;
    $next = max($maxCode, $maxId) + 1;
    return sprintf('STU-%05d', $next);
}

// Flash Messages
function set_flash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type, // success, danger, warning, info
        'message' => $message
    ];
}

function get_flash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Audit Logger
function log_audit($pdo, $admin_id, $action, $target_type, $target_id, $description) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (admin_id, action, target_type, target_id, description)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$admin_id, $action, $target_type, $target_id, $description]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}

// Notifications Helper
function add_notification($pdo, $user_id, $title, $message) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$user_id, $title, $message]);
    } catch (Exception $e) {
        error_log("Notification failed: " . $e->getMessage());
    }
}

// -------------------------------------------------------------
// Joining-Date-Based Individual Recurring Payment Cycle Engine
// -------------------------------------------------------------

/**
 * Calculates a recurring cycle date anchored to the student's joining date.
 * Accurately handles variable month lengths with end-of-month clamping.
 *
 * Example:
 * calculate_cycle_date('2026-08-02', 1) => '2026-09-02'
 * calculate_cycle_date('2026-01-31', 1) => '2026-02-28'
 */
function calculate_cycle_date($baseDate, $monthOffset) {
    $time = strtotime($baseDate);
    if (!$time) {
        $time = time();
    }
    
    $origDay = (int)date('j', $time);
    $origMonth = (int)date('n', $time);
    $origYear = (int)date('Y', $time);

    // Calculate target year and month
    $totalMonths = ($origYear * 12 + ($origMonth - 1)) + $monthOffset;
    $targetYear = intdiv($totalMonths, 12);
    $targetMonth = ($totalMonths % 12) + 1;

    // Days in target month
    $daysInTargetMonth = cal_days_in_month(CAL_GREGORIAN, $targetMonth, $targetYear);
    $targetDay = min($origDay, $daysInTargetMonth);

    return sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, $targetDay);
}

/**
 * Fetches applicable monthly fee for a student based on active services
 */
function get_student_applicable_fee($pdo, $student) {
    static $feePlans = null;
    if ($feePlans === null) {
        $stmt = $pdo->query("SELECT service_type, monthly_amount FROM fee_plans");
        $feePlans = [];
        while ($r = $stmt->fetch()) {
            $feePlans[$r['service_type']] = (float)$r['monthly_amount'];
        }
    }

    $isMess = ($student['mess_status'] === 'ACTIVE');
    $isHostel = ($student['hostel_status'] === 'ACTIVE');

    $defaultAmount = 2500.00;
    $serviceType = 'MESS';
    $serviceLabel = 'Central Dining Mess Only';

    if ($isMess && $isHostel) {
        $serviceType = 'BOTH';
        $defaultAmount = $feePlans['BOTH'] ?? 5500.00;
        $serviceLabel = 'Mess + Residential Hostel';
    } elseif ($isHostel) {
        $serviceType = 'HOSTEL';
        $defaultAmount = $feePlans['HOSTEL'] ?? 3000.00;
        $serviceLabel = 'Hostel Residence Only';
    } else {
        $serviceType = 'MESS';
        $defaultAmount = $feePlans['MESS'] ?? 2500.00;
        $serviceLabel = 'Central Dining Mess Only';
    }

    // If student has an explicit updated monthly fee
    if (isset($student['monthly_fee']) && $student['monthly_fee'] !== null && (float)$student['monthly_fee'] > 0) {
        return [
            'service' => $serviceType,
            'amount'  => (float)$student['monthly_fee'],
            'label'   => $serviceLabel
        ];
    }

    return [
        'service' => $serviceType,
        'amount'  => $defaultAmount,
        'label'   => $serviceLabel
    ];
}

/**
 * Resolves a student's current active joining-date payment cycle and due date status.
 */
function get_student_current_cycle($pdo, $studentId, $refDate = null) {
    if (!$refDate) {
        $refDate = date('Y-m-d');
    }

    $stuStmt = $pdo->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
    $stuStmt->execute([$studentId]);
    $student = $stuStmt->fetch();

    if (!$student) {
        return null;
    }

    $joiningDate = $student['joining_date'];
    $feeInfo = get_student_applicable_fee($pdo, $student);

    // Fetch highest verified completed cycle
    $compStmt = $pdo->prepare("
        SELECT MAX(cycle_number) as max_completed 
        FROM student_payment_allocations 
        WHERE student_id = ? AND status = 'COMPLETED'
    ");
    $compStmt->execute([$studentId]);
    $maxCompleted = (int)($compStmt->fetchColumn() ?: 0);

    $currentCycleNum = $maxCompleted + 1;
    $cycleStartDate = calculate_cycle_date($joiningDate, $currentCycleNum - 1);
    $cycleEndDate = calculate_cycle_date($joiningDate, $currentCycleNum);
    $dueDate = $cycleEndDate; // Due when 1 month has completed

    // Check if there is a pending submission for this cycle
    $pendStmt = $pdo->prepare("
        SELECT a.*, t.transaction_code, t.utr_number, t.created_at as submitted_at
        FROM student_payment_allocations a
        JOIN payment_transactions t ON a.payment_transaction_id = t.id
        WHERE a.student_id = ? AND a.cycle_number = ? AND a.status = 'PENDING'
        LIMIT 1
    ");
    $pendStmt->execute([$studentId, $currentCycleNum]);
    $pendingAlloc = $pendStmt->fetch();

    // Calculate days difference
    $refTimestamp = strtotime($refDate);
    $dueTimestamp = strtotime($dueDate);
    $daysDiff = (int)round(($dueTimestamp - $refTimestamp) / 86400);

    $status = 'UPCOMING';
    $statusLabel = 'Upcoming';
    $daysText = '';

    if ($pendingAlloc) {
        $status = 'PENDING_VERIFICATION';
        $statusLabel = 'Pending Verification';
        $daysText = 'Receipt submitted; awaiting admin review';
    } else {
        if ($daysDiff > 0) {
            $status = 'UPCOMING';
            $statusLabel = 'Upcoming';
            $daysText = "Due in {$daysDiff} day" . ($daysDiff > 1 ? 's' : '');
        } elseif ($daysDiff === 0) {
            $status = 'DUE';
            $statusLabel = 'Due Today';
            $daysText = 'Payment is due today';
        } else {
            $overdueDays = abs($daysDiff);
            $status = 'OVERDUE';
            $statusLabel = 'Overdue';
            $daysText = "Overdue by {$overdueDays} day" . ($overdueDays > 1 ? 's' : '');
        }
    }

    $cycleLabel = format_display_date($cycleStartDate) . ' → ' . format_display_date($cycleEndDate);
    $cycleTitle = "Cycle {$currentCycleNum}: {$cycleLabel}";

    return [
        'student_id'          => (int)$student['id'],
        'student_code'        => $student['student_code'],
        'student_name'        => $student['name'],
        'joining_date'        => $joiningDate,
        'joining_date_formatted' => format_display_date($joiningDate),
        'cycle_number'        => $currentCycleNum,
        'cycle_start_date'    => $cycleStartDate,
        'cycle_end_date'      => $cycleEndDate,
        'due_date'            => $dueDate,
        'due_date_formatted'  => format_display_date($dueDate),
        'cycle_label'         => $cycleLabel,
        'cycle_title'         => $cycleTitle,
        'amount_due'          => $feeInfo['amount'],
        'amount_due_formatted'=> '₹' . number_format($feeInfo['amount'], 2),
        'service'             => $feeInfo['service'],
        'service_label'       => $feeInfo['label'],
        'status'              => $status,
        'status_label'        => $statusLabel,
        'days_diff'           => $daysDiff,
        'days_text'           => $daysText,
        'pending_allocation'  => $pendingAlloc ? [
            'transaction_code' => $pendingAlloc['transaction_code'],
            'utr_number'       => $pendingAlloc['utr_number'],
            'submitted_at'     => format_display_date($pendingAlloc['submitted_at'])
        ] : null
    ];
}

