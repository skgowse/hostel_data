/**
 * Cycle Engine & Helper Utilities - Firebase Firestore Edition
 * Mess & Hostel Management System
 */

const { db } = require('../config/firebase');

// Format date to YYYY-MM-DD
function formatYmd(d) {
    if (!d) return '';
    if (typeof d === 'string') {
        const clean = d.split('T')[0];
        const parts = clean.split('-');
        if (parts.length === 3) {
            return `${parts[0]}-${parts[1].padStart(2, '0')}-${parts[2].padStart(2, '0')}`;
        }
        d = new Date(d);
    }
    if (isNaN(d.getTime())) return '';
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// Format date to DD-Mon-YYYY (e.g. 02-Aug-2026)
function formatDisplayDate(d) {
    if (!d || d === '0000-00-00') return 'N/A';
    if (typeof d === 'string') {
        const clean = d.split('T')[0];
        const parts = clean.split('-');
        if (parts.length === 3) {
            d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        } else {
            d = new Date(d);
        }
    }
    if (isNaN(d.getTime())) return String(d);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const day = String(d.getDate()).padStart(2, '0');
    const mon = months[d.getMonth()];
    const year = d.getFullYear();
    return `${day}-${mon}-${year}`;
}

// Days in month helper (handling leap years)
function daysInMonth(month, year) {
    return new Date(year, month, 0).getDate();
}

/**
 * Calculates a recurring cycle date anchored to the student's joining date.
 * Accurately handles variable month lengths with end-of-month clamping.
 */
function calculateCycleDate(baseDateStr, monthOffset) {
    const clean = baseDateStr.split('T')[0];
    const parts = clean.split('-');
    const origYear = parseInt(parts[0], 10);
    const origMonth = parseInt(parts[1], 10);
    const origDay = parseInt(parts[2], 10);

    const totalMonths = (origYear * 12 + (origMonth - 1)) + monthOffset;
    const targetYear = Math.floor(totalMonths / 12);
    const targetMonth = (totalMonths % 12) + 1;

    const maxDays = daysInMonth(targetMonth, targetYear);
    const targetDay = Math.min(origDay, maxDays);

    const yStr = String(targetYear);
    const mStr = String(targetMonth).padStart(2, '0');
    const dStr = String(targetDay).padStart(2, '0');

    return `${yStr}-${mStr}-${dStr}`;
}

/**
 * Calculate applicable monthly fee based on student settings
 */
async function getStudentApplicableFee(student) {
    if (student.monthly_fee !== null && student.monthly_fee !== undefined && !isNaN(parseFloat(student.monthly_fee))) {
        return {
            amount: parseFloat(student.monthly_fee),
            is_custom: true,
            source: 'STUDENT_CUSTOM_FEE'
        };
    }

    // Default system fee fallback
    let fee = 2500.00;
    const messActive = (student.mess_status === 'ACTIVE');
    const hostelActive = (student.hostel_status === 'ACTIVE');

    if (messActive && hostelActive) {
        fee = 3500.00;
    } else if (messActive) {
        fee = 2500.00;
    } else if (hostelActive) {
        fee = 1000.00;
    }

    return {
        amount: fee,
        is_custom: false,
        source: 'SERVICE_STANDARD_RATE'
    };
}

/**
 * Calculates the active cycle, due date, and status for a student in Firestore.
 */
async function getStudentCurrentCycle(studentId, asOfDateStr = null) {
    if (!asOfDateStr) {
        asOfDateStr = formatYmd(new Date());
    }

    const sDoc = await db.collection('students').doc(String(studentId)).get();
    if (!sDoc.exists) return null;
    const student = sDoc.data();

    const allocSnap = await db.collection('student_payment_allocations')
        .where('student_id', '==', parseInt(studentId, 10))
        .get();

    const allocRows = [];
    allocSnap.forEach(doc => allocRows.push(doc.data()));

    // Sort by cycle_number DESC
    allocRows.sort((a, b) => b.cycle_number - a.cycle_number);

    let maxCompleted = 0;
    let pendingAllocation = null;

    for (const row of allocRows) {
        if (row.status === 'COMPLETED') {
            if (row.cycle_number > maxCompleted) {
                maxCompleted = parseInt(row.cycle_number, 10);
            }
        }
        if (row.status === 'PENDING' && !pendingAllocation) {
            pendingAllocation = row;
        }
    }

    const currentCycleNum = maxCompleted + 1;
    const joiningDate = formatYmd(student.joining_date);

    const cycleStartDate = calculateCycleDate(joiningDate, currentCycleNum - 1);
    const cycleEndDate = calculateCycleDate(joiningDate, currentCycleNum);
    const dueDate = cycleEndDate;

    const feeInfo = await getStudentApplicableFee(student);
    let amountDue = feeInfo.amount;

    if (pendingAllocation && pendingAllocation.cycle_number === currentCycleNum) {
        amountDue = parseFloat(pendingAllocation.amount_due);
    }

    const asOfTime = new Date(asOfDateStr).getTime();
    const dueTime = new Date(dueDate).getTime();
    const daysDiff = Math.round((dueTime - asOfTime) / (1000 * 60 * 60 * 24));

    let status = 'UPCOMING';
    let statusLabel = 'Upcoming';
    let daysText = '';

    if (pendingAllocation && pendingAllocation.cycle_number === currentCycleNum) {
        status = 'PENDING_VERIFICATION';
        statusLabel = 'Pending Review';
        daysText = 'Receipt submitted';
    } else if (asOfDateStr === dueDate) {
        status = 'DUE';
        statusLabel = 'Due Today';
        daysText = 'Due today';
    } else if (asOfDateStr > dueDate) {
        status = 'OVERDUE';
        const overdueDays = Math.abs(daysDiff);
        statusLabel = `Overdue (${overdueDays} day${overdueDays > 1 ? 's' : ''})`;
        daysText = `${overdueDays} day${overdueDays > 1 ? 's' : ''} overdue`;
    } else {
        status = 'UPCOMING';
        statusLabel = `Due in ${daysDiff} day${daysDiff > 1 ? 's' : ''}`;
        daysText = `Due in ${daysDiff} day${daysDiff > 1 ? 's' : ''}`;
    }

    const messActive = (student.mess_status === 'ACTIVE');
    const hostelActive = (student.hostel_status === 'ACTIVE');
    let serviceLabel = 'None';
    if (messActive && hostelActive) serviceLabel = 'Mess + Hostel';
    else if (messActive) serviceLabel = 'Mess Only';
    else if (hostelActive) serviceLabel = 'Hostel Only';

    return {
        student_id: student.id,
        student_code: student.student_code,
        student_name: student.name,
        joining_date: joiningDate,
        joining_date_formatted: formatDisplayDate(joiningDate),
        cycle_number: currentCycleNum,
        cycle_start_date: cycleStartDate,
        cycle_end_date: cycleEndDate,
        cycle_label: `${formatDisplayDate(cycleStartDate)} → ${formatDisplayDate(cycleEndDate)}`,
        due_date: dueDate,
        due_date_formatted: formatDisplayDate(dueDate),
        service_label: serviceLabel,
        amount_due: amountDue,
        amount_due_formatted: `₹${amountDue.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
        is_custom_fee: feeInfo.is_custom,
        status: status,
        status_label: statusLabel,
        days_diff: daysDiff,
        days_text: daysText,
        pending_allocation: pendingAllocation
    };
}

/**
 * Generate Next Unique Student Code in Firestore: STU-00001
 */
async function generateStudentCode() {
    const snap = await db.collection('students').get();
    let maxCode = 0;
    let maxId = 0;

    snap.forEach(doc => {
        const st = doc.data();
        const idNum = parseInt(st.id, 10) || 0;
        if (idNum > maxId) maxId = idNum;

        if (st.student_code && st.student_code.startsWith('STU-')) {
            const num = parseInt(st.student_code.substring(4), 10) || 0;
            if (num > maxCode) maxCode = num;
        }
    });

    const next = Math.max(maxCode, maxId) + 1;
    return {
        code: `STU-${String(next).padStart(5, '0')}`,
        id: next
    };
}

/**
 * Audit Logger in Firestore
 */
async function logAudit(adminId, action, targetType, targetId, description) {
    try {
        await db.collection('audit_logs').add({
            admin_id: String(adminId || '1'),
            action,
            target_type: targetType,
            target_id: String(targetId || '0'),
            description,
            created_at: new Date().toISOString()
        });
    } catch (err) {
        console.error("Firestore audit log failed:", err.message);
    }
}

module.exports = {
    formatYmd,
    formatDisplayDate,
    calculateCycleDate,
    getStudentApplicableFee,
    getStudentCurrentCycle,
    generateStudentCode,
    logAudit
};
