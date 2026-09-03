const express = require('express');
const router = express.Router();
const db = require('../config/db');
const { requireAdmin, setFlash } = require('../middleware/auth');
const {
    formatYmd,
    formatDisplayDate,
    calculateCycleDate,
    getStudentCurrentCycle,
    generateStudentCode,
    logAudit
} = require('../utils/cycleEngine');

router.use(requireAdmin);

// -------------------------------------------------------------
// 1. Dashboard: Fee Cycles & Due Date Management Hub
// -------------------------------------------------------------
router.get(['/', '/dashboard'], async (req, res) => {
    try {
        const today = formatYmd(new Date());
        res.render('admin/dashboard', {
            pageTitle: 'Fee Cycles & Due Date Management',
            todayFormatted: formatDisplayDate(today)
        });
    } catch (err) {
        console.error('Dashboard error:', err);
        res.status(500).send('Server error loading dashboard.');
    }
});

// -------------------------------------------------------------
// 2. Student Directory
// -------------------------------------------------------------
router.get('/students', async (req, res) => {
    try {
        const search = (req.query.q || '').trim();
        const messFilter = (req.query.mess || '').trim();
        const hostelFilter = (req.query.hostel || '').trim();
        const statusFilter = (req.query.status || '').trim();
        const fromDate = (req.query.from_date || '').trim();
        const toDate = (req.query.to_date || '').trim();
        const sort = (req.query.sort || 'date_asc').trim();

        let sql = `
            SELECT s.*, u.id as user_id 
            FROM students s 
            LEFT JOIN users u ON u.student_id = s.id 
            WHERE 1=1
        `;
        const params = [];

        if (search) {
            sql += " AND (s.name LIKE ? OR s.mobile LIKE ? OR s.student_code LIKE ?)";
            const term = `%${search}%`;
            params.push(term, term, term);
        }

        if (messFilter) {
            sql += " AND s.mess_status = ?";
            params.push(messFilter);
        }

        if (hostelFilter) {
            sql += " AND s.hostel_status = ?";
            params.push(hostelFilter);
        }

        if (statusFilter) {
            sql += " AND s.status = ?";
            params.push(statusFilter);
        }

        if (fromDate) {
            sql += " AND s.joining_date >= ?";
            params.push(fromDate);
        }

        if (toDate) {
            sql += " AND s.joining_date <= ?";
            params.push(toDate);
        }

        if (sort === 'date_desc') {
            sql += " ORDER BY s.joining_date DESC, s.id DESC";
        } else if (sort === 'name_asc') {
            sql += " ORDER BY s.name ASC";
        } else if (sort === 'name_desc') {
            sql += " ORDER BY s.name DESC";
        } else {
            sql += " ORDER BY s.joining_date ASC, s.id ASC";
        }

        const [students] = await db.query(sql, params);

        const studentsWithCycles = [];
        const today = formatYmd(new Date());

        for (const st of students) {
            const cycle = await getStudentCurrentCycle(db, st.id, today);
            studentsWithCycles.push({
                ...st,
                joining_date_formatted: formatDisplayDate(st.joining_date),
                cycle: cycle
            });
        }

        res.render('admin/students', {
            pageTitle: 'Student Directory',
            students: studentsWithCycles,
            filters: { search, messFilter, hostelFilter, statusFilter, fromDate, toDate, sort }
        });
    } catch (err) {
        console.error('Student Directory error:', err);
        res.status(500).send('Server error loading student list.');
    }
});

// -------------------------------------------------------------
// 3. Add Student
// -------------------------------------------------------------
router.get('/student-add', (req, res) => {
    res.render('admin/student-add', {
        pageTitle: 'Add New Student',
        errors: []
    });
});

router.post('/student-add', async (req, res) => {
    const adminId = req.session.userId;
    const name = (req.body.name || '').trim();
    const mobile = (req.body.mobile || '').trim();
    const cleanMobile = mobile.replace(/[^0-9]/g, '');
    const joiningDate = (req.body.joining_date || '').trim();
    const messStatus = req.body.mess_status || 'ACTIVE';
    const hostelStatus = req.body.hostel_status || 'NOT_APPLICABLE';
    let roomNo = (req.body.room_no || '').trim();
    const status = req.body.status || 'ACTIVE';

    const errors = [];
    if (!name || name.length < 2) errors.push('Full name is required.');
    if (cleanMobile && cleanMobile.length !== 10) errors.push('Mobile number must be exactly 10 digits if provided.');
    if (!joiningDate) errors.push('Joining date is required.');

    if (hostelStatus !== 'ACTIVE') roomNo = null;

    if (errors.length > 0) {
        return res.render('admin/student-add', {
            pageTitle: 'Add New Student',
            errors,
            values: req.body
        });
    }

    try {
        const studentCode = await generateStudentCode(db);
        const [result] = await db.query(`
            INSERT INTO students (student_code, name, mobile, joining_date, mess_status, hostel_status, room_no, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        `, [studentCode, name, cleanMobile || null, joiningDate, messStatus, hostelStatus, roomNo || null, status]);

        const newId = result.insertId;

        await logAudit(db, adminId, 'MANUAL_ADD_STUDENT', 'STUDENT', newId, `Added student ${name} (${studentCode})`);
        setFlash(req, 'success', `Student ${name} (${studentCode}) added successfully!`);
        return res.redirect(`/admin/student-view/${newId}`);
    } catch (err) {
        console.error('Add Student error:', err);
        errors.push(`Database error: ${err.message}`);
        return res.render('admin/student-add', {
            pageTitle: 'Add New Student',
            errors,
            values: req.body
        });
    }
});

// -------------------------------------------------------------
// 4. Edit Student & Permanent Dropout Deletion
// -------------------------------------------------------------
router.get('/student-edit/:id', async (req, res) => {
    const id = parseInt(req.params.id, 10);
    try {
        const [rows] = await db.query("SELECT * FROM students WHERE id = ? LIMIT 1", [id]);
        if (!rows || rows.length === 0) {
            setFlash(req, 'danger', 'Student not found.');
            return res.redirect('/admin/students');
        }
        const student = rows[0];
        student.joining_date_ymd = formatYmd(student.joining_date);

        res.render('admin/student-edit', {
            pageTitle: `Edit: ${student.name}`,
            student,
            errors: []
        });
    } catch (err) {
        console.error('Edit student get error:', err);
        res.status(500).send('Server error.');
    }
});

router.post('/student-edit/:id', async (req, res) => {
    const adminId = req.session.userId;
    const id = parseInt(req.params.id, 10);
    const action = req.body.action || 'update_particulars';

    // Handle Permanent Deletion / Dropout
    if (action === 'delete_student' || action === 'delete_student_dropout') {
        const delId = parseInt(req.body.student_id || id, 10);
        const dropoutReason = (req.body.dropout_reason || 'Student dropped out / withdrew enrollment').trim();

        const conn = await db.getConnection();
        try {
            await conn.beginTransaction();

            const [chkRows] = await conn.query("SELECT s.*, u.id as user_id FROM students s LEFT JOIN users u ON u.student_id = s.id WHERE s.id = ? LIMIT 1", [delId]);
            if (!chkRows || chkRows.length === 0) {
                await conn.rollback();
                conn.release();
                setFlash(req, 'danger', 'Student record not found.');
                return res.redirect('/admin/students');
            }
            const stToDel = chkRows[0];

            await conn.query("DELETE FROM student_payment_allocations WHERE student_id = ?", [delId]);
            await conn.query("DELETE FROM name_change_requests WHERE student_id = ?", [delId]);
            if (stToDel.user_id) {
                await conn.query("DELETE FROM notifications WHERE user_id = ?", [stToDel.user_id]);
                await conn.query("DELETE FROM users WHERE id = ?", [stToDel.user_id]);
            }
            await conn.query("DELETE FROM students WHERE id = ?", [delId]);

            await logAudit(conn, adminId, 'DELETE_STUDENT', 'STUDENT', delId,
                `Admin permanently removed student ${stToDel.student_code} (${stToDel.name}). Reason: ${dropoutReason}`);

            await conn.commit();
            conn.release();

            setFlash(req, 'success', `Student ${stToDel.student_code} (${stToDel.name}) has been permanently deleted from the database.`);
            return res.redirect('/admin/students');
        } catch (err) {
            await conn.rollback();
            conn.release();
            console.error('Delete student error:', err);
            setFlash(req, 'danger', `Error deleting student: ${err.message}`);
            return res.redirect(`/admin/student-edit/${id}`);
        }
    }

    // Handle Particulars Update
    const name = (req.body.name || '').trim();
    const mobile = (req.body.mobile || '').trim();
    const cleanMobile = mobile.replace(/[^0-9]/g, '');
    const joiningDate = (req.body.joining_date || '').trim();
    const messStatus = req.body.mess_status || 'ACTIVE';
    const hostelStatus = req.body.hostel_status || 'NOT_APPLICABLE';
    let roomNo = (req.body.room_no || '').trim();
    const monthlyFee = req.body.monthly_fee ? parseFloat(req.body.monthly_fee) : null;
    const status = req.body.status || 'ACTIVE';

    const errors = [];
    if (!name || name.length < 2) errors.push('Full name is required.');
    if (cleanMobile && cleanMobile.length !== 10) errors.push('Mobile number must be exactly 10 digits if provided.');
    if (!joiningDate) errors.push('Joining date is required.');
    if (hostelStatus !== 'ACTIVE') roomNo = null;

    if (errors.length > 0) {
        const [rows] = await db.query("SELECT * FROM students WHERE id = ? LIMIT 1", [id]);
        return res.render('admin/student-edit', {
            pageTitle: `Edit: ${name}`,
            student: { ...rows[0], joining_date_ymd: joiningDate, name, mobile, room_no: roomNo, monthly_fee: monthlyFee },
            errors
        });
    }

    try {
        await db.query(`
            UPDATE students 
            SET name = ?, mobile = ?, joining_date = ?, mess_status = ?, hostel_status = ?, room_no = ?, monthly_fee = ?, status = ?
            WHERE id = ?
        `, [name, cleanMobile || null, joiningDate, messStatus, hostelStatus, roomNo || null, isNaN(monthlyFee) ? null : monthlyFee, status, id]);

        await logAudit(db, adminId, 'UPDATE_STUDENT_DETAILS', 'STUDENT', id, `Updated student details for ${name} (ID: ${id})`);
        setFlash(req, 'success', `Student ${name} updated successfully.`);
        return res.redirect(`/admin/student-view/${id}`);
    } catch (err) {
        console.error('Update student error:', err);
        errors.push(`Database error: ${err.message}`);
        const [rows] = await db.query("SELECT * FROM students WHERE id = ? LIMIT 1", [id]);
        return res.render('admin/student-edit', {
            pageTitle: `Edit: ${name}`,
            student: rows[0],
            errors
        });
    }
});

// -------------------------------------------------------------
// 5. Student View Profile & Payment Ledger
// -------------------------------------------------------------
router.get('/student-view/:id', async (req, res) => {
    const id = parseInt(req.params.id, 10);
    try {
        const [rows] = await db.query("SELECT * FROM students WHERE id = ? LIMIT 1", [id]);
        if (!rows || rows.length === 0) {
            setFlash(req, 'danger', 'Student not found.');
            return res.redirect('/admin/students');
        }
        const student = rows[0];
        const today = formatYmd(new Date());
        const cycle = await getStudentCurrentCycle(db, id, today);

        const [allocations] = await db.query(`
            SELECT a.*, t.payment_method, t.utr_number, t.transaction_code, t.verified_at, t.created_at as txn_date
            FROM student_payment_allocations a
            LEFT JOIN payment_transactions t ON a.payment_transaction_id = t.id
            WHERE a.student_id = ?
            ORDER BY a.cycle_number DESC, a.id DESC
        `, [id]);

        const formattedAllocations = allocations.map(a => ({
            ...a,
            cycle_start_formatted: formatDisplayDate(a.cycle_start_date),
            cycle_end_formatted: formatDisplayDate(a.cycle_end_date),
            due_date_formatted: formatDisplayDate(a.due_date),
            paid_date_formatted: a.verified_at ? formatDisplayDate(a.verified_at) : (a.txn_date ? formatDisplayDate(a.txn_date) : '—'),
            amount_formatted: `₹${parseFloat(a.allocated_amount || a.amount_due).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`
        }));

        res.render('admin/student-view', {
            pageTitle: `${student.name} (${student.student_code})`,
            student: {
                ...student,
                joining_date_formatted: formatDisplayDate(student.joining_date)
            },
            cycle,
            allocations: formattedAllocations
        });
    } catch (err) {
        console.error('Student view error:', err);
        res.status(500).send('Server error.');
    }
});

// -------------------------------------------------------------
// 6. CSV Export
// -------------------------------------------------------------
router.get('/export', async (req, res) => {
    try {
        const filename = `students_export_${new Date().toISOString().replace(/[-:T.]/g, '').slice(0, 14)}.csv`;
        res.setHeader('Content-Type', 'text/csv; charset=UTF-8');
        res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);

        // Excel UTF-8 BOM
        res.write('\uFEFF');

        res.write(['Student Code', 'Student Name', 'Mobile Number', 'Joining Date', 'Mess Status', 'Hostel Status', 'Room Number', 'Enrolment Status', 'Record Created'].join(',') + '\r\n');

        const [students] = await db.query("SELECT * FROM students ORDER BY name ASC");
        for (const s of students) {
            const row = [
                `"${s.student_code}"`,
                `"${(s.name || '').replace(/"/g, '""')}"`,
                `"${s.mobile || ''}"`,
                `"${formatDisplayDate(s.joining_date)}"`,
                `"${s.mess_status}"`,
                `"${s.hostel_status}"`,
                `"${s.room_no || ''}"`,
                `"${s.status}"`,
                `"${formatDisplayDate(s.created_at)}"`
            ];
            res.write(row.join(',') + '\r\n');
        }
        res.end();
    } catch (err) {
        console.error('Export error:', err);
        res.status(500).send('Export failed.');
    }
});

module.exports = router;
