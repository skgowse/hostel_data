<?php
/**
 * Dynamic JSON Endpoint for Filtered Students by Due Date & Cycle Status
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

$pdo = get_db_connection();

$statusFilter = sanitize_input($_GET['status'] ?? 'ALL');
$serviceFilter = sanitize_input($_GET['service'] ?? 'ALL');
$fromDueDate = sanitize_input($_GET['from_due_date'] ?? '');
$toDueDate = sanitize_input($_GET['to_due_date'] ?? '');
$search = sanitize_input($_GET['q'] ?? '');

// Base query for active students
$sql = "SELECT s.*, u.id as user_id, u.mobile as user_mobile 
        FROM students s 
        LEFT JOIN users u ON u.student_id = s.id 
        WHERE s.status = 'ACTIVE'";

if ($serviceFilter === 'MESS') {
    $sql .= " AND s.mess_status = 'ACTIVE' AND (s.hostel_status IS NULL OR s.hostel_status != 'ACTIVE')";
} elseif ($serviceFilter === 'HOSTEL') {
    $sql .= " AND s.hostel_status = 'ACTIVE' AND (s.mess_status IS NULL OR s.mess_status != 'ACTIVE')";
} elseif ($serviceFilter === 'BOTH') {
    $sql .= " AND s.mess_status = 'ACTIVE' AND s.hostel_status = 'ACTIVE'";
}

if (!empty($search)) {
    $sql .= " AND (s.name LIKE :term1 OR s.student_code LIKE :term2 OR s.mobile LIKE :term3)";
}

$stmt = $pdo->prepare($sql);
if (!empty($search)) {
    $term = "%{$search}%";
    $stmt->execute([
        'term1' => $term,
        'term2' => $term,
        'term3' => $term
    ]);
} else {
    $stmt->execute();
}
$students = $stmt->fetchAll();

// Total collections in system
$totalCollected = (float)$pdo->query("SELECT COALESCE(SUM(allocated_amount), 0) FROM student_payment_allocations WHERE status = 'COMPLETED'")->fetchColumn();

// Overall Metrics
$dueTodayCount = 0;
$overdueCount = 0;
$due7DaysCount = 0;
$due30DaysCount = 0;
$pendingCount = 0;
$completedCount = 0;

$filteredList = [];
$today = date('Y-m-d');

foreach ($students as $st) {
    $cycle = get_student_current_cycle($pdo, $st['id'], $today);
    if (!$cycle) continue;

    $stStatus = $cycle['status'];
    $daysDiff = $cycle['days_diff'];

    // Update global metrics
    if ($stStatus === 'PENDING_VERIFICATION') {
        $pendingCount++;
    } elseif ($stStatus === 'DUE') {
        $dueTodayCount++;
    } elseif ($stStatus === 'OVERDUE') {
        $overdueCount++;
    } elseif ($stStatus === 'UPCOMING') {
        if ($daysDiff <= 7) $due7DaysCount++;
        if ($daysDiff <= 30) $due30DaysCount++;
    }

    // Check if student has completed cycles
    $compAllocStmt = $pdo->prepare("
        SELECT a.*, t.payment_method, t.utr_number, t.transaction_code, t.verified_at, t.created_at as txn_date
        FROM student_payment_allocations a
        JOIN payment_transactions t ON a.payment_transaction_id = t.id
        WHERE a.student_id = ? AND a.status = 'COMPLETED'
        ORDER BY a.cycle_number DESC, a.id DESC
    ");
    $compAllocStmt->execute([$st['id']]);
    $completedAllocations = $compAllocStmt->fetchAll();

    if (!empty($completedAllocations)) {
        $completedCount += count($completedAllocations);
    }

    // If viewing COMPLETED payments filter:
    if ($statusFilter === 'COMPLETED') {
        if (empty($completedAllocations)) continue;

        foreach ($completedAllocations as $ca) {
            $cycleStartFormatted = format_display_date($ca['cycle_start_date']);
            $cycleEndFormatted = format_display_date($ca['cycle_end_date']);
            $cycleLabel = "{$cycleStartFormatted} → {$cycleEndFormatted}";
            $paidDate = !empty($ca['verified_at']) ? date('Y-m-d', strtotime($ca['verified_at'])) : date('Y-m-d', strtotime($ca['txn_date']));
            $paidDateFormatted = format_display_date($paidDate);

            // Due date range filter
            if (!empty($fromDueDate) && $ca['due_date'] < $fromDueDate) continue;
            if (!empty($toDueDate) && $ca['due_date'] > $toDueDate) continue;

            $filteredList[] = [
                'id'                     => (int)$st['id'],
                'student_code'           => $st['student_code'],
                'name'                   => $st['name'],
                'mobile'                 => $st['mobile'] ?: '—',
                'raw_joining_date'       => $st['joining_date'],
                'joining_date'           => format_display_date($st['joining_date']),
                'cycle_number'           => (int)$ca['cycle_number'],
                'cycle_label'            => $cycleLabel,
                'due_date'               => $ca['due_date'],
                'due_date_formatted'     => format_display_date($ca['due_date']),
                'service_label'          => $cycle['service_label'],
                'room_no'                => $st['room_no'] ?: '—',
                'applicable_fee'         => (float)$ca['allocated_amount'],
                'applicable_fee_formatted' => '₹' . number_format((float)$ca['allocated_amount'], 2),
                'status'                 => 'COMPLETED',
                'status_label'           => 'Paid on ' . $paidDateFormatted,
                'days_diff'              => 0,
                'days_text'              => 'Paid on ' . $paidDateFormatted . ' (' . $ca['payment_method'] . ')',
                'pending_allocation'     => null,
                'paid_date'              => $paidDateFormatted,
                'transaction_code'       => $ca['transaction_code']
            ];
        }
        continue;
    }

    // Apply Filter Criteria for Active / Unpaid Cycles
    if ($statusFilter === 'DUE_TODAY' && $stStatus !== 'DUE') continue;
    if ($statusFilter === 'OVERDUE' && $stStatus !== 'OVERDUE') continue;
    if ($statusFilter === 'DUE_7_DAYS' && !($daysDiff >= 0 && $daysDiff <= 7 && $stStatus !== 'PENDING_VERIFICATION')) continue;
    if ($statusFilter === 'DUE_30_DAYS' && !($daysDiff >= 0 && $daysDiff <= 30 && $stStatus !== 'PENDING_VERIFICATION')) continue;
    if ($statusFilter === 'PENDING' && $stStatus !== 'PENDING_VERIFICATION') continue;

    // Due date range filter
    if (!empty($fromDueDate) && $cycle['due_date'] < $fromDueDate) continue;
    if (!empty($toDueDate) && $cycle['due_date'] > $toDueDate) continue;

    $lastPaidInfo = null;
    if (!empty($completedAllocations)) {
        $latestPaid = $completedAllocations[0];
        $pDate = !empty($latestPaid['verified_at']) ? date('Y-m-d', strtotime($latestPaid['verified_at'])) : date('Y-m-d', strtotime($latestPaid['txn_date']));
        $lastPaidInfo = 'Cycle ' . $latestPaid['cycle_number'] . ' Paid (₹' . number_format($latestPaid['allocated_amount'], 2) . ' on ' . format_display_date($pDate) . ')';
    }

    $filteredList[] = [
        'id'                     => $cycle['student_id'],
        'student_code'           => $cycle['student_code'],
        'name'                   => $cycle['student_name'],
        'mobile'                 => $st['mobile'] ?: '—',
        'raw_joining_date'       => $st['joining_date'],
        'joining_date'           => $cycle['joining_date_formatted'],
        'cycle_number'           => $cycle['cycle_number'],
        'cycle_label'            => $cycle['cycle_label'],
        'due_date'               => $cycle['due_date'],
        'due_date_formatted'     => $cycle['due_date_formatted'],
        'service_label'          => $cycle['service_label'],
        'room_no'                => $st['room_no'] ?: '—',
        'applicable_fee'         => $cycle['amount_due'],
        'applicable_fee_formatted' => $cycle['amount_due_formatted'],
        'status'                 => $cycle['status'],
        'status_label'           => $cycle['status_label'],
        'days_diff'              => $cycle['days_diff'],
        'days_text'              => $cycle['days_text'],
        'pending_allocation'     => $cycle['pending_allocation'],
        'last_paid_info'         => $lastPaidInfo
    ];
}

// Ensure strict chronological ascending order by joining date, then student ID
usort($filteredList, function ($a, $b) {
    $cmp = strcmp($a['raw_joining_date'], $b['raw_joining_date']);
    if ($cmp !== 0) return $cmp;
    return (int)$a['id'] - (int)$b['id'];
});

echo json_encode([
    'success' => true,
    'today' => format_display_date($today),
    'summary' => [
        'total_active_students' => count($students),
        'due_today_count'       => $dueTodayCount,
        'overdue_count'         => $overdueCount,
        'due_7_days_count'      => $due7DaysCount,
        'due_30_days_count'     => $due30DaysCount,
        'pending_count'         => $pendingCount,
        'completed_count'       => $completedCount,
        'amount_collected'      => $totalCollected,
        'amount_collected_formatted' => '₹' . number_format($totalCollected, 2)
    ],
    'total_matching' => count($filteredList),
    'students' => $filteredList
]);
