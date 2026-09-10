/**
 * Mess & Hostel Management System - Core Admin Application Engine
 * Optimized, Lightweight & Modern JavaScript
 */

// Global HTML Sanitizer
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
window.escapeHtml = escapeHtml;

// Debounce Utility for Live Search
function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// Universal CSV Exporter with UTF-8 BOM for Excel Compatibility
function exportDataToCSV(filename, headers, rows) {
    const csvRows = [headers.join(',')];
    rows.forEach(r => csvRows.push(r.join(',')));
    const csvString = csvRows.join('\r\n');
    const blob = new Blob(['\uFEFF' + csvString], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}
window.exportDataToCSV = exportDataToCSV;

document.addEventListener('DOMContentLoaded', function () {
    // -------------------------------------------------------------
    // 1. Dark Mode / Light Mode Theme Engine
    // -------------------------------------------------------------
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const themeIcon = document.getElementById('themeIcon');

    function updateThemeIcon(theme) {
        if (!themeIcon) return;
        if (theme === 'dark') {
            themeIcon.className = 'bi bi-sun-fill text-warning';
            if (themeToggleBtn) themeToggleBtn.setAttribute('title', 'Switch to Light / White Mode');
        } else {
            themeIcon.className = 'bi bi-moon-stars-fill text-primary';
            if (themeToggleBtn) themeToggleBtn.setAttribute('title', 'Switch to Dark Mode');
        }
    }

    const currentTheme = document.documentElement.getAttribute('data-bs-theme') || localStorage.getItem('app_theme') || 'dark';
    updateThemeIcon(currentTheme);

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function () {
            const activeTheme = document.documentElement.getAttribute('data-bs-theme') || 'dark';
            const newTheme = activeTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('app_theme', newTheme);
            updateThemeIcon(newTheme);
        });
    }

    // -------------------------------------------------------------
    // 2. Universal Sidebar Toggle (Desktop Collapse & Mobile Drawer)
    // -------------------------------------------------------------
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    if (sidebarToggle && sidebar) {
        if (window.innerWidth >= 992) {
            if (localStorage.getItem('sidebar_collapsed') === 'true') {
                sidebar.classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed-active');
            }
        }

        sidebarToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (window.innerWidth < 992) {
                sidebar.classList.toggle('show');
            } else {
                sidebar.classList.toggle('collapsed');
                const isCollapsed = sidebar.classList.contains('collapsed');
                document.body.classList.toggle('sidebar-collapsed-active', isCollapsed);
                localStorage.setItem('sidebar_collapsed', isCollapsed);
            }
        });

        document.addEventListener('click', function (e) {
            if (window.innerWidth < 992 && sidebar.classList.contains('show')) {
                if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });
    }

    // -------------------------------------------------------------
    // 3. Conditional Room Number Input on Student Forms
    // -------------------------------------------------------------
    const hostelSelect = document.getElementById('hostel_status');
    const roomContainer = document.getElementById('room_number_container');
    const roomInput = document.getElementById('room_no');

    function updateRoomField() {
        if (!hostelSelect || !roomContainer) return;
        const val = hostelSelect.value.toString().toUpperCase();
        if (val === '1' || val === 'YES' || val === 'ACTIVE') {
            roomContainer.style.display = 'block';
        } else {
            roomContainer.style.display = 'none';
            if (roomInput) roomInput.value = '';
        }
    }

    if (hostelSelect) {
        hostelSelect.addEventListener('change', updateRoomField);
        updateRoomField();
    }

    // -------------------------------------------------------------
    // 4. Dynamic AJAX Table Filtering & Bulk Payment Selection
    // -------------------------------------------------------------
    const ajaxStatusSelect = document.getElementById('ajaxStatusSelect');
    const ajaxServiceSelect = document.getElementById('ajaxServiceSelect');
    const ajaxFromDueDate = document.getElementById('ajaxFromDueDate');
    const ajaxToDueDate = document.getElementById('ajaxToDueDate');
    const ajaxSearchInput = document.getElementById('ajaxSearchInput');
    const studentFeeStatusTbody = document.getElementById('studentFeeStatusTbody');
    const filterLoading = document.getElementById('filterLoadingIndicator');
    const matchCountBadge = document.getElementById('matchCountBadge');

    const kpiOverdue = document.getElementById('kpiOverdueCount');
    const kpiDueToday = document.getElementById('kpiDueTodayCount');
    const kpiDue7Days = document.getElementById('kpiDue7DaysCount');
    const kpiCollected = document.getElementById('kpiCollectedAmount');

    const selectedStudentsMap = new Map();

    function updateBulkActionBar() {
        const bulkBar = document.getElementById('bulkActionBar');
        const countEl = document.getElementById('selectedStudentsCount');
        const totalEl = document.getElementById('selectedStudentsTotalAmount');
        const selectAllCb = document.getElementById('selectAllStudentsCheckbox');

        if (!bulkBar) return;

        const count = selectedStudentsMap.size;
        if (count > 0) {
            if (countEl) countEl.textContent = `${count} Student${count > 1 ? 's' : ''} Selected`;
            bulkBar.classList.remove('d-none');
        } else {
            bulkBar.classList.add('d-none');
        }

        if (selectAllCb) {
            const selectableBoxes = document.querySelectorAll('.student-select-checkbox:not(:disabled)');
            if (selectableBoxes.length > 0) {
                const allChecked = Array.from(selectableBoxes).every(cb => cb.checked);
                const someChecked = Array.from(selectableBoxes).some(cb => cb.checked);
                selectAllCb.checked = allChecked;
                selectAllCb.indeterminate = someChecked && !allChecked;
            } else {
                selectAllCb.checked = false;
                selectAllCb.indeterminate = false;
            }
        }
    }

    function fetchFilteredStudents() {
        if (!studentFeeStatusTbody) return;

        const status = ajaxStatusSelect ? ajaxStatusSelect.value : 'ALL';
        const service = ajaxServiceSelect ? ajaxServiceSelect.value : 'ALL';
        const fromDue = ajaxFromDueDate ? ajaxFromDueDate.value : '';
        const toDue = ajaxToDueDate ? ajaxToDueDate.value : '';
        const q = ajaxSearchInput ? ajaxSearchInput.value.trim() : '';

        if (filterLoading) filterLoading.classList.remove('d-none');

        const url = `/api/admin-unpaid-filter?status=${encodeURIComponent(status)}&service=${encodeURIComponent(service)}&from_due_date=${encodeURIComponent(fromDue)}&to_due_date=${encodeURIComponent(toDue)}&q=${encodeURIComponent(q)}`;

        fetch(url)
            .then(r => r.json())
            .then(res => {
                if (filterLoading) filterLoading.classList.add('d-none');
                if (res.success) {
                    window.currentFilteredDashboardStudents = res.students || [];
                    if (kpiOverdue) kpiOverdue.textContent = res.summary.overdue_count;
                    if (kpiDueToday) kpiDueToday.textContent = res.summary.due_today_count;
                    if (kpiDue7Days) kpiDue7Days.textContent = res.summary.due_7_days_count;
                    if (kpiCollected) kpiCollected.textContent = res.summary.amount_collected_formatted;
                    if (matchCountBadge) matchCountBadge.textContent = res.total_matching + ' Students';
                    const exportCountSpan = document.getElementById('exportCountSpan');
                    if (exportCountSpan) exportCountSpan.textContent = res.total_matching;

                    if (res.students && res.students.length > 0) {
                        let html = '';
                        res.students.forEach(st => {
                            let statusBadge = '';
                            if (st.status === 'OVERDUE') {
                                statusBadge = `<span class="badge bg-danger"><i class="bi bi-exclamation-octagon me-1"></i>OVERDUE (${st.days_text})</span>`;
                            } else if (st.status === 'DUE') {
                                statusBadge = `<span class="badge bg-warning text-dark"><i class="bi bi-bell-fill me-1"></i>DUE TODAY</span>`;
                            } else if (st.status === 'PENDING_VERIFICATION') {
                                statusBadge = `<span class="badge bg-warning-subtle text-warning-emphasis border"><i class="bi bi-hourglass-split me-1"></i>PENDING REVIEW</span>`;
                            } else if (st.status === 'COMPLETED') {
                                statusBadge = `<span class="badge bg-success-subtle text-success border"><i class="bi bi-check-circle-fill me-1"></i>PAID</span><div class="small text-success fw-semibold mt-1">${escapeHtml(st.days_text)}</div>`;
                            } else {
                                statusBadge = `<span class="badge bg-primary-subtle text-primary border"><i class="bi bi-calendar-check me-1"></i>UPCOMING (${st.days_text})</span>`;
                            }

                            const safeName = (st.name || '').replace(/'/g, "\\'");
                            const safeCycleLabel = (st.cycle_label || '').replace(/'/g, "\\'");
                            const isPaid = (st.status === 'COMPLETED');
                            const hasPaidCycle = Boolean(st.last_paid_info) || isPaid;
                            const markPaidBtn = (!isPaid)
                                ? `<button type="button" class="btn btn-outline-success btn-sm mark-paid-instant-btn" title="Mark as Paid immediately"
                                           onclick="markStudentAsPaidDirectly('${st.id}', '${st.applicable_fee}', this)">
                                        <i class="bi bi-cash-stack me-1"></i>Mark Paid
                                    </button>`
                                : `<span class="badge bg-success text-white px-2 py-1"><i class="bi bi-check2-all me-1"></i>PAID</span>`;

                            let cycleSubNote = `<span class="d-block small text-primary fw-semibold mt-1">${escapeHtml(st.cycle_label)}</span>`;
                            if (st.last_paid_info) {
                                cycleSubNote += `<div class="small text-success fw-semibold mt-1"><i class="bi bi-check2-circle me-1"></i>${escapeHtml(st.last_paid_info)}</div>`;
                            }

                            const isChecked = selectedStudentsMap.has(Number(st.id));
                            const paidBadgeIcon = hasPaidCycle
                                ? `<span class="badge bg-success-subtle text-success border border-success ms-1 small" title="Student has completed paid cycle"><i class="bi bi-check2-circle me-1"></i>Paid</span>`
                                : '';

                            html += `<tr class="${hasPaidCycle ? 'row-paid-highlight' : ''}">
                                <td style="width: 40px;">
                                    <input type="checkbox" class="form-check-input student-select-checkbox" 
                                           data-student-id="${st.id}" 
                                           data-student-code="${st.student_code}" 
                                           data-student-name="${safeName}" 
                                           data-fee="${st.applicable_fee}" 
                                           data-cycle="${st.cycle_number}" 
                                           data-cycle-label="${safeCycleLabel}"
                                           ${isPaid ? 'disabled title="Payment already completed"' : ''}
                                           ${isChecked ? 'checked' : ''}>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        ${markPaidBtn}
                                        <a href="/admin/student-view/${st.id}" class="btn btn-outline-secondary" title="View Profile">
                                            <i class="bi bi-person"></i>
                                        </a>
                                    </div>
                                </td>
                                <td class="student-name-cell fw-semibold">${escapeHtml(st.name)} ${paidBadgeIcon}</td>
                                <td class="font-monospace text-muted small">${st.mobile}</td>
                                <td class="small text-muted">${st.joining_date}</td>
                                <td>
                                    <span class="badge-cycle font-monospace">Cycle ${st.cycle_number}</span>
                                    ${cycleSubNote}
                                </td>
                                <td class="font-monospace ${isPaid ? 'text-success fw-semibold' : 'text-danger fw-bold'}">${st.due_date_formatted}</td>
                                <td class="fw-bold font-monospace text-success">${st.applicable_fee_formatted}</td>
                                <td>${statusBadge}</td>
                            </tr>`;
                        });
                        studentFeeStatusTbody.innerHTML = html;

                        document.querySelectorAll('.student-select-checkbox').forEach(cb => {
                            cb.addEventListener('change', function () {
                                const id = Number(this.getAttribute('data-student-id'));
                                if (this.checked) {
                                    selectedStudentsMap.set(id, {
                                        id: id,
                                        code: this.getAttribute('data-student-code'),
                                        name: this.getAttribute('data-student-name'),
                                        fee: parseFloat(this.getAttribute('data-fee')) || 0,
                                        cycle: this.getAttribute('data-cycle'),
                                        cycleLabel: this.getAttribute('data-cycle-label')
                                    });
                                } else {
                                    selectedStudentsMap.delete(id);
                                }
                                updateBulkActionBar();
                            });
                        });

                        updateBulkActionBar();
                    } else {
                        studentFeeStatusTbody.innerHTML = `<tr><td colspan="9" class="text-center py-5 text-muted"><i class="bi bi-person-x fs-1 d-block mb-2"></i>No students matching the selected filter criteria.</td></tr>`;
                        updateBulkActionBar();
                    }
                }
            })
            .catch(() => {
                if (filterLoading) filterLoading.classList.add('d-none');
            });
    }
    window.fetchFilteredStudents = fetchFilteredStudents;

    // Master Select All Checkbox Handler
    const selectAllCb = document.getElementById('selectAllStudentsCheckbox');
    if (selectAllCb) {
        selectAllCb.addEventListener('change', function () {
            const isChecked = this.checked;
            document.querySelectorAll('.student-select-checkbox:not(:disabled)').forEach(cb => {
                cb.checked = isChecked;
                const id = Number(cb.getAttribute('data-student-id'));
                if (isChecked) {
                    selectedStudentsMap.set(id, {
                        id: id,
                        code: cb.getAttribute('data-student-code'),
                        name: cb.getAttribute('data-student-name'),
                        fee: parseFloat(cb.getAttribute('data-fee')) || 0,
                        cycle: cb.getAttribute('data-cycle'),
                        cycleLabel: cb.getAttribute('data-cycle-label')
                    });
                } else {
                    selectedStudentsMap.delete(id);
                }
            });
            updateBulkActionBar();
        });
    }

    // Clear Selection Button
    const clearSelBtn = document.getElementById('clearSelectionBtn');
    if (clearSelBtn) {
        clearSelBtn.addEventListener('click', function () {
            selectedStudentsMap.clear();
            document.querySelectorAll('.student-select-checkbox').forEach(cb => cb.checked = false);
            if (selectAllCb) {
                selectAllCb.checked = false;
                selectAllCb.indeterminate = false;
            }
            updateBulkActionBar();
        });
    }

    // Open Bulk Payment Modal
    const openBulkModalBtn = document.getElementById('openBulkPayModalBtn');
    if (openBulkModalBtn) {
        openBulkModalBtn.addEventListener('click', function () {
            if (selectedStudentsMap.size === 0) {
                alert('Please select at least one student.');
                return;
            }

            const modalCount = document.getElementById('bulkModalCount');
            const modalTotal = document.getElementById('bulkModalTotal');
            const modalListTbody = document.getElementById('bulkModalStudentList');

            if (modalCount) modalCount.textContent = selectedStudentsMap.size;

            let listHtml = '';
            selectedStudentsMap.forEach(st => {
                listHtml += `<tr>
                    <td class="font-monospace fw-bold text-primary">${st.code}</td>
                    <td class="fw-semibold">${escapeHtml(st.name)}</td>
                    <td><span class="badge bg-light text-dark font-monospace">Cycle ${st.cycle}</span></td>
                </tr>`;
            });

            if (modalListTbody) modalListTbody.innerHTML = listHtml;

            const modalEl = document.getElementById('bulkPaymentModal');
            if (modalEl) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
        });
    }

    // Submit Bulk Payment Form
    const bulkPayForm = document.getElementById('bulkPaymentForm');
    if (bulkPayForm) {
        bulkPayForm.addEventListener('submit', function (e) {
            e.preventDefault();

            if (selectedStudentsMap.size === 0) {
                alert('No students selected.');
                return;
            }

            const confirmBtn = document.getElementById('confirmBulkPayBtn');
            const method = document.getElementById('bulkPayMethod') ? document.getElementById('bulkPayMethod').value : 'CASH';
            const paidDate = document.getElementById('bulkPayDate') ? document.getElementById('bulkPayDate').value : '';
            const notes = document.getElementById('bulkPayNotes') ? document.getElementById('bulkPayNotes').value : '';

            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';
            }

            const studentIds = Array.from(selectedStudentsMap.keys());
            const params = new URLSearchParams();
            params.append('action', 'bulk_direct_payment');
            params.append('student_ids', JSON.stringify(studentIds));
            params.append('payment_method', method);
            params.append('paid_date', paidDate);
            params.append('notes', notes);

            fetch('/api/admin-payments', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
            .then(r => r.json())
            .then(res => {
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Confirm & Mark All Paid';
                }

                const modalEl = document.getElementById('bulkPaymentModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();

                if (res.success) {
                    alert(res.message);
                    selectedStudentsMap.clear();
                    fetchFilteredStudents();
                } else {
                    alert('Error: ' + res.message);
                }
            })
            .catch(() => {
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Confirm & Mark All Paid';
                }
                alert('Connection error recording bulk payment.');
            });
        });
    }

    function syncActiveKpiCard(status) {
        document.querySelectorAll('.kpi-clickable-card').forEach(card => {
            if (card.getAttribute('data-filter-status') === status) {
                card.classList.add('active-kpi-card');
            } else {
                card.classList.remove('active-kpi-card');
            }
        });
    }

    if (ajaxStatusSelect) {
        ajaxStatusSelect.addEventListener('change', function () {
            if (ajaxStatusSelect.value === 'ALL') {
                if (ajaxFromDueDate) ajaxFromDueDate.value = '';
                if (ajaxToDueDate) ajaxToDueDate.value = '';
            }
            syncActiveKpiCard(ajaxStatusSelect.value);
            fetchFilteredStudents();
        });
        if (ajaxServiceSelect) ajaxServiceSelect.addEventListener('change', fetchFilteredStudents);
        if (ajaxFromDueDate) ajaxFromDueDate.addEventListener('change', fetchFilteredStudents);
        if (ajaxToDueDate) ajaxToDueDate.addEventListener('change', fetchFilteredStudents);
        if (ajaxSearchInput) ajaxSearchInput.addEventListener('input', debounce(fetchFilteredStudents, 300));

        // Clickable KPI Statistics Cards Handler
        document.querySelectorAll('.kpi-clickable-card').forEach(card => {
            card.addEventListener('click', function () {
                const targetStatus = this.getAttribute('data-filter-status');
                if (targetStatus && ajaxStatusSelect) {
                    if (ajaxStatusSelect.value === targetStatus) {
                        ajaxStatusSelect.value = 'ALL';
                        syncActiveKpiCard('ALL');
                    } else {
                        ajaxStatusSelect.value = targetStatus;
                        syncActiveKpiCard(targetStatus);
                    }

                    if (ajaxFromDueDate) ajaxFromDueDate.value = '';
                    if (ajaxToDueDate) ajaxToDueDate.value = '';

                    fetchFilteredStudents();
                }
            });
        });

        const resetBtn = document.getElementById('resetFiltersBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                if (ajaxStatusSelect) ajaxStatusSelect.value = 'ALL';
                if (ajaxServiceSelect) ajaxServiceSelect.value = 'ALL';
                if (ajaxFromDueDate) ajaxFromDueDate.value = '';
                if (ajaxToDueDate) ajaxToDueDate.value = '';
                if (ajaxSearchInput) ajaxSearchInput.value = '';
                syncActiveKpiCard('ALL');
                fetchFilteredStudents();
            });
        }

        // Export Filtered Dashboard Records (Instant CSV Download)
        function exportCurrentDashboardFilteredData() {
            const students = window.currentFilteredDashboardStudents || [];
            if (students.length === 0) {
                alert('No student records found to export for the active filters.');
                return;
            }

            const headers = [
                '"Student Code"',
                '"Student Name"',
                '"Mobile Number"',
                '"Joining Date"',
                '"Billing Cycle"',
                '"Cycle Interval"',
                '"Due Date"',
                '"Fee Amount (INR)"',
                '"Cycle Status"',
                '"Status Details / Payment Notes"',
                '"Room Number"'
            ];

            const rows = students.map(st => [
                `"${st.student_code || ''}"`,
                `"${(st.name || '').replace(/"/g, '""')}"`,
                `"${st.mobile || ''}"`,
                `"${st.joining_date || ''}"`,
                `"Cycle ${st.cycle_number || ''}"`,
                `"${(st.cycle_label || '').replace(/"/g, '""')}"`,
                `"${st.due_date_formatted || ''}"`,
                `"${st.applicable_fee || 0}"`,
                `"${st.status || ''}"`,
                `"${(st.days_text || st.last_paid_info || '').replace(/"/g, '""')}"`,
                `"${st.room_no || ''}"`
            ]);

            const statusVal = (ajaxStatusSelect ? ajaxStatusSelect.value : 'all').toLowerCase();
            const dateStamp = new Date().toISOString().slice(0, 10);
            exportDataToCSV(`filtered_student_billing_${statusVal}_${dateStamp}.csv`, headers, rows);
        }

        const exportDashBtn = document.getElementById('exportDashboardFilteredBtn');
        if (exportDashBtn) exportDashBtn.addEventListener('click', exportCurrentDashboardFilteredData);

        const exportToolbarBtn = document.getElementById('exportToolbarFilteredBtn');
        if (exportToolbarBtn) exportToolbarBtn.addEventListener('click', exportCurrentDashboardFilteredData);

        fetchFilteredStudents();
    }

    // -------------------------------------------------------------
    // 5. Student Directory Live AJAX Search & Dynamic Filter
    // -------------------------------------------------------------
    const dirSearchInput = document.getElementById('studentDirectorySearch');
    const dirMessSelect = document.getElementById('mess');
    const dirHostelSelect = document.getElementById('hostel');
    const dirSortSelect = document.getElementById('sort');
    const dirTableBody = document.getElementById('studentsDirectoryTableBody');
    const dirCountBadge = document.getElementById('studentsDirectoryCount');
    const dirSpinner = document.getElementById('studentsDirectorySpinner');
    const dirFilterForm = document.getElementById('studentsDirectoryFilterForm');
    const clearDirBtn = document.getElementById('clearDirectoryFiltersBtn');

    function fetchDirectoryStudents() {
        if (!dirTableBody) return;

        const q = dirSearchInput ? dirSearchInput.value.trim() : '';
        const mess = dirMessSelect ? dirMessSelect.value : '';
        const hostel = dirHostelSelect ? dirHostelSelect.value : '';
        const sort = dirSortSelect ? dirSortSelect.value : 'date_asc';

        if (dirSpinner) dirSpinner.classList.remove('d-none');

        const params = new URLSearchParams({ q, mess, hostel, sort });
        fetch(`/api/admin-students-filter?${params.toString()}`)
            .then(r => r.json())
            .then(res => {
                if (dirSpinner) dirSpinner.classList.add('d-none');
                if (res.success) {
                    window.currentFilteredDirectoryStudents = res.students || [];
                    if (dirCountBadge) dirCountBadge.textContent = res.total;

                    if (res.students && res.students.length > 0) {
                        let html = '';
                        res.students.forEach(st => {
                            let servicesBadge = '';
                            if (st.mess_status === 'ACTIVE') {
                                servicesBadge += '<span class="badge bg-primary-subtle text-primary border me-1">Mess</span>';
                            }
                            if (st.hostel_status === 'ACTIVE') {
                                servicesBadge += '<span class="badge bg-info-subtle text-info border">Hostel</span>';
                            }
                            if (st.mess_status !== 'ACTIVE' && st.hostel_status !== 'ACTIVE') {
                                servicesBadge = '<span class="badge bg-secondary">None</span>';
                            }

                            let cycleHtml = '—';
                            let feeStatusHtml = '—';
                            if (st.cycle) {
                                cycleHtml = `<span class="badge bg-light text-dark font-monospace">Cycle ${st.cycle.cycle_number}</span>
                                             <div class="small text-muted">${escapeHtml(st.cycle.due_date_formatted)}</div>`;
                                if (st.cycle.status === 'OVERDUE') {
                                    feeStatusHtml = '<span class="badge bg-danger">OVERDUE</span>';
                                } else if (st.cycle.status === 'DUE') {
                                    feeStatusHtml = '<span class="badge bg-warning text-dark">DUE TODAY</span>';
                                } else if (st.cycle.status === 'PENDING_VERIFICATION') {
                                    feeStatusHtml = '<span class="badge bg-warning-subtle text-warning-emphasis border">PENDING</span>';
                                } else {
                                    feeStatusHtml = '<span class="badge bg-success-subtle text-success border">UPCOMING</span>';
                                }
                            }

                            html += `<tr>
                                <td class="font-monospace fw-bold text-primary">${escapeHtml(st.student_code)}</td>
                                <td class="fw-semibold">${escapeHtml(st.name)}</td>
                                <td class="font-monospace text-muted small">${escapeHtml(st.mobile || '—')}</td>
                                <td class="small text-muted">${escapeHtml(st.joining_date_formatted)}</td>
                                <td>${servicesBadge}</td>
                                <td><span class="badge bg-body-tertiary text-dark font-monospace border">${escapeHtml(st.room_no || '—')}</span></td>
                                <td>${cycleHtml}</td>
                                <td>${feeStatusHtml}</td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="/admin/student-view/${st.id}" class="btn btn-outline-primary" title="View Profile">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="/admin/student-edit/${st.id}" class="btn btn-outline-secondary" title="Edit Student">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>`;
                        });
                        dirTableBody.innerHTML = html;
                    } else {
                        dirTableBody.innerHTML = `<tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-person-x fs-1 d-block mb-2"></i>No students matching the selected filter criteria.
                            </td>
                        </tr>`;
                    }
                }
            })
            .catch(() => {
                if (dirSpinner) dirSpinner.classList.add('d-none');
            });
    }

    if (dirSearchInput && dirTableBody) {
        dirSearchInput.addEventListener('input', debounce(fetchDirectoryStudents, 300));
        if (dirMessSelect) dirMessSelect.addEventListener('change', fetchDirectoryStudents);
        if (dirHostelSelect) dirHostelSelect.addEventListener('change', fetchDirectoryStudents);
        if (dirSortSelect) dirSortSelect.addEventListener('change', fetchDirectoryStudents);

        if (dirFilterForm) {
            dirFilterForm.addEventListener('submit', function (e) {
                e.preventDefault();
                fetchDirectoryStudents();
            });
        }

        if (clearDirBtn) {
            clearDirBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (dirSearchInput) dirSearchInput.value = '';
                if (dirMessSelect) dirMessSelect.value = '';
                if (dirHostelSelect) dirHostelSelect.value = '';
                if (dirSortSelect) dirSortSelect.value = 'date_asc';
                fetchDirectoryStudents();
            });
        }

        // Export Filtered Student Directory Records
        function exportCurrentDirectoryFilteredData() {
            const students = window.currentFilteredDirectoryStudents || [];
            if (students.length === 0) {
                alert('No student records found to export for the active filters.');
                return;
            }

            const headers = [
                '"Student Code"',
                '"Student Name"',
                '"Mobile Number"',
                '"Joining Date"',
                '"Mess Status"',
                '"Hostel Status"',
                '"Room Number"',
                '"Active Cycle"',
                '"Due Date"',
                '"Fee Status"'
            ];

            const rows = students.map(st => [
                `"${st.student_code || ''}"`,
                `"${(st.name || '').replace(/"/g, '""')}"`,
                `"${st.mobile || ''}"`,
                `"${st.joining_date_formatted || ''}"`,
                `"${st.mess_status || ''}"`,
                `"${st.hostel_status || ''}"`,
                `"${st.room_no || ''}"`,
                `"${st.cycle ? 'Cycle ' + st.cycle.cycle_number : '—'}"`,
                `"${st.cycle ? st.cycle.due_date_formatted : '—'}"`,
                `"${st.cycle ? st.cycle.status : '—'}"`
            ]);

            const dateStamp = new Date().toISOString().slice(0, 10);
            exportDataToCSV(`filtered_students_directory_${dateStamp}.csv`, headers, rows);
        }

        const exportDirBtn = document.getElementById('exportDirectoryFilteredBtn');
        if (exportDirBtn) exportDirBtn.addEventListener('click', exportCurrentDirectoryFilteredData);

        const exportDirToolbarBtn = document.getElementById('exportDirectoryToolbarBtn');
        if (exportDirToolbarBtn) exportDirToolbarBtn.addEventListener('click', exportCurrentDirectoryFilteredData);
    }
});

// -------------------------------------------------------------
// Instant 1-Click Payment Recording (No Modal, Direct AJAX Mark as Paid)
// -------------------------------------------------------------
window.markStudentAsPaidDirectly = function (studentId, amount, btnElement) {
    if (!studentId) return;

    const targetRow = btnElement ? btnElement.closest('tr') : null;
    if (targetRow) {
        targetRow.classList.add('row-paid-highlight');
    }

    if (btnElement) {
        btnElement.disabled = true;
        btnElement.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
    }

    const todayYmd = new Date().toISOString().split('T')[0];
    const params = new URLSearchParams();
    params.append('action', 'direct_student_payment');
    params.append('student_id', studentId);
    params.append('payment_method', 'CASH');
    params.append('amount', amount || '0');
    params.append('paid_date', todayYmd);
    params.append('notes', 'Direct 1-click mark paid by Admin');

    fetch('/api/admin-payments', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            if (typeof fetchFilteredStudents === 'function') {
                fetchFilteredStudents();
            }
        } else {
            alert('Error recording payment: ' + res.message);
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = '<i class="bi bi-cash-stack me-1"></i>Mark Paid';
            }
        }
    })
    .catch(() => {
        alert('Network connection error recording payment.');
        if (btnElement) {
            btnElement.disabled = false;
            btnElement.innerHTML = '<i class="bi bi-cash-stack me-1"></i>Mark Paid';
        }
    });
};

window.openDirectPaymentModal = window.markStudentAsPaidDirectly;
