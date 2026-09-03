<?php
/**
 * Admin Dashboard & Fee Due-Date Management (Joining-Date-Based Individual Cycles)
 * Mess & Hostel Management System
 */

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth/middleware.php';

require_admin();

$pdo = get_db_connection();

// Fetch Pending Transactions
$pendingTxnsStmt = $pdo->query("
    SELECT t.*, u.name as payer_name, u.mobile as payer_mobile,
           (SELECT COUNT(*) FROM student_payment_allocations a WHERE a.payment_transaction_id = t.id) as students_count
    FROM payment_transactions t
    JOIN users u ON t.payer_user_id = u.id
    WHERE t.status = 'PENDING'
    ORDER BY t.id DESC
");
$pendingTxns = $pendingTxnsStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <!-- Header Card -->
    <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <span class="text-uppercase text-muted fw-bold small tracking-wide">Administration Hub</span>
                <h3 class="fw-bold text-dark mb-1">Fee Cycles & Due Date Management</h3>
                <p class="text-muted small mb-0">Manage student billing cycles, verify payment receipts, or directly mark offline / cash payments as completed</p>
            </div>
            <div class="d-flex gap-2">
                <a href="/admin/students.php" class="btn btn-outline-primary btn-sm fw-semibold">
                    <i class="bi bi-people-fill me-1"></i> View Student List
                </a>
            </div>
        </div>
    </div>

    <!-- KPI Statistics (Calculated dynamically per joining date - Clickable Filters) -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card kpi-clickable-card kpi-card-overdue h-100" 
                 id="kpiCardOverdue" data-filter-status="OVERDUE" title="Click to filter Overdue Payments">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-title">Payments Overdue</div>
                        <div class="stat-value" id="kpiOverdueCount">0</div>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-exclamation-octagon-fill"></i>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top small d-flex justify-content-between align-items-center opacity-75">
                    <span>Due date has passed</span>
                    <span class="badge bg-danger text-white"><i class="bi bi-arrow-right"></i> Filter</span>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card kpi-clickable-card kpi-card-due-today h-100" 
                 id="kpiCardDueToday" data-filter-status="DUE_TODAY" title="Click to filter Payments Due Today">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-title">Due Today</div>
                        <div class="stat-value" id="kpiDueTodayCount">0</div>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-bell-fill"></i>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top small d-flex justify-content-between align-items-center opacity-75">
                    <span>Due date is today</span>
                    <span class="badge bg-warning text-dark"><i class="bi bi-arrow-right"></i> Filter</span>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card kpi-clickable-card kpi-card-due-7days h-100" 
                 id="kpiCardDue7Days" data-filter-status="DUE_7_DAYS" title="Click to filter Payments Due Within 7 Days">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-title">Due Within 7 Days</div>
                        <div class="stat-value" id="kpiDue7DaysCount">0</div>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-calendar-week-fill"></i>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top small d-flex justify-content-between align-items-center opacity-75">
                    <span>Upcoming in next 7 days</span>
                    <span class="badge bg-primary text-white"><i class="bi bi-arrow-right"></i> Filter</span>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card kpi-clickable-card kpi-card-completed h-100" 
                 id="kpiCardCompleted" data-filter-status="COMPLETED" title="Click to filter Completed Payments">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-title">Total Collected</div>
                        <div class="stat-value" id="kpiCollectedAmount">₹0.00</div>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top small d-flex justify-content-between align-items-center opacity-75">
                    <span>Verified fee allocations</span>
                    <span class="badge bg-success text-white"><i class="bi bi-arrow-right"></i> Filter</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Verifications Queue (Student Submitted Receipts) -->
    <?php if (!empty($pendingTxns)): ?>
        <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4" id="pendingTxnsSection">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-hourglass-split text-warning me-2"></i>Pending Verification Queue</h5>
                    <small class="text-muted">Student-submitted payment receipts awaiting administrative confirmation</small>
                </div>
                <span class="badge bg-warning text-dark font-monospace" id="pendingTxnCountBadge"><?= count($pendingTxns) ?> PENDING</span>
            </div>

            <div class="table-responsive">
                <table class="table custom-table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Txn ID</th>
                            <th>Payer Name</th>
                            <th>Mobile</th>
                            <th>UTR / Reference</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Cycles Covered</th>
                            <th>Submitted On</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="pendingTxnsTbody">
                        <?php foreach ($pendingTxns as $pt): ?>
                            <tr id="pending-txn-row-<?= $pt['id'] ?>">
                                <td class="font-monospace fw-bold text-primary">
                                    <a href="/admin/payment-view.php?id=<?= $pt['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($pt['transaction_code']) ?>
                                    </a>
                                </td>
                                <td class="fw-semibold text-dark"><?= htmlspecialchars($pt['payer_name']) ?></td>
                                <td class="font-monospace text-muted small"><?= htmlspecialchars($pt['payer_mobile']) ?></td>
                                <td class="font-monospace fw-bold"><?= htmlspecialchars($pt['utr_number']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($pt['payment_method']) ?></span></td>
                                <td class="fw-bold text-success font-monospace">₹<?= number_format($pt['total_amount'], 2) ?></td>
                                <td>
                                    <span class="badge bg-info-subtle text-info-emphasis border">
                                        <?= $pt['students_count'] ?> Student(s)
                                    </span>
                                    <div class="small text-muted mt-1" style="font-size: 0.78rem;"><?= htmlspecialchars($pt['cycle_summary'] ?: 'Standard Cycle') ?></div>
                                </td>
                                <td class="small text-muted"><?= format_display_date($pt['created_at']) ?> <?= date('H:i', strtotime($pt['created_at'])) ?></td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="/admin/payment-view.php?id=<?= $pt['id'] ?>" class="btn btn-outline-secondary" title="View Full Receipt">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button type="button" class="btn btn-success fw-semibold verify-btn" 
                                                data-txn-id="<?= $pt['id'] ?>" 
                                                data-txn-code="<?= htmlspecialchars($pt['transaction_code']) ?>"
                                                data-amount="₹<?= number_format($pt['total_amount'], 2) ?>"
                                                title="Confirm & Mark Completed">
                                            <i class="bi bi-check-lg me-1"></i>Verify
                                        </button>
                                        <button type="button" class="btn btn-outline-danger reject-btn"
                                                data-txn-id="<?= $pt['id'] ?>"
                                                data-txn-code="<?= htmlspecialchars($pt['transaction_code']) ?>"
                                                title="Reject Payment">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Dynamic Due-Date & Cycle Filter Card -->
    <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-funnel text-primary me-2"></i>Filter Students by Due Date & Cycle Status
            </h5>
            <div class="small text-muted">
                Displaying students ordered by joining date in ascending order
            </div>
        </div>

        <div class="row g-3 align-items-end">
            <!-- 1. Status Filter -->
            <div class="col-md-3">
                <label class="form-label small fw-bold">Due Date Status</label>
                <select class="form-select" id="ajaxStatusSelect">
                    <option value="ALL">All Active Students</option>
                    <option value="OVERDUE">Overdue Payments</option>
                    <option value="DUE_TODAY">Due Today</option>
                    <option value="DUE_7_DAYS">Due Within 7 Days</option>
                    <option value="DUE_30_DAYS">Due Within 30 Days</option>
                    <option value="PENDING">Pending Verification</option>
                    <option value="COMPLETED">Completed Payments</option>
                </select>
            </div>

            <!-- 2. Service Filter -->
            <div class="col-md-2">
                <label class="form-label small fw-bold">Service Type</label>
                <select class="form-select" id="ajaxServiceSelect">
                    <option value="ALL">All Services</option>
                    <option value="MESS">Mess Only</option>
                    <option value="HOSTEL">Hostel Only</option>
                    <option value="BOTH">Mess + Hostel</option>
                </select>
            </div>

            <!-- 3. Custom Due Date Range -->
            <div class="col-md-2">
                <label class="form-label small fw-bold">Due From Date</label>
                <input type="date" class="form-control" id="ajaxFromDueDate">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Due To Date</label>
                <input type="date" class="form-control" id="ajaxToDueDate">
            </div>

            <!-- 4. Debounced Search Box -->
            <div class="col-md-2">
                <label class="form-label small fw-bold">Search Student / UTR</label>
                <input type="text" class="form-control" id="ajaxSearchInput" placeholder="Name, Mobile, ID...">
            </div>

            <!-- 5. Reset -->
            <div class="col-md-1">
                <button type="button" id="resetFiltersBtn" class="btn btn-light border w-100" title="Reset Filters">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Output Table -->
    <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <span class="text-muted small">Calculated as of: <strong class="text-primary font-monospace"><?= format_display_date(date('Y-m-d')) ?></strong></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div id="filterLoadingIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status"></div>
                <span class="badge-count font-monospace" id="matchCountBadge">0 Students</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table custom-table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAllStudentsCheckbox" title="Select All Matching Students">
                        </th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Mobile</th>
                        <th>Joining Date</th>
                        <th>Current Cycle Interval</th>
                        <th>Due Date</th>
                        <th>Fee</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="studentFeeStatusTbody">
                    <!-- Dynamic Rows Injected by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Floating Sticky Bulk Action Toolbar -->
    <div id="bulkActionBar" class="card border-0 shadow-lg rounded-4 bg-primary text-white p-3 mb-4 d-none position-sticky z-3" style="bottom: 1.5rem;">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-check2-circle fs-4 text-white"></i>
                <span class="fw-bold fs-6 text-white" id="selectedStudentsCount">0 Students Selected</span>
                <span class="badge bg-white text-primary font-monospace ms-2 fs-6 shadow-sm" id="selectedStudentsTotalAmount">Total: ₹0.00</span>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-light fw-bold px-3 text-primary shadow-sm" id="openBulkPayModalBtn">
                    <i class="bi bi-cash-stack me-1"></i> Mark Selected as Paid
                </button>
                <button type="button" class="btn btn-outline-light btn-sm" id="clearSelectionBtn">
                    Clear Selection
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Bulk Multi-Student Direct Payment -->
<div class="modal fade" id="bulkPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="bulkPaymentForm">
                <?= csrf_field() ?>
                <div class="modal-header bg-success text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="bi bi-people-fill me-2"></i>Bulk Mark as Paid</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-success d-flex justify-content-between align-items-center py-2 px-3 mb-3 border-0 rounded-3">
                        <div><strong id="bulkModalCount">0</strong> students selected for payment</div>
                        <div>Total Amount: <strong id="bulkModalTotal" class="fs-5 font-monospace text-success">₹0.00</strong></div>
                    </div>

                    <!-- Preview List of Selected Students -->
                    <label class="form-label small fw-bold">Selected Students Preview</label>
                    <div class="table-responsive mb-3 border rounded-3" style="max-height: 200px; overflow-y: auto;">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>ID</th>
                                    <th>Student Name</th>
                                    <th>Active Cycle</th>
                                    <th class="text-end">Fee Amount</th>
                                </tr>
                            </thead>
                            <tbody id="bulkModalStudentList">
                            </tbody>
                        </table>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="bulkPayMethod" class="form-label fw-bold">Payment Method</label>
                            <select class="form-select" id="bulkPayMethod" required>
                                <option value="CASH" selected>Cash Payment</option>
                                <option value="UPI">UPI / GPay / PhonePe</option>
                                <option value="BANK_TRANSFER">Direct Bank Transfer / NEFT</option>
                                <option value="POS">Card / POS Swipe</option>
                                <option value="CHEQUE">Cheque / Demand Draft</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="bulkPayDate" class="form-label fw-bold">Payment Date (Date to Save) <span class="text-danger">*</span></label>
                            <input type="date" class="form-control font-monospace fw-semibold" id="bulkPayDate" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="bulkPayNotes" class="form-label fw-bold">Receipt Reference / Note</label>
                        <input type="text" class="form-control" id="bulkPayNotes" placeholder="e.g. Bulk cash collection at Warden Office">
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 pt-0">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="confirmBulkPayBtn" class="btn btn-success px-4 fw-bold shadow-sm">
                        <i class="bi bi-check-circle-fill me-1"></i> Confirm & Mark All Paid
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Direct / Offline Payment Entry (Mark Student Paid Directly) -->
<div class="modal fade" id="directPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="directPaymentForm">
                <?= csrf_field() ?>
                <input type="hidden" id="directPayStudentId">

                <div class="modal-header bg-success text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="bi bi-cash-stack me-2"></i>Record Payment / Mark as Paid</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong id="directPayStudentName" class="fs-6 text-dark"></strong>
                            <span id="directPayStudentCode" class="font-monospace text-primary fw-bold"></span>
                        </div>
                        <div class="small text-muted">
                            Active Cycle: <span id="directPayCycleLabel" class="fw-semibold text-dark"></span>
                            <br>
                            Due Date: <span id="directPayDueDate" class="text-danger fw-bold"></span>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="directPayMethod" class="form-label fw-bold">Payment Method</label>
                            <select class="form-select" id="directPayMethod" required>
                                <option value="CASH" selected>Cash Payment</option>
                                <option value="UPI">UPI / GPay / PhonePe</option>
                                <option value="BANK_TRANSFER">Direct Bank Transfer / NEFT</option>
                                <option value="POS">Card / POS Swipe</option>
                                <option value="CHEQUE">Cheque / Demand Draft</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="directPayAmount" class="form-label fw-bold">Amount Paid (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control font-monospace fw-bold text-success" id="directPayAmount" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="directPayNotes" class="form-label fw-bold">Receipt Reference / Note</label>
                        <input type="text" class="form-control" id="directPayNotes" placeholder="e.g. Cash collected at Warden Office, Receipt #102">
                    </div>

                    <div class="mb-3">
                        <label for="directPayDate" class="form-label fw-bold">Payment Date</label>
                        <input type="date" class="form-control" id="directPayDate" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 pt-0">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="confirmDirectPayBtn" class="btn btn-success px-4 fw-semibold">
                        <i class="bi bi-check-circle-fill me-1"></i> Confirm & Mark Completed
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Rejection Reason for Payment -->
<div class="modal fade" id="rejectTxnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-danger text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-x-circle-fill me-2"></i>Reject Payment Submission?</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-secondary small mb-3">
                    Specify the reason for rejecting transaction <strong id="rejectModalTxnCode" class="font-monospace text-danger"></strong>.
                </p>
                <input type="hidden" id="rejectModalTxnId">
                <div class="mb-3">
                    <label for="rejectPaymentReason" class="form-label fw-bold">Rejection Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="rejectPaymentReason" rows="3" required placeholder="e.g. Screenshot unreadable or UTR does not match bank records."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 pt-0">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirmRejectTxnBtn" class="btn btn-danger px-4 fw-semibold"><i class="bi bi-x-circle me-1"></i>Confirm Rejection</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
