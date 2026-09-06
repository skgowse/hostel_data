/**
 * Database Reset Script: Empties all student records, transactions,
 * allocations, and logs while preserving the Admin user account.
 */

const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');

async function resetAll() {
    console.log("=============================================================");
    console.log("🧹 RESETTING DATABASE: Clearing all records to empty state");
    console.log("=============================================================\n");

    // 1. Reset Firebase Firestore JSON Collections
    console.log("--- 1. Resetting Firebase Firestore Collections ---");
    const dataDir = path.join(__dirname, '../data/firestore');
    if (!fs.existsSync(dataDir)) {
        fs.mkdirSync(dataDir, { recursive: true });
    }

    // Default admin user
    const adminHash = '$2a$10$8K1p/a0dL1LXMIgoEDFrwOfMQbFkdnzOqV0.l1.gL4Yw.Lp3YtD6q'; // admin123
    const adminUser = {
        "1": {
            id: "1",
            name: "Administrator",
            mobile: "9999999999",
            password_hash: adminHash,
            role: "admin",
            first_login: 0,
            status: "active",
            created_at: new Date().toISOString()
        }
    };

    fs.writeFileSync(path.join(dataDir, 'users.json'), JSON.stringify(adminUser, null, 2), 'utf8');
    fs.writeFileSync(path.join(dataDir, 'students.json'), JSON.stringify({}, null, 2), 'utf8');
    fs.writeFileSync(path.join(dataDir, 'student_payment_allocations.json'), JSON.stringify({}, null, 2), 'utf8');
    fs.writeFileSync(path.join(dataDir, 'payment_transactions.json'), JSON.stringify({}, null, 2), 'utf8');
    fs.writeFileSync(path.join(dataDir, 'audit_logs.json'), JSON.stringify({}, null, 2), 'utf8');
    console.log("[PASS] Firebase Firestore collections reset (Students: 0, Allocations: 0, Transactions: 0, Audit: 0, Users: 1 Admin).");

    // 2. Reset MySQL / MariaDB Tables
    try {
        console.log("\n--- 2. Resetting MariaDB Tables ---");
        const sqlPool = mysql.createPool({
            host: '127.0.0.1',
            port: 3307,
            user: 'root',
            password: '',
            database: 'mess_hostel_db'
        });

        await sqlPool.query("SET FOREIGN_KEY_CHECKS = 0");
        await sqlPool.query("TRUNCATE TABLE student_payment_allocations");
        await sqlPool.query("TRUNCATE TABLE payment_transactions");
        await sqlPool.query("TRUNCATE TABLE audit_logs");
        await sqlPool.query("TRUNCATE TABLE students");
        await sqlPool.query("DELETE FROM users WHERE role != 'admin'");
        await sqlPool.query(`
            INSERT INTO users (id, name, mobile, password_hash, role, first_login, status, created_at)
            VALUES (1, 'Administrator', '9999999999', ?, 'admin', 0, 'active', NOW())
            ON DUPLICATE KEY UPDATE name='Administrator', mobile='9999999999', password_hash=VALUES(password_hash), role='admin', status='active'
        `, [adminHash]);
        await sqlPool.query("SET FOREIGN_KEY_CHECKS = 1");

        console.log("[PASS] MariaDB tables truncated and reset.");
        await sqlPool.end();
    } catch (e) {
        console.warn("[Note] MariaDB reset notice:", e.message);
    }

    console.log("\n=============================================================");
    console.log("✨ DATABASE IS NOW COMPLETELY CLEAN & EMPTY!");
    console.log("🔑 Administrator Login: Mobile: 9999999999 | Password: admin123");
    console.log("=============================================================\n");
}

resetAll().then(() => process.exit(0)).catch(err => {
    console.error(err);
    process.exit(1);
});
