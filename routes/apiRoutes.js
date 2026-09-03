const express = require('express');
const router = express.Router();
const db = require('../config/db');
const {
    formatYmd,
    formatDisplayDate,
    calculateCycleDate,
    getStudentCurrentCycle,
    logAudit
} = require('../utils/cycleEngine');

// Middleware to check admin role for APIs
function checkAdminApi(req, res, next) {
    if (req.session && req.session.userId && req.session.role === 'admin') {
        return next();
    }
    return res.status(401).json({ success: false, message: 'Unauthorized access.' });
}

router.use(checkAdminApi);

// -------------------------------------------------------------
// 1. Dynamic JSON Filter Endpoint
// -------------------------------------------------------------
router.get('/admin-unpaid-filter', async (req, res) => {
    try {
        const statusFilter = (req.query.status || 'ALL').trim();
        const serviceFilter = (req.query.service || 'ALL').trim();
        const fromDueDate = (req.query.from_due_date || '').trim();
        const toDueDate = (req.query.to_due_date || '').trim();
        const search = (req.query.q || '').trim();

        let sql = `
            SELECT s.*, u.id as user_id, u.mobile as user_mobile 
            FROM students s 
            LEFT JOIN users u ON u.student_id = s.id 
            WHERE s.status = 'ACTIVE'
        `;
        const params = [];

        if (serviceFilter === 'MESS') {
            sql += " AND s.mess_status = 'ACTIVE' AND (s.hostel_status IS NULL OR s.hostel_status != 'ACTIVE')";
        } else if (serviceFilter === 'HOSTEL') {
            sql += " AND s.hostel_status = 'ACTIVE' AND (s.mess_status IS NULL OR s.mess_status != 'ACTIVE')";
        } else if (serviceFilter === 'BOTH') {
            sql += " AND s.mess_status = 'ACTIVE' AND s.hostel_status = 'ACTIVE'";
        }

        if (search) {
            sql += " AND (s.name LIKE ? OR s.student_code LIKE ? OR s.mobile LIKE ?)";
            const term = `%${search}%`;
            params.push(term, term, term);
        }

        const [students] = await db.query(sql, params);

        // Total Collections
        const [sumRows] = await db.query("SELECT COALESCE(SUM(allocated_amount), 0) as total FROM student_payment_allocations WHERE status = 'COMPLETED'");
        const totalCollected = parseFloat(sumRows[0].total) || 0;

        let dueTodayCount = 0;
        let overdueCount = 0;
        let due7DaysCount = 0;
        let due30DaysCount = 0;
        let pendingCount = 0;
        let completedCount = 0;

        const filteredList = [];
        const today = formatYmd(new Date());

        for (const st of students) {
            const cycle = await getStudentCurrentCycle(db, st.id, today);
            if (!cycle) continue;

            const stStatus = cycle.status;
            const daysDiff = cycle.days_diff;

            // Update metrics
            if (stStatus === 'PENDING_VERIFICATION') pendingCount++;
            else if (stStatus === 'DUE') dueTodayCount++;
            else if (stStatus === 'OVERDUE') overdueCount++;
            else if (stStatus === 'UPCOMING') {
                if (daysDiff <= 7) due7DaysCount++;
                if (daysDiff <= 30) due30DaysCount++;
            }

            // Check completed cycles
            const [completedAllocations] = await db.query(`
                SELECT a.*, t.payment_method, t.utr_number, t.transaction_code, t.verified_at, t.created_at as txn_date
                FROM student_payment_allocations a
                JOIN payment_transactions t ON a.payment_transaction_id = t.id
                WHERE a.student_id = ? AND a.status = 'COMPLETED'
                ORDER BY a.cycle_number DESC, a.id DESC
            `, [st.id]);

            if (completedAllocations.length > 0) {
                completedCount += completedAllocations.length;
            }

            // Completed Filter Mode
            if (statusFilter === 'COMPLETED') {
                if (completedAllocations.length === 0) continue;

                for (const ca of completedAllocations) {
                    const cycleLabel = `${formatDisplayDate(ca.cycle_start_date)} → ${formatDisplayDate(ca.cycle_end_date)}`;
                    const paidDate = ca.verified_at ? formatYmd(ca.verified_at) : formatYmd(ca.txn_date);
                    const paidDateFormatted = formatDisplayDate(paidDate);
                    const dueYmd = formatYmd(ca.due_date);

                    if (fromDueDate && dueYmd < fromDueDate) continue;
                    if (toDueDate && dueYmd > toDueDate) continue;

                    filteredList.push({
                        id: st.id,
                        student_code: st.student_code,
                        name: st.name,
                        mobile: st.mobile || '—',
                        raw_joining_date: formatYmd(st.joining_date),
                        joining_date: formatDisplayDate(st.joining_date),
                        cycle_number: parseInt(ca.cycle_number, 10),
                        cycle_label: cycleLabel,
                        due_date: dueYmd,
                        due_date_formatted: formatDisplayDate(dueYmd),
                        service_label: cycle.service_label,
                        room_no: st.room_no || '—',
                        applicable_fee: parseFloat(ca.allocated_amount),
                        applicable_fee_formatted: `₹${parseFloat(ca.allocated_amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`,
                        status: 'COMPLETED',
                        status_label: `Paid on ${paidDateFormatted}`,
                        days_diff: 0,
                        days_text: `Paid on ${paidDateFormatted} (${ca.payment_method})`,
                        pending_allocation: null,
                        paid_date: paidDateFormatted,
                        transaction_code: ca.transaction_code
                    });
                }
                continue;
            }

            // Apply Status Criteria for Active Cycles
            if (statusFilter === 'DUE_TODAY' && stStatus !== 'DUE') continue;
            if (statusFilter === 'OVERDUE' && stStatus !== 'OVERDUE') continue;
            if (statusFilter === 'DUE_7_DAYS' && !(daysDiff >= 0 && daysDiff <= 7 && stStatus !== 'PENDING_VERIFICATION')) continue;
            if (statusFilter === 'DUE_30_DAYS' && !(daysDiff >= 0 && daysDiff <= 30 && stStatus !== 'PENDING_VERIFICATION')) continue;
            if (statusFilter === 'PENDING' && stStatus !== 'PENDING_VERIFICATION') continue;

            const dueYmd = formatYmd(cycle.due_date);
            if (fromDueDate && dueYmd < fromDueDate) continue;
            if (toDueDate && dueYmd > toDueDate) continue;

            let lastPaidInfo = null;
            if (completedAllocations.length > 0) {
                const latest = completedAllocations[0];
                const pDate = latest.verified_at ? formatYmd(latest.verified_at) : formatYmd(latest.txn_date);
                lastPaidInfo = `Cycle ${latest.cycle_number} Paid (₹${parseFloat(latest.allocated_amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })} on ${formatDisplayDate(pDate)})`;
            }

            filteredList.push({
                id: cycle.student_id,
                student_code: cycle.student_code,
                name: cycle.student_name,
                mobile: st.mobile || '—',
                raw_joining_date: formatYmd(st.joining_date),
                joining_date: cycle.joining_date_formatted,
                cycle_number: cycle.cycle_number,
                cycle_label: cycle.cycle_label,
                due_date: cycle.due_date,
                due_date_formatted: cycle.due_date_formatted,
                service_label: cycle.service_label,
                room_no: st.room_no || '—',
                applicable_fee: cycle.amount_due,
                applicable_fee_formatted: cycle.amount_due_formatted,
                status: cycle.status,
                status_label: cycle.status_label,
                days_diff: cycle.days_diff,
                days_text: cycle.days_text,
                pending_allocation: cycle.pending_allocation,
                last_paid_info: lastPaidInfo
            });
        }

        // Sort chronologically ascending by joining date, then student ID
        filteredList.sort((a, b) => {
            const cmp = (a.raw_joining_date || '').localeCompare(b.raw_joining_date || '');
            if (cmp !== 0) return cmp;
            return a.id - b.id;
        });

        res.json({
            success: true,
            today: formatDisplayDate(today),
            summary: {
                total_active_students: students.length,
                due_today_count: dueTodayCount,
                overdue_count: overdueCount,
                due_7_days_count: due7DaysCount,
                due_30_days_count: due30DaysCount,
                pending_count: pendingCount,
                completed_count: completedCount,
                amount_collected: totalCollected,
                amount_collected_formatted: `₹${totalCollected.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`
            },
            total_matching: filteredList.length,
            students: filteredList
        });
    } catch (err) {
        console.error('API Filter Error:', err);
        res.status(500).json({ success: false, message: 'Server error processing filter query.' });
    }
});

// -------------------------------------------------------------
// 2. Direct Single & Bulk Payment Processing
// -------------------------------------------------------------
router.post('/admin-payments', async (req, res) => {
    const adminId = req.session.userId;
    const action = req.body.action || '';

    if (action === 'direct_student_payment') {
        const studentId = parseInt(req.body.student_id, 10);
        const method = req.body.payment_method || 'CASH';
        const amount = parseFloat(req.body.amount);
        const notes = (req.body.notes || '').trim();
        const paidDate = req.body.paid_date ? formatYmd(req.body.paid_date) : formatYmd(new Date());

        if (!studentId || isNaN(amount) || amount <= 0) {
            return res.json({ success: false, message: 'Invalid student ID or payment amount.' });
        }

        const conn = await db.getConnection();
        try {
            await conn.beginTransaction();

            const cycle = await getStudentCurrentCycle(conn, studentId, paidDate);
            if (!cycle) {
                await conn.rollback();
                conn.release();
                return res.json({ success: false, message: 'Student record not found.' });
            }

            // Sync student's recurring monthly fee
            await conn.query("UPDATE students SET monthly_fee = ? WHERE id = ?", [amount, studentId]);

            const cycleSummary = `Direct ${method} Payment - Cycle ${cycle.cycle_number} (${cycle.cycle_label})`;
            const txnCode = 'TXN-' + Math.floor(Date.now() / 1000) + '-' + Math.floor(1000 + Math.random() * 9000);

            // Insert transaction
            const utrNumber = 'UTR-' + txnCode;
            const [txnRes] = await conn.query(`
                INSERT INTO payment_transactions 
                (transaction_code, payer_user_id, utr_number, billing_period, total_amount, payment_method, cycle_summary, status, verified_by, verified_at, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'COMPLETED', ?, NOW(), ?, NOW(), NOW())
            `, [txnCode, adminId, utrNumber, cycle.cycle_label, amount, method, cycleSummary, adminId, notes ? `${notes} [Paid on ${formatDisplayDate(paidDate)}]` : `Paid on ${formatDisplayDate(paidDate)}`]);

            const txnId = txnRes.insertId;

            // Insert Allocation
            await conn.query(`
                INSERT INTO student_payment_allocations
                (payment_transaction_id, student_id, cycle_number, cycle_start_date, cycle_end_date, due_date, amount_due, allocated_amount, service_covered, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', NOW(), NOW())
            `, [txnId, studentId, cycle.cycle_number, cycle.cycle_start_date, cycle.cycle_end_date, cycle.due_date, amount, amount, cycle.service_label]);

            await logAudit(conn, adminId, 'DIRECT_PAYMENT_RECORDED', 'STUDENT', studentId,
                `Admin directly recorded ${method} payment of ₹${amount.toFixed(2)} for ${cycle.student_name} (${cycle.student_code}) for Cycle ${cycle.cycle_number} on ${formatDisplayDate(paidDate)}`);

            await conn.commit();
            conn.release();

            return res.json({
                success: true,
                message: `Payment of ₹${amount.toLocaleString('en-IN', { minimumFractionDigits: 2 })} for Cycle ${cycle.cycle_number} recorded successfully on ${formatDisplayDate(paidDate)} and marked COMPLETED.`
            });
        } catch (err) {
            await conn.rollback();
            conn.release();
            console.error('Direct Payment Error:', err);
            return res.status(500).json({ success: false, message: 'Database error recording payment.' });
        }
    }

    if (action === 'bulk_direct_payment') {
        let studentIds = [];
        try {
            studentIds = typeof req.body.student_ids === 'string' ? JSON.parse(req.body.student_ids) : req.body.student_ids;
        } catch (e) {
            studentIds = [];
        }

        if (!Array.isArray(studentIds) || studentIds.length === 0) {
            return res.json({ success: false, message: 'Please select at least one student.' });
        }

        const method = req.body.payment_method || 'CASH';
        const notes = (req.body.notes || '').trim();
        const paidDate = req.body.paid_date ? formatYmd(req.body.paid_date) : formatYmd(new Date());

        const conn = await db.getConnection();
        try {
            await conn.beginTransaction();

            let processedCount = 0;
            let totalProcessedAmount = 0;

            for (const sId of studentIds) {
                const studentId = parseInt(sId, 10);
                if (!studentId) continue;

                const cycle = await getStudentCurrentCycle(conn, studentId, paidDate);
                if (!cycle) continue;

                const amount = cycle.amount_due;
                const cycleSummary = `Bulk ${method} Payment - Cycle ${cycle.cycle_number} (${cycle.cycle_label})`;
                const txnCode = 'TXN-' + Math.floor(Date.now() / 1000) + '-' + Math.floor(1000 + Math.random() * 9000);
                const utrNumber = 'UTR-' + txnCode;

                const [txnRes] = await conn.query(`
                    INSERT INTO payment_transactions 
                    (transaction_code, payer_user_id, utr_number, billing_period, total_amount, payment_method, cycle_summary, status, verified_by, verified_at, notes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'COMPLETED', ?, NOW(), ?, NOW(), NOW())
                `, [txnCode, adminId, utrNumber, cycle.cycle_label, amount, method, cycleSummary, adminId, notes ? `${notes} [Batch Paid on ${formatDisplayDate(paidDate)}]` : `Batch Paid on ${formatDisplayDate(paidDate)}`]);

                const txnId = txnRes.insertId;

                await conn.query(`
                    INSERT INTO student_payment_allocations
                    (payment_transaction_id, student_id, cycle_number, cycle_start_date, cycle_end_date, due_date, amount_due, allocated_amount, service_covered, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', NOW(), NOW())
                `, [txnId, studentId, cycle.cycle_number, cycle.cycle_start_date, cycle.cycle_end_date, cycle.due_date, amount, amount, cycle.service_label]);

                processedCount++;
                totalProcessedAmount += amount;
            }

            await logAudit(conn, adminId, 'BULK_PAYMENT_RECORDED', 'STUDENT', 0,
                `Admin recorded bulk ${method} payments for ${processedCount} students totaling ₹${totalProcessedAmount.toFixed(2)} on ${formatDisplayDate(paidDate)}`);

            await conn.commit();
            conn.release();

            return res.json({
                success: true,
                message: `Successfully marked ${processedCount} student(s) as PAID on ${formatDisplayDate(paidDate)} (Total: ₹${totalProcessedAmount.toLocaleString('en-IN', { minimumFractionDigits: 2 })}).`,
                processed_count: processedCount,
                total_amount: totalProcessedAmount
            });
        } catch (err) {
            await conn.rollback();
            conn.release();
            console.error('Bulk Payment Error:', err);
            return res.status(500).json({ success: false, message: 'Database error processing bulk payments.' });
        }
    }

    return res.json({ success: false, message: 'Unknown action specified.' });
});

module.exports = router;
