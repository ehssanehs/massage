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
    // it pre-fills every empty money/price input in the same form. Fields
    // are intentionally left editable: an already-filled value (e.g. an
    // edited session, a discount amount) is never overwritten.
    // ------------------------------------------------------------------
    document.querySelectorAll('form select[name="service_id"]').forEach((serviceSelect) => {
        if (!serviceSelect.options.length) return;
        serviceSelect.addEventListener('change', () => {
            const opt = serviceSelect.selectedOptions && serviceSelect.selectedOptions[0];
            const price = opt && opt.getAttribute('data-price');
            if (price === null || price === '' || price === undefined) return; // non-service select or no price
            const form = serviceSelect.closest('form');
            if (!form) return;
            form.querySelectorAll('input[data-autofill]').forEach((input) => {
                if (!input.value || parseFloat(input.value) <= 0) {
                    input.value = price;
                }
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
});
