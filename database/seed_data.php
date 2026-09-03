<?php
/**
 * Database Migration & 113-Student Seed Script
 * Mess & Hostel Management System
 */

require_once __DIR__ . '/../config/database.php';

echo "--- Starting Database Setup & Seeding ---\n";

// Step 1: Connect to server without DB first to create database
try {
    $raw_pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $raw_pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    echo "[OK] Database `" . DB_NAME . "` created / verified.\n";
} catch (Exception $e) {
    die("[FAIL] Could not create database: " . $e->getMessage() . "\n");
}

// Step 2: Connect using main helper
$pdo = get_db_connection();

// Step 3: Run database.sql
$sqlFile = __DIR__ . '/database.sql';
if (!file_exists($sqlFile)) {
    die("[FAIL] database.sql not found at $sqlFile\n");
}
$sql = file_get_contents($sqlFile);
// Strip UTF-8 BOM if present
$sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
$pdo->exec($sql);
echo "[OK] Tables created successfully.\n";

// Step 4: Seed Default Admin User
$adminMobile = '9999999999';
$adminPass = 'admin123';
$adminHash = password_hash($adminPass, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO `users` (`mobile`, `password_hash`, `role`, `first_login`, `status`) VALUES (?, ?, 'admin', 0, 'active')");
$stmt->execute([$adminMobile, $adminHash]);
$adminUserId = $pdo->lastInsertId();
echo "[OK] Default Admin created (Mobile: $adminMobile | Password: $adminPass)\n";

// Step 5: Parse and seed 113 students from entries_1_to_113.xlsx
$xlsxFile = __DIR__ . '/entries_1_to_113.xlsx';
if (!file_exists($xlsxFile)) {
    die("[FAIL] entries_1_to_113.xlsx not found at $xlsxFile\n");
}

$zip = new ZipArchive();
if ($zip->open($xlsxFile) !== true) {
    die("[FAIL] Could not open $xlsxFile\n");
}

// Read shared strings
$sharedStrings = [];
$ssXml = $zip->getFromName('xl/sharedStrings.xml');
if ($ssXml) {
    $xml = simplexml_load_string($ssXml);
    foreach ($xml->si as $si) {
        $text = '';
        if (isset($si->t)) {
            $text = (string)$si->t;
        } else {
            foreach ($si->r as $r) {
                $text .= (string)$r->t;
            }
        }
        $sharedStrings[] = $text;
    }
}

// Read sheet1.xml
$sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();

if (!$sheetXml) {
    die("[FAIL] xl/worksheets/sheet1.xml not found in XLSX\n");
}

$sheet = simplexml_load_string($sheetXml);
$rows = [];
foreach ($sheet->sheetData->row as $row) {
    $cells = [];
    foreach ($row->c as $c) {
        $attr = $c->attributes();
        $ref = (string)$attr['r'];
        $col = preg_replace('/[0-9]/', '', $ref);
        $type = isset($attr['t']) ? (string)$attr['t'] : '';
        $val = isset($c->v) ? (string)$c->v : '';

        if ($type === 's' && is_numeric($val) && isset($sharedStrings[(int)$val])) {
            $val = $sharedStrings[(int)$val];
        }
        $cells[$col] = $val;
    }
    $rows[] = $cells;
}

echo "[OK] Read " . count($rows) . " total rows from XLSX (including header).\n";

// Prepare insert statement
$insertStmt = $pdo->prepare("
    INSERT INTO `students` (`student_code`, `name`, `mobile`, `joining_date`, `mess_status`, `hostel_status`, `room_no`, `status`)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE')
");

$importedCount = 0;
$hostelCount = 0;

for ($i = 1; $i < count($rows); $i++) {
    $r = $rows[$i];
    $rawNo = isset($r['A']) ? trim($r['A']) : '';
    $rawName = isset($r['B']) ? trim($r['B']) : '';
    $rawDate = isset($r['C']) ? trim($r['C']) : '';

    if ($rawName === '') continue;

    $idx = $importedCount + 1;
    $studentCode = sprintf('STU-%05d', $idx);

    // Detect Hostel tag
    $isHostel = false;
    if (preg_match('/\(HSTL\)/i', $rawName) || preg_match('/\(hostel\)/i', $rawName)) {
        $isHostel = true;
    }

    $cleanName = trim(preg_replace('/\s*\((?:HSTL|hostel)\)/i', '', $rawName));

    // Date parsing: expecting DD-MM-YY e.g. 02-08-26 or 05-08-26
    $parsedDate = '2026-08-01'; // fallback
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/', $rawDate, $m)) {
        $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $year = $m[3];
        if (strlen($year) == 2) {
            $year = '20' . $year;
        }
        $parsedDate = "$year-$month-$day";
    }

    $messStatus = 'ACTIVE';
    $hostelStatus = $isHostel ? 'ACTIVE' : 'NOT_APPLICABLE';
    $roomNo = null;

    if ($isHostel) {
        $hostelCount++;
        $roomNo = 'H-' . (100 + $hostelCount);
    }

    $mobile = null; // As requested, phone numbers will be added later or upon student registration

    $insertStmt->execute([
        $studentCode,
        $cleanName,
        $mobile,
        $parsedDate,
        $messStatus,
        $hostelStatus,
        $roomNo
    ]);

    $importedCount++;
}

// Log initial import in audit log
$auditStmt = $pdo->prepare("INSERT INTO `audit_logs` (`admin_id`, `action`, `target_type`, `target_id`, `description`) VALUES (?, 'INITIAL_SEED', 'SYSTEM', 0, ?)");
$auditStmt->execute([$adminUserId, "Imported $importedCount initial student records from entries_1_to_113.xlsx ($hostelCount hostel students)"]);

echo "==============================================\n";
echo "[SUCCESS] Database setup & seeding complete!\n";
echo "- Total students imported: $importedCount\n";
echo "- Hostel students: $hostelCount\n";
echo "- Mess-only students: " . ($importedCount - $hostelCount) . "\n";
echo "- Default Admin credentials: 9999999999 / admin123\n";
echo "==============================================\n";
