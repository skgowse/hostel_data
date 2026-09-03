<?php
/**
 * Migration: Payment System & Fee Plans
 * Mess & Hostel Management System
 */

require_once __DIR__ . '/../config/database.php';

echo "--- Running Payment System Migration ---\n";

$pdo = get_db_connection();

try {
    // 1. Fee Plans Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `fee_plans` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `service_type` ENUM('MESS', 'HOSTEL', 'BOTH') NOT NULL UNIQUE,
            `monthly_amount` DECIMAL(10,2) NOT NULL,
            `description` VARCHAR(255) DEFAULT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "[OK] `fee_plans` table verified.\n";

    // Seed default fee plans
    $pdo->exec("
        INSERT INTO `fee_plans` (`service_type`, `monthly_amount`, `description`) 
        VALUES 
            ('MESS', 2500.00, 'Standard Monthly Dining Fee'),
            ('HOSTEL', 3000.00, 'Standard Monthly Residential Accommodation Fee'),
            ('BOTH', 5500.00, 'Combined Mess & Residential Hostel Fee')
        ON DUPLICATE KEY UPDATE `monthly_amount` = VALUES(`monthly_amount`);
    ");
    echo "[OK] Default fee plans seeded (MESS: ₹2,500, HOSTEL: ₹3,000, BOTH: ₹5,500).\n";

    // 2. Payment Transactions Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payment_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `transaction_code` VARCHAR(50) NOT NULL UNIQUE,
            `payer_user_id` INT NOT NULL,
            `utr_number` VARCHAR(100) NOT NULL UNIQUE,
            `billing_period` VARCHAR(30) NOT NULL,
            `total_amount` DECIMAL(10,2) NOT NULL,
            `payment_method` ENUM('UPI', 'NET_BANKING', 'DEBIT_CARD', 'CASH', 'OTHER') NOT NULL DEFAULT 'UPI',
            `screenshot_path` VARCHAR(255) DEFAULT NULL,
            `ocr_raw_text` TEXT DEFAULT NULL,
            `ocr_detected_utr` VARCHAR(100) DEFAULT NULL,
            `ocr_detected_amount` DECIMAL(10,2) DEFAULT NULL,
            `ocr_confidence` DECIMAL(5,2) DEFAULT NULL,
            `status` ENUM('PENDING', 'COMPLETED', 'REJECTED') NOT NULL DEFAULT 'PENDING',
            `verified_by` INT DEFAULT NULL,
            `verified_at` DATETIME DEFAULT NULL,
            `rejection_reason` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`payer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_txn_utr` (`utr_number`),
            INDEX `idx_txn_status` (`status`),
            INDEX `idx_txn_period` (`billing_period`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "[OK] `payment_transactions` table verified.\n";

    // 3. Student Payment Allocations Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_payment_allocations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `payment_transaction_id` INT NOT NULL,
            `student_id` INT NOT NULL,
            `billing_period` VARCHAR(30) NOT NULL,
            `allocated_amount` DECIMAL(10,2) NOT NULL,
            `service_covered` ENUM('MESS', 'HOSTEL', 'BOTH') NOT NULL,
            `status` ENUM('PENDING', 'COMPLETED', 'REJECTED') NOT NULL DEFAULT 'PENDING',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
            INDEX `idx_alloc_student_period` (`student_id`, `billing_period`),
            INDEX `idx_alloc_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "[OK] `student_payment_allocations` table verified.\n";

    echo "--- Payment Migration Completed Successfully ---\n";
} catch (Exception $e) {
    die("[FAIL] Migration error: " . $e->getMessage() . "\n");
}
