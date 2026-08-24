/**
 * LOKA - Application JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    initSidebar();
    initDataTables();
    initDatePickers();
    initToasts();
    initConfirmDialogs();
    initFormValidation();
    initDropdowns();
    initNotificationPolling();
    initPreventDoubleSubmit();
});

/**
 * Sidebar Toggle
 */
function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('main-content');
    const toggleBtn = document.getElementById('sidebarToggle');

    // Create overlay for mobile
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    overlay.id = 'sidebarOverlay';
    document.body.appendChild(overlay);

    // Helper function to check if mobile
    const isMobileView = () => window.innerWidth < 992;

    if (toggleBtn && sidebar && mainContent) {
        const toggleSidebar = () => {
            if (isMobileView()) {
                // Mobile: use show/hide classes with overlay
                const isOpen = sidebar.classList.contains('show');
                if (isOpen) {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                } else {
                    sidebar.classList.add('show');
                    overlay.classList.add('show');
                    document.body.classList.add('sidebar-open');
                }
            } else {
                // Desktop: use collapsed/expanded classes
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                // Save state for desktop only
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            }
        };

        toggleBtn.addEventListener('click', toggleSidebar);

        // Close sidebar on overlay click (mobile)
        overlay.addEventListener('click', () => {
            if (sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
                document.body.classList.remove('sidebar-open');
            }
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
                document.body.classList.remove('sidebar-open');
            }
        });

        // Restore desktop state only
        if (!isMobileView() && localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }

        // Mobile: close sidebar on link click
        sidebar.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (isMobileView()) {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                }
            });
        });
    }

    // Handle window resize
    window.addEventListener('resize', () => {
        const nowMobile = window.innerWidth < 992;
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        // Clean up mobile styles when switching to desktop
        if (!nowMobile && sidebarOverlay) {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.classList.remove('sidebar-open');
        }
    });
}

/**
 * Initialize DataTables
 * Auto-enhances every table in the app with sorting + search.
 * Opt out with class "no-datatable" on the <table>.
 */
function initDataTables() {
    if (typeof $ === 'undefined' || !$.fn.DataTable) return;

    // Human-readable date formats ("Aug 24, 2026 3:04 PM") sort chronologically
    if ($.fn.dataTable.moment) {
        $.fn.dataTable.moment('MMM D, YYYY h:mm A');
        $.fn.dataTable.moment('MMM D, YYYY');
    }

    document.querySelectorAll('.table:not(.no-datatable)').forEach(table => {
        if ($.fn.DataTable.isDataTable(table)) return;
        // Skip tables inside modals, tables without headers, and empty tables
        if (table.closest('.modal')) return;
        if (!table.querySelector('thead')) return;
        if (!table.querySelectorAll('tbody tr').length) return;

        $(table).DataTable({
            pageLength: 15,
            lengthMenu: [[10, 15, 25, 50, -1], [10, 15, 25, 50, "All"]],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "No entries found",
                emptyTable: "No data available",
                paginate: {
                    first: '<i class="bi bi-chevron-double-left"></i>',
                    previous: '<i class="bi bi-chevron-left"></i>',
                    next: '<i class="bi bi-chevron-right"></i>',
                    last: '<i class="bi bi-chevron-double-right"></i>'
                }
            },
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                 '<"row"<"col-sm-12"tr>>' +
                 '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
            // Neutral default: keep the server-side order, which is newest-first
            order: [],
            responsive: true
        });
    });
}

/**
 * Initialize Date Pickers
 */
function initDatePickers() {
    if (typeof flatpickr === 'undefined') {
        console.warn('Flatpickr not loaded');
        return;
    }
    
    // Date only
    document.querySelectorAll('.datepicker').forEach(el => {
        // Skip if already initialized
        if (el._flatpickr) return;
        flatpickr(el, {
            dateFormat: "Y-m-d",
            allowInput: true
        });
    });
    
    // DateTime
    document.querySelectorAll('.datetimepicker').forEach(el => {
        // Skip if already initialized
        if (el._flatpickr) return;
        flatpickr(el, {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            time_24hr: true,
            allowInput: true,
            minDate: "today",
            minuteIncrement: 15
        });
    });
    
    // Date range
    document.querySelectorAll('.daterange').forEach(el => {
        // Skip if already initialized
        if (el._flatpickr) return;
        flatpickr(el, {
            mode: "range",
            dateFormat: "Y-m-d",
            allowInput: true
        });
    });
}

/**
 * Initialize Toast Notifications
 */
function initToasts() {
    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.click();
            }
        }, 5000);
    });
}

/**
 * Show toast notification
 */
function showToast(message, type = 'info') {
    const toastContainer = document.getElementById('toast-container') || createToastContainer();
    
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    
    toastContainer.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container position-fixed top-0 end-0 p-3';
    container.style.zIndex = '1100';
    document.body.appendChild(container);
    return container;
}

/**
 * Initialize Confirm Dialogs
 */
function initConfirmDialogs() {
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function(e) {
            const message = this.getAttribute('data-confirm') || 'Are you sure?';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });
}

/**
 * Initialize Form Validation
 */
function initFormValidation() {
    document.querySelectorAll('.needs-validation').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
}

/**
 * Initialize Dropdowns
 */
function initDropdowns() {
    // Ensure dropdown links don't navigate
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(trigger => {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
        });
    });
}

/**
 * AJAX Helper
 */
async function fetchApi(url, options = {}) {
    const defaults = {
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    
    const config = { ...defaults, ...options };
    
    try {
        const response = await fetch(url, config);
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || 'Request failed');
        }
        
        return data;
    } catch (error) {
        showToast(error.message, 'danger');
        throw error;
    }
}

/**
 * Format currency
 */
function formatCurrency(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: currency
    }).format(amount);
}

/**
 * Format date
 */
function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

/**
 * Format datetime
 */
function formatDateTime(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Copy to clipboard
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('Copied to clipboard!', 'success');
    }).catch(() => {
        showToast('Failed to copy', 'danger');
    });
}

/**
 * Print element
 */
function printElement(elementId) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Print</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { padding: 20px; }
                @media print { body { padding: 0; } }
            </style>
        </head>
        <body>${element.innerHTML}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 250);
}

/**
 * Export table to CSV
 */
function exportTableToCSV(tableId, filename = 'export.csv') {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        cols.forEach(col => {
            let text = col.innerText.replace(/"/g, '""');
            rowData.push(`"${text}"`);
        });
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
}

/**
 * Notification Polling
 */
function initNotificationPolling() {
    const pollingInterval = 30000;
    let lastCount = null;

    function updateNotificationCount() {
        fetch(window.location.origin + window.location.pathname + '?page=notifications&action=refresh-ajax&view=inbox', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.unread !== undefined) {
                const badge = document.querySelector('.badge.bg-danger');
                if (badge) {
                    if (data.unread > 0) {
                        badge.textContent = data.unread > 9 ? '9+' : data.unread;
                        badge.classList.remove('d-none');
                        if (lastCount !== null && data.unread > lastCount) {
                            showToast('You have new notifications', 'info');
                        }
                    } else {
                        badge.classList.add('d-none');
                    }
                    lastCount = data.unread;
                }
                
                const dropdownItems = document.querySelectorAll('.notification-dropdown .dropdown-item:not(.text-center):not([role="button"])');
                if (dropdownItems.length > 0 && data.html) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(data.html, 'text/html');
                    const newItems = doc.querySelectorAll('a.dropdown-item');
                    const dropdownList = document.querySelector('.notification-dropdown');
                    const divider = dropdownList.querySelector('hr.dropdown-divider');
                    const viewAll = dropdownList.querySelector('a.text-center');
                    
                    dropdownItems.forEach(item => item.remove());
                    
                    newItems.forEach(newItem => {
                        dropdownList.insertBefore(newItem, divider);
                    });
                }
            }
        })
        .catch(error => console.error('Notification poll error:', error));
    }

    setInterval(updateNotificationCount, pollingInterval);
}

/**
 * Prevent Double Form Submission
 * Disables submit buttons and changes text to "Processing..." to prevent multiple clicks
 * while synchronous emails are sending.
 */
function initPreventDoubleSubmit() {
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            // Check if form has HTML5 validation errors
            if (!form.checkValidity()) {
                return;
            }
            
            // Find the submit button(s)
            const submitBtns = form.querySelectorAll('button[type="submit"]');
            
            submitBtns.forEach(btn => {
                if (!btn.disabled) {
                    // Retain width to prevent layout shift
                    const width = btn.offsetWidth;
                    if (width > 0) {
                        btn.style.width = width + 'px';
                    }
                    
                    // Create hidden input to preserve the button's name/value in POST data
                    if (btn.name) {
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = btn.name;
                        hiddenInput.value = btn.value;
                        form.appendChild(hiddenInput);
                    }
                    
                    // Small delay to allow the form to actually submit before disabling
                    setTimeout(() => {
                        btn.disabled = true;
                        
                        // Change text to processing, keeping icons if possible
                        if (btn.innerHTML.includes('<i')) {
                            // If it has an icon, just swap the text
                            btn.innerHTML = '<i class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></i>Processing...';
                        } else {
                            btn.innerHTML = 'Processing...';
                        }
                    }, 50);

                    // Full-screen overlay so the wait state is obvious and
                    // users don't think the app hung and re-submit
                    if (!document.getElementById('submittingOverlay')) {
                        const overlay = document.createElement('div');
                        overlay.id = 'submittingOverlay';
                        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.45);z-index:2000;display:flex;align-items:center;justify-content:center;';
                        overlay.innerHTML =
                            '<div style="background:#fff;border-radius:12px;padding:1.5rem 2.5rem;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.3)">' +
                            '<div class="spinner-border text-primary mb-3" role="status" style="width:2.5rem;height:2.5rem"></div>' +
                            '<div style="font-weight:600;font-size:1.05rem">Submitting&hellip; please wait</div>' +
                            '<div style="color:#6c757d;font-size:0.85rem;margin-top:0.25rem">Do not close or reload this page</div>' +
                            '</div>';
                        document.body.appendChild(overlay);

                        // Safety: never leave the user permanently stuck if the
                        // request takes abnormally long or fails client-side
                        setTimeout(() => { const o = document.getElementById('submittingOverlay'); if (o) o.remove(); }, 60000);
                    }
                }
            });
        });
    });
}
