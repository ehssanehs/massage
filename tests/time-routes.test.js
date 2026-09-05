const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

function route(get, post, options = {}) {
    const input = { get, ...options };
    if (post !== undefined) input.post = post;
    const output = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
    const result = JSON.parse(output);
    assert.equal(result.error, null, JSON.stringify(result.error));
    assert.notEqual(result.status, 500);
    return result;
}
const booking = { customer_id: '1', therapist_id: '1', service_id: '1', appointment_date: '۱۴۰۵/۰۱/۰۱', start_time: '۹۳۰', end_time: '١٠:٤٥', status: 'pending' };
const session = { customer_id: '1', therapist_id: '1', service_id: '1', massage_date: '۱۴۰۵/۰۱/۰۱', start_time: '', end_time: '', price: '1000', final_amount: '1000', status: 'cancelled' };

test('every create/edit time field uses the shared 24-hour text input and local picker', () => {
    for (const module of ['appointments', 'sessions']) {
        for (const action of ['create', 'edit']) {
            const result = route({ r: `${module}.${action}`, id: 1 });
            for (const field of ['start_time', 'end_time']) {
                const input = result.body.match(new RegExp(`<input[^>]+name="${field}"[^>]*>`))[0];
                assert.match(input, /type="text"/);
                assert.match(input, /data-time-input dir="ltr" inputmode="numeric"/);
                assert.equal(/ required/.test(input), module === 'appointments');
            }
            assert.doesNotMatch(result.body, /type="time"/);
            assert.match(result.body, /assets\/js\/timepicker.js/);
            assert.match(result.body, /assets\/css\/timepicker.css/);
            assert.match(result.body, /۹۳۰/);
        }
    }
});

test('compact/Persian/Arabic times save as canonical TIME on create and edit, without JS', () => {
    for (const action of ['create', 'edit']) {
        const result = route({ r: `appointments.${action}`, id: 1 }, booking);
        const saved = result.writes.find(w => w.table === 'appointments').data;
        assert.equal(saved.start_time, '09:30:00');
        assert.equal(saved.end_time, '10:45:00');
        assert.equal(saved.appointment_date, '2026-03-21');
        const overlap = result.queries.find(q => q.sql.includes('AND (start_time < ?'));
        assert.deepEqual(overlap.params, ['1', '2026-03-21', action === 'edit' ? 1 : 0, '10:45:00', '09:30:00']);
        const sessionResult = route({ r: `sessions.${action}`, id: 1 }, { ...session, start_time: '۹', end_time: '1007' });
        const savedSession = sessionResult.writes.find(w => w.table === 'massage_sessions').data;
        assert.equal(savedSession.start_time, '09:00:00');
        assert.equal(savedSession.end_time, '10:07:00');
    }
});

test('invalid required/optional/array times block writes and preserve correctable text', () => {
    for (const action of ['create', 'edit']) {
        for (const module of ['appointments', 'sessions']) {
            for (const invalid of ['۲۵:۶۰', ['930']]) {
                const result = route({ r: `${module}.${action}`, id: 1 }, { ...(module === 'appointments' ? booking : session), start_time: invalid });
                assert.equal(result.writes.length, 0, `${module}.${action}`);
                assert.match(result.body, /شروع باید ساعت معتبر ۲۴ساعته/);
                if (typeof invalid === 'string') assert.match(result.body, /value="۲۵:۶۰"/);
                assert.equal(result.queries.some(q => q.sql.includes('AND (start_time < ?')), false);
            }
        }
    }
    const required = route({ r: 'appointments.create' }, { ...booking, start_time: ' ', end_time: '' });
    assert.equal(required.writes.length, 0);
    assert.match(required.body, /ساعت شروع الزامی است/);
    assert.match(required.body, /ساعت پایان الزامی است/);
    const end = route({ r: 'appointments.create' }, { ...booking, end_time: '1260' });
    assert.equal(end.writes.length, 0);
    assert.match(end.body, /ساعت پایان باید ساعت معتبر ۲۴ساعته/);
});

test('optional blanks save NULL, while midnight is a real time', () => {
    const empty = route({ r: 'sessions.edit', id: 1 }, session).writes.find(w => w.table === 'massage_sessions').data;
    assert.equal(empty.start_time, null);
    assert.equal(empty.end_time, null);
    const midnight = route({ r: 'appointments.create' }, { ...booking, start_time: '۰', end_time: '۱' });
    assert.equal(midnight.writes.find(w => w.table === 'appointments').data.start_time, '00:00:00');
    assert.equal(midnight.writes.find(w => w.table === 'appointments').data.end_time, '01:00:00');
});

test('restored times with seconds survive form rendering, failed POST and resaving', () => {
    const options = { fixtures: { appointments: { start_time: '10:07:09', end_time: '11:07:09' } } };
    const edit = route({ r: 'appointments.edit', id: 1 }, undefined, options);
    assert.match(edit.body, /name="start_time" value="۱۰:۰۷:۰۹"/);
    const failed = route({ r: 'appointments.edit', id: 1 }, { ...booking, appointment_date: '', start_time: '۱۰:۰۷:۰۹', end_time: '11:07:09' }, options);
    assert.equal(failed.writes.length, 0);
    assert.match(failed.body, /name="start_time" value="۱۰:۰۷:۰۹"/);
    const saved = route({ r: 'appointments.edit', id: 1 }, { ...booking, start_time: '۱۰:۰۷:۰۹', end_time: '11:07:09' }, options).writes.find(w => w.table === 'appointments').data;
    assert.equal(saved.start_time, '10:07:09');
    assert.equal(saved.end_time, '11:07:09');
    const view = route({ r: 'appointments.show', id: 1 }, undefined, options);
    assert.match(view.body, /۱۰:۰۷:۰۹/);
});

test('normalized hours still participate in duplicate-booking validation', () => {
    const result = route({ r: 'appointments.create' }, booking, { overlap: true });
    assert.equal(result.writes.length, 0);
    assert.match(result.body, /نوبت دیگری دارد/);
    assert.match(result.body, /name="start_time" value="۰۹:۳۰"/);
    assert.match(result.body, /name="end_time" value="۱۰:۴۵"/);
});
