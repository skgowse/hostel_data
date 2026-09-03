/**
 * Cycle Engine & Helper Utilities - Node.js
 * Mess & Hostel Management System
 */

// Format date to YYYY-MM-DD
function formatYmd(d) {
    if (!d) return '';
    if (typeof d === 'string') {
        const parts = d.split('T')[0].split('-');
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
async function getStudentApplicableFee(db, student) {
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
 * Calculates the active cycle, due date, and status for a student.
 */
async function getStudentCurrentCycle(db, studentId, asOfDateStr = null) {
    if (!asOfDateStr) {
        asOfDateStr = formatYmd(new Date());
    }

    const [students] = await db.query("SELECT * FROM students WHERE id = ? LIMIT 1", [studentId]);
    if (!students || students.length === 0) return null;
    const student = students[0];

    const [allocRows] = await db.query(`
        SELECT cycle_number, status, payment_transaction_id, allocated_amount, amount_due
        FROM student_payment_allocations
        WHERE student_id = ?
        ORDER BY cycle_number DESC, id DESC
    `, [studentId]);

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

    const feeInfo = await getStudentApplicableFee(db, student);
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
 * Generate Next Unique Student Code: STU-00001
 */
async function generateStudentCode(db) {
    const [rows] = await db.query("SELECT MAX(CAST(SUBSTRING(student_code, 5) AS UNSIGNED)) as max_code, MAX(id) as max_id FROM students WHERE student_code LIKE 'STU-%'");
    const row = rows[0] || {};
    const maxCode = row.max_code ? parseInt(row.max_code, 10) : 0;
    const maxId = row.max_id ? parseInt(row.max_id, 10) : 0;
    const next = Math.max(maxCode, maxId) + 1;
    return `STU-${String(next).padStart(5, '0')}`;
}

/**
 * Audit Logger
 */
async function logAudit(db, adminId, action, targetType, targetId, description) {
    try {
        await db.query(
            "INSERT INTO audit_logs (admin_id, action, target_type, target_id, description) VALUES (?, ?, ?, ?, ?)",
            [adminId, action, targetType, targetId, description]
        );
    } catch (err) {
        console.error("Audit log failed:", err.message);
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
