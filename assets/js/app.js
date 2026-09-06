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
            let total = 0;
            selectedStudentsMap.forEach(item => total += item.fee);

            if (countEl) countEl.textContent = `${count} Student${count > 1 ? 's' : ''} Selected`;
            if (totalEl) totalEl.textContent = `Total: ₹${total.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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
                    if (kpiOverdue) kpiOverdue.textContent = res.summary.overdue_count;
                    if (kpiDueToday) kpiDueToday.textContent = res.summary.due_today_count;
                    if (kpiDue7Days) kpiDue7Days.textContent = res.summary.due_7_days_count;
                    if (kpiCollected) kpiCollected.textContent = res.summary.amount_collected_formatted;
                    if (matchCountBadge) matchCountBadge.textContent = res.total_matching + ' Students';

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
                            const markPaidBtn = (!isPaid)
                                ? `<button type="button" class="btn btn-outline-success btn-sm" title="Record Payment Directly (Mark as Paid)"
                                           onclick="openDirectPaymentModal('${st.id}', '${st.student_code}', '${safeName}', '${st.applicable_fee}', '${st.cycle_number}', '${safeCycleLabel}', '${st.due_date_formatted}')">
                                        <i class="bi bi-cash-stack me-1"></i>Mark Paid
                                    </button>`
                                : `<span class="badge bg-success text-white px-2 py-1"><i class="bi bi-check2-all me-1"></i>PAID</span>`;

                            let cycleSubNote = `<span class="d-block small text-primary fw-semibold mt-1">${escapeHtml(st.cycle_label)}</span>`;
                            if (st.last_paid_info) {
                                cycleSubNote += `<div class="small text-success fw-semibold mt-1"><i class="bi bi-check2-circle me-1"></i>${escapeHtml(st.last_paid_info)}</div>`;
                            }

                            const isChecked = selectedStudentsMap.has(Number(st.id));

                            html += `<tr>
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
                                <td class="font-monospace fw-bold text-primary">${st.student_code}</td>
                                <td class="student-name-cell fw-semibold">${escapeHtml(st.name)}</td>
                                <td class="font-monospace text-muted small">${st.mobile}</td>
                                <td class="small text-muted">${st.joining_date}</td>
                                <td>
                                    <span class="badge-cycle font-monospace">Cycle ${st.cycle_number}</span>
                                    ${cycleSubNote}
                                </td>
                                <td class="font-monospace ${isPaid ? 'text-success fw-semibold' : 'text-danger fw-bold'}">${st.due_date_formatted}</td>
                                <td class="fw-bold font-monospace text-success">${st.applicable_fee_formatted}</td>
                                <td>${statusBadge}</td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        ${markPaidBtn}
                                        <a href="/admin/student-view/${st.id}" class="btn btn-outline-secondary" title="View Profile">
                                            <i class="bi bi-person"></i>
                                        </a>
                                    </div>
                                </td>
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
                        studentFeeStatusTbody.innerHTML = `<tr><td colspan="10" class="text-center py-5 text-muted"><i class="bi bi-person-x fs-1 d-block mb-2"></i>No students matching the selected filter criteria.</td></tr>`;
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

            let total = 0;
            let listHtml = '';
            selectedStudentsMap.forEach(st => {
                total += st.fee;
                listHtml += `<tr>
                    <td class="font-monospace fw-bold text-primary">${st.code}</td>
                    <td class="fw-semibold">${escapeHtml(st.name)}</td>
                    <td><span class="badge bg-light text-dark font-monospace">Cycle ${st.cycle}</span></td>
                    <td class="text-end fw-bold font-monospace text-success">₹${st.fee.toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td>
                </tr>`;
            });

            if (modalTotal) modalTotal.textContent = `₹${total.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
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

        fetchFilteredStudents();
    }

    // -------------------------------------------------------------
    // 5. Single Student Direct Payment Form Submission
    // -------------------------------------------------------------
    const directPayForm = document.getElementById('directPaymentForm');
    if (directPayForm) {
        directPayForm.addEventListener('submit', function (e) {
            e.preventDefault();

            const studentId = document.getElementById('directPayStudentId').value;
            const method = document.getElementById('directPayMethod').value;
            const amount = document.getElementById('directPayAmount').value;
            const notes = document.getElementById('directPayNotes').value;
            const paidDate = document.getElementById('directPayDate').value;
            const confirmBtn = document.getElementById('confirmDirectPayBtn');

            if (!studentId || !amount || parseFloat(amount) <= 0) {
                alert('Please provide a valid payment amount.');
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Recording...';

            const params = new URLSearchParams();
            params.append('action', 'direct_student_payment');
            params.append('student_id', studentId);
            params.append('payment_method', method);
            params.append('amount', amount);
            params.append('notes', notes);
            params.append('paid_date', paidDate);

            fetch('/api/admin-payments', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
            .then(r => r.json())
            .then(res => {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Confirm & Mark Completed';

                const modalEl = document.getElementById('directPaymentModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();

                if (res.success) {
                    alert(res.message);
                    if (typeof fetchFilteredStudents === 'function') {
                        fetchFilteredStudents();
                    } else {
                        window.location.reload();
                    }
                } else {
                    alert('Error: ' + res.message);
                }
            })
            .catch(() => {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Confirm & Mark Completed';
                alert('Connection error recording payment.');
            });
        });
    }
});

// Modal Setup for Direct Offline Payment
window.openDirectPaymentModal = function (studentId, studentCode, studentName, amount, cycleNumber, cycleLabel, dueDate) {
    const sId = document.getElementById('directPayStudentId');
    const sName = document.getElementById('directPayStudentName');
    const sCode = document.getElementById('directPayStudentCode');
    const sAmount = document.getElementById('directPayAmount');
    const sCycle = document.getElementById('directPayCycleLabel');
    const sDue = document.getElementById('directPayDueDate');
    const sNotes = document.getElementById('directPayNotes');

    if (sId) sId.value = studentId;
    if (sName) sName.textContent = studentName;
    if (sCode) sCode.textContent = studentCode;
    if (sAmount) sAmount.value = amount;
    if (sCycle) sCycle.textContent = `Cycle ${cycleNumber} (${cycleLabel})`;
    if (sDue) sDue.textContent = dueDate;
    if (sNotes) sNotes.value = '';

    const modalEl = document.getElementById('directPaymentModal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
};
