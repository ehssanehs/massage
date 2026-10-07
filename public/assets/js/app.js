document.addEventListener('DOMContentLoaded', () => {
    const root = document.documentElement;
    const toggle = document.getElementById('themeToggle');

    // ---------------------------------------------------------------
    // Jalali DatePicker — local, dependency-free picker (no CDN).
    // Converts stray input[type=date] fields and enhances input[data-jdp].
    // ---------------------------------------------------------------
    if (window.JalaliPicker) {
        try {
            JalaliPicker.init();
        } catch (e) {
            console.warn('JalaliPicker init failed', e);
        }
    }
    // All server-rendered date fields are plain Jalali text inputs, so manual
    // entry still works when JavaScript/the picker is unavailable.

    // Apply saved theme immediately
    const saved = localStorage.getItem('theme');
    if (saved) {
        root.setAttribute('data-bs-theme', saved);
        document.cookie = 'theme=' + saved + '; path=/; max-age=31536000; SameSite=Lax';
    }

    // Update icon based on current theme
    function updateIcon() {
        if (!toggle) return;
        const current = root.getAttribute('data-bs-theme');
        const icon = toggle.querySelector('i');
        if (icon) {
            if (current === 'dark') {
                icon.className = 'bi bi-sun';
            } else {
                icon.className = 'bi bi-moon-stars';
            }
        }
    }

    // Initialize icon
    updateIcon();

    // Theme toggle click handler
    if (toggle) {
        toggle.addEventListener('click', (e) => {
            e.preventDefault();
            const current = root.getAttribute('data-bs-theme') || 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-bs-theme', next);
            localStorage.setItem('theme', next);
            document.cookie = 'theme=' + next + '; path=/; max-age=31536000; SameSite=Lax';
            updateIcon();
        });
    }

    // Sidebar toggle for mobile
    const sidebarToggle = document.querySelector('[data-toggle-sidebar]');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) sidebar.classList.toggle('open');
        });
    }

    // ------------------------------------------------------------------
    // Auto-fill price/amount from the selected massage service.
    // When a "خدمت" select that carries per-option data-price is changed,
    // the session's price and final_amount fields are filled from the
    // service's recorded default price. The filled fields stay normal,
    // editable inputs — a discount already entered on the form is kept and
    // the final amount is recalculated as (price - discount). Editing the
    // price or discount afterwards refreshes the final amount from the
    // current price.
    // ------------------------------------------------------------------
    function readDiscount(form) {
        const input = form.querySelector('input[name="discount"]');
        return Math.max(0, parseFloat(input && input.value) || 0);
    }

    function recalcFinalAmount(form) {
        const priceInput = form.querySelector('input[name="price"][data-autofill]');
        const finalInput = form.querySelector('input[name="final_amount"][data-autofill]');
        if (!priceInput || !finalInput) return;
        const price = parseFloat(priceInput.value) || 0;
        finalInput.value = Math.max(0, price - readDiscount(form));
    }

    function applyServicePrice(form) {
        const serviceSelect = form.querySelector('select[name="service_id"]');
        const option = serviceSelect && serviceSelect.selectedOptions && serviceSelect.selectedOptions[0];
        const rawPrice = option && option.getAttribute('data-price');
        if (rawPrice === null || rawPrice === '' || rawPrice === undefined) return; // no price on this service
        const price = parseFloat(rawPrice);
        if (Number.isNaN(price)) return;

        const priceInput = form.querySelector('input[name="price"][data-autofill]');
        if (priceInput) priceInput.value = price;
        recalcFinalAmount(form);
    }

    document.querySelectorAll('form select[name="service_id"]').forEach((serviceSelect) => {
        serviceSelect.addEventListener('change', () => {
            const form = serviceSelect.closest('form');
            if (form) applyServicePrice(form);
        });
    });

    // Keep the final amount consistent when the price or discount is edited on
    // the same form. The fields remain fully editable by the user afterwards.
    document.querySelectorAll('form input[name="price"][data-autofill], form input[name="discount"]').forEach((moneyInput) => {
        moneyInput.addEventListener('input', () => {
            const form = moneyInput.closest('form');
            if (form) recalcFinalAmount(form);
        });
    });

    // ------------------------------------------------------------------
    // Customer credit helper on session/package forms.
    // The customer select carries data-balance on each option (injected
    // server-side). A hint under the credit_used field shows the selected
    // customer's available balance and updates when the customer changes;
    // the field itself stays fully editable. The server clamps the spend
    // to the real balance on save, so the hint is guidance, not security.
    // ------------------------------------------------------------------
    function creditBalanceFor(form) {
        const sel = form.querySelector('select[name="customer_id"]');
        const opt = sel && sel.selectedOptions && sel.selectedOptions[0];
        const raw = opt && opt.getAttribute('data-balance');
        return raw === null || raw === undefined ? 0 : (parseFloat(raw) || 0);
    }
    function refreshCreditHint(form) {
        const creditInput = form.querySelector('input[data-credit]');
        if (!creditInput) return;
        let hint = creditInput.parentElement && creditInput.parentElement.querySelector('.credit-hint');
        if (!hint) {
            hint = document.createElement('div');
            hint.className = 'form-text credit-hint';
            (creditInput.closest('div') || creditInput.parentElement).appendChild(hint);
        }
        hint.textContent = 'اعتبار موجود مشتری: ' + new Intl.NumberFormat('fa-IR').format(creditBalanceFor(form)) + ' ریال';
    }
    document.querySelectorAll('input[data-credit]').forEach((creditInput) => {
        const form = creditInput.closest('form');
        if (form) {
            refreshCreditHint(form);
            const sel = form.querySelector('select[name="customer_id"]');
            if (sel) sel.addEventListener('change', () => {
                refreshCreditHint(form);
                const typed = parseFloat(creditInput.value) || 0;
                if (typed > creditBalanceFor(form)) creditInput.value = creditBalanceFor(form);
            });
            creditInput.addEventListener('input', () => {
                const typed = parseFloat(creditInput.value) || 0;
                if (typed < 0) creditInput.value = 0;
            });
        }
    });

    // Sort only the rendered rows of unpaginated tables. Server-sorted
    // module lists own their order and are explicitly opted out.
    const faCollator = new Intl.Collator('fa-IR', { numeric: true, sensitivity: 'base' });
    const latinDigits = value => String(value).replace(/[۰-۹٠-٩]/g, digit => {
        const code = digit.charCodeAt(0);
        return String(code - (code >= 0x6F0 ? 0x6F0 : 0x660));
    });
    const blankSortValue = value => !value || /^[\s\-–—]+$/.test(value);
    const datePattern = /(?:^|\D)(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/;
    function sortValue(cell, type) {
        const text = latinDigits(cell.textContent).replace(/[\u200c\u200e\u200f]/g, '').trim();
        if (blankSortValue(text)) return null;
        if (type === 'date') {
            const match = text.match(datePattern);
            if (match) return [match[1], match[2], match[3], match[4] || 0, match[5] || 0, match[6] || 0]
                .map(part => String(part).padStart(4, '0')).join('');
        }
        if (type === 'number') {
            const number = Number(text.replace(/[٬,\s\u00a0]/g, '').replace(/٫/g, '.').replace(/[^\d.+\-]/g, ''));
            if (Number.isFinite(number)) return number;
        }
        return text;
    }
    document.querySelectorAll('table').forEach(table => {
        if (table.hasAttribute('data-server-sort')) return;
        const header = table.tHead ? table.tHead.rows[0] : table.tBodies[0] && table.tBodies[0].rows[0];
        if (!header || !Array.from(header.cells).every(cell => cell.tagName === 'TH')) return;
        if (!table.caption && table.createCaption) {
            const caption = table.createCaption();
            caption.className = 'caption-top small text-muted';
            caption.textContent = 'مرتب‌سازی فقط ردیف‌های نمایش‌داده‌شده در این جدول را تغییر می‌دهد.';
        }
        Array.from(header.cells).forEach((th, column) => {
            const label = th.textContent.trim();
            if (!label || /^(عملیات|انتخاب|اقدامات)$/.test(label) || th.querySelector('input, select, button, a')) return;
            const button = document.createElement('button');
            button.setAttribute('type', 'button');
            button.setAttribute('class', 'btn btn-link p-0 text-reset text-decoration-none fw-semibold');
            button.setAttribute('aria-label', 'مرتب‌سازی بر اساس ' + label);
            th.textContent = '';
            button.textContent = label + ' ↕';
            th.appendChild(button);
            th.setAttribute('aria-sort', 'none');
            button.addEventListener('click', () => {
                const direction = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
                const order = direction === 'ascending' ? 1 : -1;
                Array.from(header.cells).forEach(cell => {
                    if (cell.getAttribute('aria-sort') && cell !== th) {
                        cell.setAttribute('aria-sort', 'none');
                        const otherButton = cell.querySelector('button');
                        if (otherButton) otherButton.textContent = otherButton.textContent.replace(/ [▲▼]$/, ' ↕');
                    }
                });
                th.setAttribute('aria-sort', direction);
                button.textContent = label + (order === 1 ? ' ▲' : ' ▼');
                Array.from(table.tBodies).forEach(body => {
                    const rows = Array.from(body.rows).filter(row => row !== header);
                    const values = rows.map(row => latinDigits(row.cells[column] ? row.cells[column].textContent : '').trim())
                        .filter(value => !blankSortValue(value));
                    const type = values.length && values.every(value => datePattern.test(value)) ? 'date'
                        : values.length && values.every(value => /^\s*[+-]?[\d\s٬,٫.]+\s*(?:ریال|تومان)?\s*$/.test(value)) ? 'number' : 'text';
                    rows.map((row, index) => ({ row, index, value: row.cells[column] ? sortValue(row.cells[column], type) : null }))
                        .sort((a, b) => {
                            if (a.value === null || b.value === null) return a.value === null ? (b.value === null ? a.index - b.index : 1) : -1;
                            const comparison = type === 'number' ? a.value - b.value : faCollator.compare(a.value, b.value);
                            return order * comparison || a.index - b.index;
                        }).forEach(({ row }) => body.appendChild(row));
                });
            });
        });
    });

    // Revenue chart
    const canvas = document.getElementById('revenueChart');
    if (canvas && window.Chart && canvas.dataset.url) {
        fetch(canvas.dataset.url)
            .then(r => r.json())
            .then(d => {
                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: d.labels,
                        datasets: [{
                            label: 'درآمد',
                            data: d.values,
                            borderColor: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim(),
                            backgroundColor: 'rgba(124,58,237,.15)',
                            fill: true,
                            tension: 0.35
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { ticks: { callback: v => new Intl.NumberFormat('fa-IR').format(v) } }
                        }
                    }
                });
            })
            .catch(() => {});
    }

    // Auto-hide toasts
    setTimeout(() => {
        document.querySelectorAll('.toast').forEach(t => {
            if (window.bootstrap) bootstrap.Toast.getOrCreateInstance(t).hide();
        });
    }, 3500);

    // ---------------------------------------------------------------
    // Followup center: re-followup days input + multi-select + bulk bar
    // ---------------------------------------------------------------
    const bulkBar = document.getElementById('bulkBar');
    const selCount = document.getElementById('selCount');
    const checkAll = document.getElementById('fuCheckAll');

    function fuUpdateBulkBar() {
        const boxes = document.querySelectorAll('.fu-row-check');
        const checked = document.querySelectorAll('.fu-row-check:checked');
        if (!bulkBar) return;
        bulkBar.classList.toggle('d-none', checked.length === 0);
        if (selCount) selCount.textContent = new Intl.NumberFormat('fa-IR').format(checked.length);
        if (checkAll && boxes.length) {
            checkAll.checked = checked.length === boxes.length;
            checkAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
        }
    }

    window.fuClearSelection = function () {
        document.querySelectorAll('.fu-row-check').forEach(cb => { cb.checked = false; });
        if (checkAll) checkAll.checked = false;
        fuUpdateBulkBar();
    };

    document.querySelectorAll('.fu-row-check').forEach(cb => {
        cb.addEventListener('change', fuUpdateBulkBar);
    });
    if (checkAll) {
        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.fu-row-check').forEach(cb => { cb.checked = checkAll.checked; });
            fuUpdateBulkBar();
        });
    }
    fuUpdateBulkBar();

    // Show the "re-followup after N days" input only for non-booked statuses
    document.querySelectorAll('.followup-action-form').forEach(form => {
        const select = form.querySelector('.fu-status-select');
        const days = form.querySelector('.fu-refollow-days');
        if (!select || !days) return;
        const sync = () => {
            days.classList.toggle('d-none', select.value === 'booked');
            if (select.value === 'booked') days.value = '';
        };
        select.addEventListener('change', sync);
        sync();
    });
});
