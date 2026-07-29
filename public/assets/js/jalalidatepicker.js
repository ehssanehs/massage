/*!
 * Jalali (Persian / Shamsi) date picker — dependency-free and fully offline.
 * Binds automatically to every <input data-jdp> (and any remaining
 * <input type="date">, which is converted to a text input first).
 *
 * The calendar math is a byte-for-byte port of App\Support\Jalali so the
 * values picked here always round-trip exactly through the server-side
 * Jalali <-> Gregorian conversion used for storage in MySQL.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Date math — identical algorithm to app/Support/Jalali.php (Khayyam
    // 33-year cycle), so client and server never disagree.
    // ------------------------------------------------------------------
    const intdiv = (a, b) => Math.trunc(a / b);

    function g2j(gy, gm, gd) {
        const gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        const gy2 = gm > 2 ? gy + 1 : gy;
        let days = 355666 + 365 * gy + intdiv(gy2 + 3, 4) - intdiv(gy2 + 99, 100)
            + intdiv(gy2 + 399, 400) + gd + gdm[gm - 1];
        let jy = -1595 + 33 * intdiv(days, 12053);
        days %= 12053;
        jy += 4 * intdiv(days, 1461);
        days %= 1461;
        if (days > 365) { jy += intdiv(days - 1, 365); days = (days - 1) % 365; }
        let jm, jd;
        if (days < 186) { jm = 1 + intdiv(days, 31); jd = 1 + (days % 31); }
        else { jm = 7 + intdiv(days - 186, 30); jd = 1 + ((days - 186) % 30); }
        return [jy, jm, jd];
    }

    function jDayNumber(jy, jm, jd) {
        jy += 1595;
        return -355668 + 365 * jy + intdiv(jy, 33) * 8 + intdiv((jy % 33) + 3, 4)
            + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
    }

    function j2g(jy, jm, jd) {
        let days = jDayNumber(jy, jm, jd);
        let gy = 400 * intdiv(days, 146097);
        days %= 146097;
        if (days > 36524) {
            gy += 100 * intdiv(--days, 36524);
            days %= 36524;
            if (days >= 365) days++;
        }
        gy += 4 * intdiv(days, 1461);
        days %= 1461;
        if (days > 365) { gy += intdiv(days - 1, 365); days = (days - 1) % 365; }
        let gd = days + 1;
        const leap = (gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0);
        const sal = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        let gm = 1;
        for (; gm <= 12 && gd > sal[gm]; gm++) gd -= sal[gm];
        return [gy, gm, gd];
    }

    function monthLength(jy, jm) {
        if (jm <= 6) return 31;
        if (jm <= 11) return 30;
        return jDayNumber(jy + 1, 1, 1) - jDayNumber(jy, 1, 1) === 366 ? 30 : 29;
    }

    // 0 = شنبه (Saturday) … 6 = جمعه (Friday)
    function weekday(jy, jm, jd) {
        const g = j2g(jy, jm, jd);
        return (new Date(g[0], g[1] - 1, g[2]).getDay() + 1) % 7;
    }

    // ------------------------------------------------------------------
    // Digits & formatting helpers
    // ------------------------------------------------------------------
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const AR = '٠١٢٣٤٥٦٧٨٩';
    const faDigits = (s) => String(s).replace(/\d/g, (d) => FA[+d]);
    const enDigits = (s) => String(s)
        .replace(/[۰-۹]/g, (d) => String(FA.indexOf(d)))
        .replace(/[٠-٩]/g, (d) => String(AR.indexOf(d)));
    const pad2 = (n) => (n < 10 ? '0' : '') + n;

    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    function todayJalali() {
        const t = new Date();
        return g2j(t.getFullYear(), t.getMonth() + 1, t.getDate());
    }

    /** Parse "1404/05/09" style input (any digit system, / or -). */
    function parseInput(value) {
        const s = enDigits(String(value || '').trim());
        const m = s.match(/^(\d{3,4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/);
        if (!m) return null;
        const jy = +m[1], jm = +m[2], jd = +m[3];
        if (jy < 1200 || jy > 1600 || jm < 1 || jm > 12 || jd < 1 || jd > 31) return null;
        return [jy, jm, jd];
    }

    // ------------------------------------------------------------------
    // Picker popup
    // ------------------------------------------------------------------
    let popup = null;
    let currentInput = null;
    let viewJy = 1400;
    let viewJm = 1;

    function buildPopup() {
        popup = document.createElement('div');
        popup.className = 'jdp-popup';
        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-label', 'تقویم شمسی');
        document.body.appendChild(popup);
    }

    function render() {
        if (!popup) buildPopup();
        const today = todayJalali();
        const selected = currentInput ? parseInput(currentInput.value) : null;
        let html = '<div class="jdp-head">'
            + '<button type="button" class="jdp-nav" data-a="py" title="سال قبل">&#171;</button>'
            + '<button type="button" class="jdp-nav" data-a="pm" title="ماه قبل">&#8249;</button>'
            + '<div class="jdp-title">' + MONTHS[viewJm - 1] + ' ' + faDigits(viewJy) + '</div>'
            + '<button type="button" class="jdp-nav" data-a="nm" title="ماه بعد">&#8250;</button>'
            + '<button type="button" class="jdp-nav" data-a="ny" title="سال بعد">&#187;</button>'
            + '</div><div class="jdp-grid">';
        for (let i = 0; i < WEEKDAYS.length; i++) {
            html += '<span class="jdp-wd">' + WEEKDAYS[i] + '</span>';
        }
        const len = monthLength(viewJy, viewJm);
        const offset = weekday(viewJy, viewJm, 1);
        for (let i = 0; i < offset; i++) html += '<span class="jdp-day jdp-empty"></span>';
        for (let d = 1; d <= len; d++) {
            const cls = ['jdp-day'];
            if (today[0] === viewJy && today[1] === viewJm && today[2] === d) cls.push('today');
            if (selected && selected[0] === viewJy && selected[1] === viewJm && selected[2] === d) cls.push('selected');
            html += '<button type="button" class="' + cls.join(' ') + '" data-d="' + d + '">' + faDigits(d) + '</button>';
        }
        html += '</div><div class="jdp-foot">'
            + '<button type="button" class="jdp-btn" data-a="today">امروز</button>'
            + '<button type="button" class="jdp-btn jdp-danger" data-a="clear">پاک‌سازی</button>'
            + '<button type="button" class="jdp-btn" data-a="close">بستن</button>'
            + '</div>';
        popup.innerHTML = html;
    }

    function place() {
        if (!popup || !currentInput) return;
        const r = currentInput.getBoundingClientRect();
        const width = Math.max(292, r.width);
        popup.style.width = width + 'px';
        let left = r.left + window.scrollX + r.width - width; // align right edges (RTL)
        if (left < 8) left = 8;
        if (left + width > window.scrollX + document.documentElement.clientWidth - 8) {
            left = Math.max(8, window.scrollX + document.documentElement.clientWidth - 8 - width);
        }
        let top = r.bottom + window.scrollY + 6;
        const popH = popup.offsetHeight || 340;
        const spaceBelow = window.innerHeight - r.bottom;
        if (spaceBelow < popH + 12 && r.top > popH + 12) top = r.top + window.scrollY - popH - 6;
        popup.style.left = left + 'px';
        popup.style.top = top + 'px';
    }

    function open(input) {
        if (!input) return;
        currentInput = input;
        const sel = parseInput(input.value) || todayJalali();
        viewJy = sel[0];
        viewJm = sel[1];
        render();
        popup.classList.add('open');
        place();
    }

    function close() {
        if (popup) popup.classList.remove('open');
        currentInput = null;
    }

    function pick(day) {
        if (!currentInput) return;
        const input = currentInput;
        input.value = faDigits(viewJy + '/' + pad2(viewJm) + '/' + pad2(day));
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    }

    function clearValue() {
        if (currentInput) {
            currentInput.value = '';
            currentInput.dispatchEvent(new Event('input', { bubbles: true }));
            currentInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        close();
    }

    // ------------------------------------------------------------------
    // Event wiring (delegation works for dynamically added inputs too)
    // ------------------------------------------------------------------
    document.addEventListener('focusin', (e) => {
        const t = e.target;
        if (t && t.matches && t.matches('input[data-jdp]')) open(t);
    });

    document.addEventListener('click', (e) => {
        const target = e.target;
        const input = target && target.closest ? target.closest('input[data-jdp]') : null;
        if (input) { open(input); return; }
        if (!popup || !popup.classList.contains('open')) return;

        const btn = target && target.closest ? target.closest('.jdp-day[data-d], [data-a]') : null;
        if (btn && popup.contains(btn)) {
            e.preventDefault();
            if (btn.dataset.d !== undefined) { pick(parseInt(btn.dataset.d, 10)); return; }
            switch (btn.dataset.a) {
                case 'pm': viewJm--; if (viewJm < 1) { viewJm = 12; viewJy--; } render(); break;
                case 'nm': viewJm++; if (viewJm > 12) { viewJm = 1; viewJy++; } render(); break;
                case 'py': viewJy--; render(); break;
                case 'ny': viewJy++; render(); break;
                case 'today': { const t = todayJalali(); viewJy = t[0]; viewJm = t[1]; pick(t[2]); break; }
                case 'clear': clearValue(); break;
                case 'close': close(); break;
                default: break;
            }
            return;
        }
        if (popup && !popup.contains(target)) close();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') close();
    });

    window.addEventListener('resize', () => {
        if (popup && popup.classList.contains('open')) place();
    });

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------
    window.JalaliPicker = {
        init() {
            // Any stray native date input becomes a jalali text input.
            document.querySelectorAll('input[type="date"]').forEach((inp) => {
                inp.type = 'text';
                inp.setAttribute('data-jdp', '');
                if (!inp.getAttribute('placeholder')) inp.setAttribute('placeholder', '1404/01/01');
                inp.setAttribute('autocomplete', 'off');
                inp.classList.add('jalali-input');
            });
            document.querySelectorAll('input[data-jdp]').forEach((inp) => {
                inp.classList.add('jalali-input');
                if (!inp.getAttribute('autocomplete')) inp.setAttribute('autocomplete', 'off');
            });
        },
        open,
        close,
        g2j,
        j2g
    };
})();
