const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const script = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');

function cell(text, tagName = 'TD', content = null) {
    return {
        tagName, textContent: text, children: [], attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        getAttribute(name) { return this.attributes[name]; },
        appendChild(child) { this.children.push(child); child.parentElement = this; },
        querySelector(selector) { return selector === 'button' ? this.children.find(child => child.tagName === 'BUTTON') || null : null; },
        content
    };
}
function row(...cells) { return { cells }; }
function table(headers, records, { bare = false, server = false } = {}) {
    const header = row(...headers.map(text => cell(text, 'TH')));
    const body = {
        rows: bare ? [header, ...records] : [...records],
        appendChild(item) { this.rows.splice(this.rows.indexOf(item), 1); this.rows.push(item); }
    };
    const result = {
        tHead: bare ? null : { rows: [header] },
        tBodies: {0: body, length: 1}, // HTMLCollection is array-like, not an Array
        rows: bare ? body.rows : [header, ...body.rows],
        hasAttribute(name) { return server && name === 'data-server-sort'; },
        createCaption() { return this.caption = {textContent: '', className: ''}; }
    };
    return result;
}
function boot(tables) {
    const document = {
        documentElement: { getAttribute() { return null; } },
        getElementById() { return null; },
        querySelector() { return null; },
        querySelectorAll(selector) { return selector === 'table' ? tables : []; },
        createElement(tagName) {
            return {
                tagName: tagName.toUpperCase(), attributes: {}, listeners: {},
                setAttribute(name, value) { this.attributes[name] = value; },
                getAttribute(name) { return this.attributes[name]; },
                addEventListener(name, fn) { this.listeners[name] = fn; },
                click() { this.listeners.click({ preventDefault() {} }); }
            };
        },
        addEventListener(name, callback) { if (name === 'DOMContentLoaded') this.ready = callback; }
    };
    vm.runInNewContext(script, { document, window: {}, localStorage: { getItem() { return null; } }, setTimeout() {}, Intl, console });
    document.ready();
    return tables;
}
function labels(t) { return t.tBodies[0].rows.map(r => r.cells[0].textContent); }
function click(t, index) { t.tHead ? t.tHead.rows[0].cells[index].children[0].click() : t.tBodies[0].rows[0].cells[index].children[0].click(); }

test('sorts plain text with fa-IR collation, toggles direction, and exposes accessible state', () => {
    const t = table(['نام'], [row(cell('یاسمن')), row(cell('آرزو')), row(cell('بهرام'))]);
    boot([t]);
    const th = t.tHead.rows[0].cells[0];
    const button = th.children[0];
    assert.equal(button.tagName, 'BUTTON');
    assert.equal(button.getAttribute('type'), 'button');
    assert.match(t.caption.textContent, /فقط.*نمایش/);
    click(t, 0);
    assert.deepEqual(labels(t), ['آرزو', 'بهرام', 'یاسمن']);
    assert.equal(th.getAttribute('aria-sort'), 'ascending');
    assert.match(button.textContent, /▲/);
    click(t, 0);
    assert.deepEqual(labels(t), ['یاسمن', 'بهرام', 'آرزو']);
    assert.equal(th.getAttribute('aria-sort'), 'descending');
    assert.match(button.textContent, /▼/);
});

test('sorts dates with Persian and Arabic digits by date and time, blank last both ways', () => {
    const t = table(['زمان'], [row(cell('۱۴۰۳/۱۰/۲ ۰۹:۰۰')), row(cell('١٤٠٣/٢/١ ١١:٠٠')), row(cell('۱۴۰۳/۱۰/۲ ۰۸:۰۰')), row(cell('—'))]);
    boot([t]);
    click(t, 0);
    assert.deepEqual(labels(t), ['١٤٠٣/٢/١ ١١:٠٠', '۱۴۰۳/۱۰/۲ ۰۸:۰۰', '۱۴۰۳/۱۰/۲ ۰۹:۰۰', '—']);
    click(t, 0);
    assert.deepEqual(labels(t), ['۱۴۰۳/۱۰/۲ ۰۹:۰۰', '۱۴۰۳/۱۰/۲ ۰۸:۰۰', '١٤٠٣/٢/١ ١١:٠٠', '—']);
});

test('sorts Persian currency numerically and preserves equal-key input order', () => {
    const records = [row(cell('الف'), cell('۲٬۰۰۰ ریال')), row(cell('ب'), cell('١٠٠ ریال')), row(cell('پ'), cell('۲,۰۰۰ ریال')), row(cell('ت'), cell('—'))];
    const t = table(['نام', 'مبلغ'], records);
    boot([t]);
    click(t, 1);
    assert.deepEqual(labels(t), ['ب', 'الف', 'پ', 'ت']);
    click(t, 1);
    assert.deepEqual(labels(t), ['الف', 'پ', 'ب', 'ت']);
});

test('sorts signed credit history amounts by actual numeric value', () => {
    const t = table(['مبلغ'], [row(cell('+۲٬۰۰۰')),row(cell('+۱۰')),row(cell('-۱۰۰'))]);
    boot([t]);
    click(t, 0);
    assert.deepEqual(labels(t), ['-۱۰۰', '+۱۰', '+۲٬۰۰۰']);
    click(t, 0);
    assert.deepEqual(labels(t), ['+۲٬۰۰۰', '+۱۰', '-۱۰۰']);
});

test('does not move header in implicit tbody and leaves checkbox/action cells alone', () => {
    const checkbox = { checked: true };
    const action = { id: 'edit-link' };
    const a = row(cell('ب'), cell('', 'TD', checkbox), cell('', 'TD', action));
    const b = row(cell('آ'), cell('', 'TD', {}), cell('', 'TD', {}));
    const t = table(['نام', '', 'عملیات'], [a, b], { bare: true });
    boot([t]);
    assert.equal(t.tBodies[0].rows[0].cells[0].tagName, 'TH');
    assert.equal(t.tBodies[0].rows[0].cells[1].children.length, 0);
    assert.equal(t.tBodies[0].rows[0].cells[2].children.length, 0);
    click(t, 0);
    assert.equal(t.tBodies[0].rows[0].cells[0].tagName, 'TH');
    assert.equal(t.tBodies[0].rows[1], b);
    assert.equal(t.tBodies[0].rows[2], a);
    assert.equal(a.cells[1].content, checkbox);
    assert.equal(checkbox.checked, true);
    assert.equal(a.cells[2].content, action);
});

test('switching columns resets first click to ascending', () => {
    const t = table(['نام', 'امتیاز'], [row(cell('ب'), cell('۲')), row(cell('آ'), cell('۱'))]);
    boot([t]);
    click(t, 0);
    click(t, 1);
    click(t, 0);
    assert.equal(t.tHead.rows[0].cells[0].getAttribute('aria-sort'), 'ascending');
    assert.deepEqual(labels(t), ['آ', 'ب']);
});

test('skips server-sorted tables and sorts each other table independently', () => {
    const excluded = table(['نام'], [row(cell('ب')), row(cell('آ'))], { server: true });
    const included = table(['نام'], [row(cell('ب')), row(cell('آ'))]);
    boot([excluded, included]);
    assert.equal(excluded.tHead.rows[0].cells[0].children.length, 0);
    click(included, 0);
    assert.deepEqual(labels(excluded), ['ب', 'آ']);
    assert.deepEqual(labels(included), ['آ', 'ب']);
});
