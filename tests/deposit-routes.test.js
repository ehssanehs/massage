// Route tests for the appointment deposit feature: form field, list column,
// deposit filter checkbox, and query predicate.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

function route(get, post) {
    const input = { get: { ...get } };
    for (const opt of ['fixtures', 'search_fixture', 'overlap']) {
        if (input.get[opt] !== undefined) { input[opt] = input.get[opt]; delete input.get[opt]; }
    }
    if (post !== undefined) input.post = post;
    const output = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
    const result = JSON.parse(output);
    assert.equal(result.error, null, JSON.stringify(result.error));
    assert.ok([200, 302].includes(result.status), `unexpected status ${result.status}`);
    return result;
}

test('appointment create/edit forms render the deposit_amount field', () => {
    const create = route({ r: 'appointments.create' });
    assert.strictEqual(create.status, 200);
    assert.match(create.body, /name="deposit_amount"/);
    assert.match(create.body, /بیعانه/);
    const edit = route({ r: 'appointments.edit', id: 1 });
    assert.strictEqual(edit.status, 200);
    assert.match(edit.body, /name="deposit_amount"/);
});

test('deposit is never autofilled from service price', () => {
    const res = route({ r: 'appointments.create' });
    const input = res.body.match(/<input[^>]*name="deposit_amount"[^>]*>/)[0];
    assert.doesNotMatch(input, /data-autofill/);
});

const appt = { customer_id: '1', therapist_id: '1', service_id: '1', appointment_date: '۱۴۰۵/۰۱/۰۱', start_time: '10', end_time: '11', status: 'confirmed' };
test('invalid deposits never write an appointment', () => {
    for (const value of ['-1', 'oops', '1e3', '10000000000000', '1.001', ['1']]) {
        const res = route({ r: 'appointments.create' }, { ...appt, deposit_amount: value });
        assert.equal(res.status, 200);
        assert.equal(res.writes.filter(w => w.table === 'appointments').length, 0, JSON.stringify(value));
        assert.match(res.body, /بیعانه/);
    }
});
test('Persian deposit is saved exactly and empty deposit saves zero', () => {
    for (const [raw, expected] of [['۵۰۰۰۰۰٫۵۰', '500000.50'], ['', '0.00'], ['٠', '0.00']]) {
        const res = route({ r: 'appointments.create' }, { ...appt, deposit_amount: raw });
        assert.equal(res.status, 302);
        assert.equal(res.writes.find(w => w.table === 'appointments').data.deposit_amount, expected);
    }
});

test('deposit display preserves received fractional money', () => {
    const res = route({ r: 'appointments.show', id: 1, fixtures: { appointments: { deposit_amount: '750000.50' } } });
    assert.match(res.body, /۷۵۰,۰۰۰\.۵۰/);
});

test('appointments list renders the بیعانه column header', () => {
    const res = route({ r: 'appointments' });
    assert.strictEqual(res.status, 200);
    assert.match(res.body, /بیعانه/);
});

test('appointments page renders the deposit filter checkbox', () => {
    const res = route({ r: 'appointments' });
    assert.strictEqual(res.status, 200);
    assert.match(res.body, /name="deposit"/);
    assert.match(res.body, /فقط نوبت‌های بیعانه‌دار/);
});

test('deposit=1 adds deposit_amount > 0 predicate to list and count queries', () => {
    const res = route({ r: 'appointments', deposit: '1' });
    assert.strictEqual(res.status, 200);
    const preds = res.queries.filter(q => q.sql.includes('deposit_amount > 0'));
    assert.ok(preds.length >= 2, 'predicate in both data and count queries');
});

test('without deposit=1 no deposit predicate appears', () => {
    const res = route({ r: 'appointments' });
    assert.strictEqual(res.status, 200);
    const preds = res.queries.filter(q => q.sql.includes('deposit_amount > 0'));
    assert.strictEqual(preds.length, 0, 'no deposit predicate without filter');
});

test('appointment show page displays stored deposit as money', () => {
    const res = route({ r: 'appointments.show', id: 1, fixtures: { appointments: { deposit_amount: '750000.00' } } });
    assert.strictEqual(res.status, 200);
    assert.match(res.body, /بیعانه/);
    assert.match(res.body, /750,000|۷۵۰,۰۰۰/);
});
