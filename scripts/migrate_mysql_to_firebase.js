/**
 * Migration Script: MariaDB / MySQL -> Firebase Cloud Firestore
 * Transfers all collections, 108 students, admin credentials, payment ledger, and audit logs.
 */

const mysql = require('mysql2/promise');
const { db } = require('../config/firebase');

async function migrate() {
    console.log("=============================================================");
    console.log("🚀 STARTING MIGRATION: MySQL / MariaDB -> Firebase Firestore");
    console.log("=============================================================\n");

    const sqlPool = mysql.createPool({
        host: '127.0.0.1',
        port: 3307,
        user: 'root',
        password: '',
        database: 'mess_hostel_db'
    });

    try {
        // 1. Migrate Users
        console.log("--- 1. Migrating Users ---");
        const [users] = await sqlPool.query("SELECT * FROM users");
        for (const u of users) {
            await db.collection('users').doc(String(u.id)).set({
                id: String(u.id),
                student_id: u.student_id ? String(u.student_id) : null,
                name: u.name,
                mobile: u.mobile,
                password_hash: u.password_hash,
                role: u.role,
                first_login: u.first_login,
                status: u.status,
                created_at: u.created_at ? new Date(u.created_at).toISOString() : new Date().toISOString()
            });
        }
        console.log(`[PASS] Migrated ${users.length} user records to Firestore 'users' collection.`);

        // 2. Migrate Students
        console.log("\n--- 2. Migrating Students ---");
        const [students] = await sqlPool.query("SELECT * FROM students ORDER BY id ASC");
        for (const s of students) {
            const joiningDate = s.joining_date ? (typeof s.joining_date === 'string' ? s.joining_date.split('T')[0] : s.joining_date.toISOString().split('T')[0]) : '2026-08-01';
            await db.collection('students').doc(String(s.id)).set({
                id: parseInt(s.id, 10),
                student_code: s.student_code,
                name: s.name,
                mobile: s.mobile,
                joining_date: joiningDate,
                mess_status: s.mess_status,
                hostel_status: s.hostel_status,
                room_no: s.room_no,
                monthly_fee: s.monthly_fee !== null ? parseFloat(s.monthly_fee) : null,
                status: s.status,
                created_at: s.created_at ? new Date(s.created_at).toISOString() : new Date().toISOString()
            });
        }
        console.log(`[PASS] Migrated ${students.length} student records to Firestore 'students' collection.`);

        // 3. Migrate Payment Transactions
        console.log("\n--- 3. Migrating Payment Transactions ---");
        const [txns] = await sqlPool.query("SELECT * FROM payment_transactions ORDER BY id ASC");
        for (const t of txns) {
            await db.collection('payment_transactions').doc(String(t.id)).set({
                id: String(t.id),
                transaction_code: t.transaction_code,
                payer_user_id: t.payer_user_id ? String(t.payer_user_id) : '1',
                utr_number: t.utr_number,
                billing_period: t.billing_period,
                total_amount: parseFloat(t.total_amount),
                payment_method: t.payment_method,
                cycle_summary: t.cycle_summary,
                status: t.status,
                verified_by: t.verified_by ? String(t.verified_by) : '1',
                verified_at: t.verified_at ? new Date(t.verified_at).toISOString() : null,
                notes: t.notes,
                created_at: t.created_at ? new Date(t.created_at).toISOString() : new Date().toISOString()
            });
        }
        console.log(`[PASS] Migrated ${txns.length} payment transaction records to Firestore 'payment_transactions' collection.`);

        // 4. Migrate Student Payment Allocations
        console.log("\n--- 4. Migrating Student Payment Allocations ---");
        const [allocs] = await sqlPool.query("SELECT * FROM student_payment_allocations ORDER BY id ASC");
        for (const a of allocs) {
            await db.collection('student_payment_allocations').doc(String(a.id)).set({
                id: String(a.id),
                payment_transaction_id: String(a.payment_transaction_id),
                student_id: parseInt(a.student_id, 10),
                cycle_number: parseInt(a.cycle_number, 10),
                cycle_start_date: a.cycle_start_date ? (typeof a.cycle_start_date === 'string' ? a.cycle_start_date.split('T')[0] : a.cycle_start_date.toISOString().split('T')[0]) : '',
                cycle_end_date: a.cycle_end_date ? (typeof a.cycle_end_date === 'string' ? a.cycle_end_date.split('T')[0] : a.cycle_end_date.toISOString().split('T')[0]) : '',
                due_date: a.due_date ? (typeof a.due_date === 'string' ? a.due_date.split('T')[0] : a.due_date.toISOString().split('T')[0]) : '',
                amount_due: parseFloat(a.amount_due),
                allocated_amount: parseFloat(a.allocated_amount),
                service_covered: a.service_covered,
                status: a.status,
                created_at: a.created_at ? new Date(a.created_at).toISOString() : new Date().toISOString()
            });
        }
        console.log(`[PASS] Migrated ${allocs.length} student allocation records to Firestore 'student_payment_allocations' collection.`);

        // 5. Migrate Audit Logs
        console.log("\n--- 5. Migrating Audit Logs ---");
        const [logs] = await sqlPool.query("SELECT * FROM audit_logs ORDER BY id ASC");
        for (const l of logs) {
            await db.collection('audit_logs').doc(String(l.id)).set({
                id: String(l.id),
                admin_id: l.admin_id ? String(l.admin_id) : '1',
                action: l.action,
                target_type: l.target_type,
                target_id: l.target_id ? String(l.target_id) : '0',
                description: l.description,
                created_at: l.created_at ? new Date(l.created_at).toISOString() : new Date().toISOString()
            });
        }
        console.log(`[PASS] Migrated ${logs.length} audit log records to Firestore 'audit_logs' collection.`);

        console.log("\n=============================================================");
        console.log("🎉 FIREBASE FIRESTORE DATA MIGRATION COMPLETED SUCCESSFULLY!");
        console.log("=============================================================\n");
        await sqlPool.end();
        process.exit(0);
    } catch (err) {
        console.error("Migration failed:", err);
        process.exit(1);
    }
}

migrate();
