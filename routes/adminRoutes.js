const express = require('express');
const router = express.Router();
const { db } = require('../config/firebase');
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
        const search = (req.query.q || '').trim().toLowerCase();
        const messFilter = (req.query.mess || '').trim();
        const hostelFilter = (req.query.hostel || '').trim();
        const statusFilter = (req.query.status || '').trim();
        const fromDate = (req.query.from_date || '').trim();
        const toDate = (req.query.to_date || '').trim();
        const sort = (req.query.sort || 'date_asc').trim();

        const snap = await db.collection('students').get();
        let students = [];
        snap.forEach(doc => students.push(doc.data()));

        if (search) {
            students = students.filter(s =>
                (s.name && s.name.toLowerCase().includes(search)) ||
                (s.student_code && s.student_code.toLowerCase().includes(search)) ||
                (s.mobile && s.mobile.includes(search))
            );
        }

        if (messFilter) {
            students = students.filter(s => s.mess_status === messFilter);
        }

        if (hostelFilter) {
            students = students.filter(s => s.hostel_status === hostelFilter);
        }

        if (statusFilter) {
            students = students.filter(s => s.status === statusFilter);
        }

        if (fromDate) {
            students = students.filter(s => formatYmd(s.joining_date) >= fromDate);
        }

        if (toDate) {
            students = students.filter(s => formatYmd(s.joining_date) <= toDate);
        }

        if (sort === 'date_desc') {
            students.sort((a, b) => (formatYmd(b.joining_date) || '').localeCompare(formatYmd(a.joining_date) || '') || (b.id - a.id));
        } else if (sort === 'name_asc') {
            students.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        } else if (sort === 'name_desc') {
            students.sort((a, b) => (b.name || '').localeCompare(a.name || ''));
        } else {
            students.sort((a, b) => (formatYmd(a.joining_date) || '').localeCompare(formatYmd(b.joining_date) || '') || (a.id - b.id));
        }

        const studentsWithCycles = [];
        const today = formatYmd(new Date());

        for (const st of students) {
            const cycle = await getStudentCurrentCycle(st.id, today);
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
        const nextInfo = await generateStudentCode();
        const studentCode = nextInfo.code;
        const newId = nextInfo.id;

        const newStudent = {
            id: newId,
            student_code: studentCode,
            name,
            mobile: cleanMobile || null,
            joining_date: joiningDate,
            mess_status: messStatus,
            hostel_status: hostelStatus,
            room_no: roomNo || null,
            monthly_fee: null,
            status,
            created_at: new Date().toISOString()
        };

        await db.collection('students').doc(String(newId)).set(newStudent);

        await logAudit(adminId, 'MANUAL_ADD_STUDENT', 'STUDENT', newId, `Added student ${name} (${studentCode}) in Firebase`);
        setFlash(req, 'success', `Student ${name} (${studentCode}) added successfully!`);
        return res.redirect(`/admin/student-view/${newId}`);
    } catch (err) {
        console.error('Add Student error:', err);
        errors.push(`Firebase error: ${err.message}`);
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
        const doc = await db.collection('students').doc(String(id)).get();
        if (!doc.exists) {
            setFlash(req, 'danger', 'Student not found.');
            return res.redirect('/admin/students');
        }
        const student = doc.data();
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

        try {
            const sDoc = await db.collection('students').doc(String(delId)).get();
            if (!sDoc.exists) {
                setFlash(req, 'danger', 'Student record not found.');
                return res.redirect('/admin/students');
            }
            const stToDel = sDoc.data();

            // Delete associated allocations in Firestore
            const allocsSnap = await db.collection('student_payment_allocations')
                .where('student_id', '==', delId)
                .get();
            
            const batch = db.batch();
            allocsSnap.forEach(doc => {
                batch.delete(db.collection('student_payment_allocations').doc(doc.id));
            });

            // Delete student doc
            batch.delete(db.collection('students').doc(String(delId)));
            await batch.commit();

            await logAudit(adminId, 'DELETE_STUDENT', 'STUDENT', delId,
                `Admin permanently removed student ${stToDel.student_code} (${stToDel.name}) from Firebase. Reason: ${dropoutReason}`);

            setFlash(req, 'success', `Student ${stToDel.student_code} (${stToDel.name}) has been permanently deleted from Firebase.`);
            return res.redirect('/admin/students');
        } catch (err) {
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
        const sDoc = await db.collection('students').doc(String(id)).get();
        return res.render('admin/student-edit', {
            pageTitle: `Edit: ${name}`,
            student: { ...sDoc.data(), joining_date_ymd: joiningDate, name, mobile, room_no: roomNo, monthly_fee: monthlyFee },
            errors
        });
    }

    try {
        await db.collection('students').doc(String(id)).update({
            name,
            mobile: cleanMobile || null,
            joining_date: joiningDate,
            mess_status: messStatus,
            hostel_status: hostelStatus,
            room_no: roomNo || null,
            monthly_fee: isNaN(monthlyFee) ? null : monthlyFee,
            status
        });

        await logAudit(adminId, 'UPDATE_STUDENT_DETAILS', 'STUDENT', id, `Updated student details for ${name} (ID: ${id}) in Firebase`);
        setFlash(req, 'success', `Student ${name} updated successfully in Firebase.`);
        return res.redirect(`/admin/student-view/${id}`);
    } catch (err) {
        console.error('Update student error:', err);
        errors.push(`Firebase error: ${err.message}`);
        const sDoc = await db.collection('students').doc(String(id)).get();
        return res.render('admin/student-edit', {
            pageTitle: `Edit: ${name}`,
            student: sDoc.data(),
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
        const sDoc = await db.collection('students').doc(String(id)).get();
        if (!sDoc.exists) {
            setFlash(req, 'danger', 'Student not found.');
            return res.redirect('/admin/students');
        }
        const student = sDoc.data();
        const today = formatYmd(new Date());
        const cycle = await getStudentCurrentCycle(id, today);

        const allocSnap = await db.collection('student_payment_allocations')
            .where('student_id', '==', id)
            .get();

        const allocations = [];
        for (const doc of allocSnap.docs) {
            const a = doc.data();
            let txn = null;
            if (a.payment_transaction_id) {
                const tDoc = await db.collection('payment_transactions').doc(String(a.payment_transaction_id)).get();
                if (tDoc.exists) txn = tDoc.data();
            }

            allocations.push({
                ...a,
                payment_method: txn ? txn.payment_method : 'CASH',
                utr_number: txn ? txn.utr_number : '',
                transaction_code: txn ? txn.transaction_code : '',
                cycle_start_formatted: formatDisplayDate(a.cycle_start_date),
                cycle_end_formatted: formatDisplayDate(a.cycle_end_date),
                due_date_formatted: formatDisplayDate(a.due_date),
                paid_date_formatted: (txn && txn.verified_at) ? formatDisplayDate(txn.verified_at) : ((txn && txn.created_at) ? formatDisplayDate(txn.created_at) : '—'),
                amount_formatted: `₹${parseFloat(a.allocated_amount || a.amount_due).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`
            });
        }

        allocations.sort((a, b) => b.cycle_number - a.cycle_number);

        res.render('admin/student-view', {
            pageTitle: `${student.name} (${student.student_code})`,
            student: {
                ...student,
                joining_date_formatted: formatDisplayDate(student.joining_date)
            },
            cycle,
            allocations
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

        const snap = await db.collection('students').get();
        const students = [];
        snap.forEach(doc => students.push(doc.data()));
        students.sort((a, b) => (a.name || '').localeCompare(b.name || ''));

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
