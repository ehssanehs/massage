/* Local 24-hour time entry. Grammar matches App\Support\ClockTime; no CDN required. */
(function () {
    'use strict';

    const selector = 'input[data-time-input]';
    const fa = value => String(value).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[+d]);
    const en = value => String(value ?? '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const pad = value => String(value).padStart(2, '0');
    const clean = value => en(value).replace(/[\u200e\u200f\u061c]/g, '').replace(/[：∶.٫]/g, ':').replace(/[\s\u0085]*:[\s\u0085]*/g, ':').replace(/^[\s\u0085]+|[\s\u0085]+$/g, '');
    const editable = input => input && input.isConnected && !input.disabled && !input.readOnly;
    const isInput = element => element?.matches?.(selector);
    const prepared = new WeakSet();
    const touched = new WeakSet();
    let popup, active, opener, draftSeconds = 0;

    function normalize(value) {
        value = clean(value);
        let hour, minute = 0, second = 0;
        if (/^\d{1,2}$/.test(value)) hour = +value;
        else if (/^\d{3,4}$/.test(value)) {
            hour = +value.slice(0, -2);
            minute = +value.slice(-2);
        } else {
            const match = /^(\d{1,2}):(\d{1,2})(?::(\d{2}))?$/.exec(value);
            if (!match) return null;
            [hour, minute, second] = [+match[1], +match[2], +(match[3] || 0)];
        }
        return hour < 24 && minute < 60 && second < 60 ? [hour, minute, second].map(pad).join(':') : null;
    }

    function format(value) {
        const normalized = normalize(value);
        if (!normalized) return clean(value) === '' ? '' : String(value ?? '');
        return fa(normalized.endsWith(':00') ? normalized.slice(0, 5) : normalized);
    }

    function now() {
        // Only the explicit "now" shortcut uses a timezone. Entered/stored times never shift.
        const timezone = document.documentElement.dataset.timezone || 'Asia/Tehran';
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone: timezone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23', numberingSystem: 'latn',
        }).formatToParts(new Date());
        return `${parts.find(p => p.type === 'hour').value}:${parts.find(p => p.type === 'minute').value}:00`;
    }

    function validate(input, normalizeValue = false, showError = false) {
        if (showError) touched.add(input);
        const value = normalize(input.value);
        let message = '';
        if (!input.disabled && !input.readOnly) {
            if (clean(input.value) === '') message = input.required ? 'ساعت را وارد کنید.' : '';
            else if (!value) message = 'ساعت معتبر ۲۴ساعته وارد کنید؛ مثلاً ۹:۳۰ یا ۹۳۰.';
        }
        input.setCustomValidity(message);
        const visible = Boolean(message && touched.has(input));
        input.classList.toggle('time-invalid', visible);
        if (visible) input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
        const error = input.closest('.time-field')?.querySelector('[data-time-error]');
        if (error) { error.textContent = visible ? message : ''; error.hidden = !visible; }
        if (normalizeValue && !message) input.value = format(input.value);
        return !message;
    }

    function prepare(input) {
        if (!prepared.has(input)) {
            makePopup();
            prepared.add(input);
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'none');
            input.value = format(input.value);
            input.defaultValue = format(input.defaultValue);
            input.setAttribute('aria-haspopup', 'dialog');
            input.setAttribute('aria-controls', 'time-picker-panel');
            input.setAttribute('aria-expanded', 'false');
        }
        const toggle = input.closest('.time-field')?.querySelector('[data-time-toggle]');
        if (toggle) { toggle.hidden = false; toggle.disabled = !editable(input); }
        validate(input);
    }

    function init(root = document) {
        if (isInput(root)) prepare(root);
        root.querySelectorAll?.(selector).forEach(prepare);
    }

    function makePopup() {
        if (popup) return;
        popup = document.createElement('div');
        popup.id = 'time-picker-panel';
        popup.className = 'time-picker';
        popup.hidden = true;
        popup.dir = 'rtl';
        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-labelledby', 'time-picker-title');
        const buttons = (values, part) => values.map(n => `<button type="button" data-time-${part}="${n}" aria-pressed="false" tabindex="-1">${fa(pad(n))}</button>`).join('');
        popup.innerHTML = `
            <div class="time-picker-heading"><div><strong id="time-picker-title">انتخاب ساعت</strong><small>۲۴ساعته، بدون قبل/بعدازظهر</small></div><button type="button" data-time-action="cancel" class="time-picker-close" aria-label="بستن انتخابگر ساعت">×</button></div>
            <div class="time-picker-parts" dir="ltr">
                <label>ساعت<input type="text" inputmode="numeric" maxlength="2" autocomplete="off" data-time-part="hour" aria-describedby="time-picker-error"></label>
                <span aria-hidden="true">:</span>
                <label>دقیقه<input type="text" inputmode="numeric" maxlength="2" autocomplete="off" data-time-part="minute" aria-describedby="time-picker-error"></label>
            </div>
            <div class="time-picker-caption">انتخاب ساعت</div>
            <div class="time-picker-hours" dir="ltr" role="group" aria-label="انتخاب سریع ساعت">${buttons(Array.from({ length: 24 }, (_, n) => n), 'hour')}</div>
            <div class="time-picker-caption">انتخاب دقیقه یا تایپِ مقدار دلخواه</div>
            <div class="time-picker-minutes" dir="ltr" role="group" aria-label="انتخاب سریع دقیقه">${buttons([0, 15, 30, 45], 'minute')}</div>
            <div id="time-picker-error" class="time-picker-error" aria-live="polite" hidden></div>
            <div class="time-picker-shortcuts"><button type="button" data-time-action="now">الان</button><button type="button" data-time-action="clear">پاک‌کردن</button></div>
            <button type="button" class="time-picker-apply" data-time-action="apply">تأیید <bdi dir="ltr" data-time-preview></bdi></button>`;
        document.body.appendChild(popup);
    }

    const partInput = part => popup.querySelector(`[data-time-part="${part}"]`);
    function readPart(part) {
        const text = clean(partInput(part).value);
        return /^\d{1,2}$/.test(text) && +text < (part === 'hour' ? 24 : 60) ? +text : null;
    }
    function draftValue() {
        const hour = readPart('hour'), minute = readPart('minute');
        return hour !== null && minute !== null ? [hour, minute, draftSeconds].map(pad).join(':') : null;
    }

    function renderDraft() {
        const value = draftValue();
        popup.querySelector('[data-time-preview]').textContent = value ? format(value) : '';
        popup.querySelector('[data-time-action="apply"]').disabled = !value || !editable(active);
        const error = popup.querySelector('#time-picker-error');
        error.hidden = Boolean(value);
        error.textContent = value ? '' : 'ساعت از ۰ تا ۲۳ و دقیقه از ۰ تا ۵۹ است.';
        for (const part of ['hour', 'minute']) {
            const selected = readPart(part);
            partInput(part).setAttribute('aria-invalid', String(selected === null));
            const choices = Array.from(popup.querySelectorAll(`[data-time-${part}]`));
            const tabStop = choices.find(button => +button.getAttribute(`data-time-${part}`) === selected) || choices[0];
            choices.forEach(button => {
                button.setAttribute('aria-pressed', String(selected === +button.getAttribute(`data-time-${part}`)));
                button.tabIndex = button === tabStop ? 0 : -1;
            });
        }
        position();
    }

    function position() {
        if (!active || popup.hidden) return;
        if (!editable(active)) { close(); return; }
        const rect = active.getBoundingClientRect();
        // The visual viewport also accounts for a mobile on-screen keyboard.
        const viewport = window.visualViewport;
        const left = viewport?.offsetLeft || 0, top = viewport?.offsetTop || 0;
        const width = viewport?.width || window.innerWidth, height = viewport?.height || window.innerHeight;
        popup.style.maxHeight = `${Math.max(100, height - 24)}px`;
        popup.style.width = `${Math.min(360, width - 24)}px`;
        const x = Math.max(left + 12, Math.min(rect.right - popup.offsetWidth, left + width - popup.offsetWidth - 12));
        let y = rect.bottom + 8;
        if (y + popup.offsetHeight > top + height - 12) y = rect.top - popup.offsetHeight - 8;
        y = Math.max(top + 12, Math.min(y, top + height - popup.offsetHeight - 12));
        popup.style.left = `${x}px`;
        popup.style.top = `${y}px`;
    }

    function open(input, trigger = input) {
        if (!editable(input)) return;
        prepare(input);
        close();
        makePopup();
        active = input;
        opener = trigger;
        // Opening/cancelling never fills an empty field or overwrites a failed POST.
        const value = normalize(input.value) || now();
        const [hour, minute, second] = value.split(':').map(Number);
        draftSeconds = second;
        partInput('hour').value = fa(pad(hour));
        partInput('minute').value = fa(pad(minute));
        const label = input.labels?.[0]?.textContent.replace(/\*/g, '').trim() || 'ساعت';
        popup.querySelector('#time-picker-title').textContent = `انتخاب ${label}`;
        popup.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        input.closest('.time-field')?.querySelector('[data-time-toggle]')?.setAttribute('aria-expanded', 'true');
        renderDraft();
        // A touch opening must not summon the keyboard and hide the quick-selection grid.
        const keyboard = trigger === input || window.matchMedia('(pointer: fine)').matches;
        const focusTarget = keyboard ? partInput('hour') : popup.querySelector('[data-time-action="cancel"]');
        focusTarget.focus({ preventScroll: true });
        if (keyboard) focusTarget.select();
    }

    function close(restoreFocus = false) {
        if (!active) return;
        const previous = active, trigger = opener;
        active = opener = null;
        popup.hidden = true;
        previous.setAttribute('aria-expanded', 'false');
        previous.closest('.time-field')?.querySelector('[data-time-toggle]')?.setAttribute('aria-expanded', 'false');
        if (restoreFocus && trigger?.isConnected && !trigger.disabled) trigger.focus({ preventScroll: true });
    }

    function commit(value) {
        if (!editable(active)) { close(); return; }
        const input = active, oldValue = input.value;
        input.value = format(value);
        validate(input, true, true);
        close(true);
        if (input.value !== oldValue) {
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function choose(part, value) {
        partInput(part).value = fa(pad(value));
        draftSeconds = 0;
        renderDraft();
    }

    document.addEventListener('focusin', event => {
        if (isInput(event.target)) prepare(event.target);
        if (active && event.target !== active && event.target !== opener && !popup.contains(event.target)) close();
    });
    document.addEventListener('pointerdown', event => {
        if (active && !popup.contains(event.target) && !active.closest('.time-field')?.contains(event.target) && event.target !== active) close();
    });
    document.addEventListener('click', event => {
        const toggle = event.target.closest?.('[data-time-toggle]');
        if (toggle) {
            const input = toggle.closest('.time-field')?.querySelector(selector);
            if (active === input) close(true); else open(input, toggle);
            return;
        }
        if (!active || !popup.contains(event.target)) return;
        const button = event.target.closest('button');
        if (!button) return;
        for (const part of ['hour', 'minute']) {
            if (button.hasAttribute(`data-time-${part}`)) { choose(part, +button.getAttribute(`data-time-${part}`)); return; }
        }
        switch (button.dataset.timeAction) {
            case 'cancel': close(true); break;
            case 'now': commit(now()); break;
            case 'clear': commit(''); break;
            case 'apply': if (draftValue()) commit(draftValue()); break;
        }
    });
    document.addEventListener('input', event => {
        if (isInput(event.target)) validate(event.target);
        if (active && event.target.matches?.('[data-time-part]')) { draftSeconds = 0; renderDraft(); }
    });
    document.addEventListener('change', event => {
        if (isInput(event.target)) validate(event.target, true, true);
    });
    document.addEventListener('blur', event => {
        if (isInput(event.target)) validate(event.target, true, true);
        if (active && event.target.matches?.('[data-time-part]')) {
            const value = readPart(event.target.dataset.timePart);
            if (value !== null) event.target.value = fa(pad(value));
        }
    }, true);
    document.addEventListener('invalid', event => {
        if (isInput(event.target)) validate(event.target, false, true);
    }, true);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && active) { event.preventDefault(); close(true); return; }
        if (isInput(event.target) && event.key === 'ArrowDown') { event.preventDefault(); open(event.target); return; }
        if (isInput(event.target) && event.key === 'Enter') validate(event.target, true, true);
        if (!active || !popup.contains(event.target)) return;
        if (event.key === 'Enter') {
            // Let buttons keep their native Enter action (including Cancel/Clear/Now).
            if (event.target.tagName !== 'BUTTON') { event.preventDefault(); if (draftValue()) commit(draftValue()); }
            return;
        }
        const part = event.target.dataset.timePart;
        if (part && ['ArrowUp', 'ArrowDown'].includes(event.key)) {
            event.preventDefault();
            const limit = part === 'hour' ? 24 : 60;
            choose(part, ((readPart(part) ?? 0) + (event.key === 'ArrowUp' ? 1 : -1) + limit) % limit);
            event.target.select();
        }
        for (const unit of ['hour', 'minute']) {
            if (!event.target.hasAttribute(`data-time-${unit}`)) continue;
            const choices = Array.from(popup.querySelectorAll(`[data-time-${unit}]`));
            const steps = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: unit === 'hour' ? 6 : 4, ArrowUp: unit === 'hour' ? -6 : -4 };
            let index = choices.indexOf(event.target);
            if (event.key === 'Home') index = 0;
            else if (event.key === 'End') index = choices.length - 1;
            else if (event.key in steps) index = (index + steps[event.key] + choices.length) % choices.length;
            else continue;
            event.preventDefault();
            choose(unit, +choices[index].getAttribute(`data-time-${unit}`));
            choices[index].focus({ preventScroll: true });
        }
    });
    document.addEventListener('submit', event => {
        let invalid;
        event.target.querySelectorAll(selector).forEach(input => {
            prepare(input);
            if (!validate(input, true, true)) invalid ||= input;
        });
        if (invalid) { event.preventDefault(); invalid.reportValidity(); }
    }, true);
    document.addEventListener('reset', event => {
        if (active?.form === event.target) close();
        setTimeout(() => event.target.querySelectorAll(selector).forEach(input => {
            touched.delete(input);
            validate(input, true);
        }), 0);
    });
    window.addEventListener('resize', position);
    window.addEventListener('scroll', position, true);
    window.visualViewport?.addEventListener('resize', position);
    window.visualViewport?.addEventListener('scroll', position);

    window.TimePicker = { init, open, close, normalize, format, now };
    // Run before unrelated CDN scripts; safe to call again or for dynamically added fields.
    init();
    document.addEventListener('DOMContentLoaded', () => init());
})();
