<?php
/**
 * Admin: CSV Export Engine
 * Mess & Hostel Management System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth/middleware.php';

require_admin();

$pdo = get_db_connection();

$type = sanitize_input($_GET['type'] ?? 'students');
$filename = 'students_export_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
// Output UTF-8 BOM so Excel opens it with proper encoding
fputs($output, "\xEF\xBB\xBF");

// Student Exports (Standard, Date-wise, Mess, Hostel, Both, Daily, Range)
    fputcsv($output, ['Student Code', 'Student Name', 'Mobile Number', 'Joining Date', 'Mess Status', 'Hostel Status', 'Room Number', 'Enrolment Status', 'Record Created']);

    if ($type === 'date_records' || $type === 'daily') {
        $date = sanitize_input($_GET['date'] ?? date('Y-m-d'));
        $stmt = $pdo->prepare("SELECT * FROM students WHERE joining_date = ? ORDER BY id ASC");
        $stmt->execute([$date]);
    } elseif ($type === 'range') {
        $fromDate = sanitize_input($_GET['from_date'] ?? date('Y-m-01'));
        $toDate = sanitize_input($_GET['to_date'] ?? date('Y-m-d'));
        $stmt = $pdo->prepare("SELECT * FROM students WHERE joining_date BETWEEN ? AND ? ORDER BY joining_date ASC, id ASC");
        $stmt->execute([$fromDate, $toDate]);
    } elseif ($type === 'mess') {
        $stmt = $pdo->query("SELECT * FROM students WHERE mess_status = 'ACTIVE' AND status = 'ACTIVE' ORDER BY name ASC");
    } elseif ($type === 'hostel') {
        $stmt = $pdo->query("SELECT * FROM students WHERE hostel_status = 'ACTIVE' AND status = 'ACTIVE' ORDER BY room_no ASC, name ASC");
    } elseif ($type === 'both') {
        $stmt = $pdo->query("SELECT * FROM students WHERE mess_status = 'ACTIVE' AND hostel_status = 'ACTIVE' AND status = 'ACTIVE' ORDER BY name ASC");
    } else {
        // Full Students list with filters if passed
        $stmt = $pdo->query("SELECT * FROM students ORDER BY name ASC");
    }

    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['student_code'],
            $row['name'],
            $row['mobile'] ?: '',
            format_display_date($row['joining_date']),
            $row['mess_status'],
            $row['hostel_status'],
            $row['room_no'] ?: '',
            $row['status'],
            $row['created_at']
        ]);
    }

fclose($output);
exit;
