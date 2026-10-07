// Route tests for followup center enhancements: search param, quick filters,
// bulk delete POST, and re-followup creation POST.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

// Only the DB is stubbed (route-harness.php). PHP_BIN for custom PHP paths.
function route(get, post) {
    const input = { get: { ...get } };
    // fixtures/search_fixture/overlap are harness options, not query params
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

test('followup page renders search input, quick filters and selection UI', () => {
    const res = route({ r: 'followups', filter: 'active' });
    assert.strictEqual(res.status, 200);
    assert.match(res.body, /name="q"/);
    assert.match(res.body, /جستجوی نام مشتری/);
    assert.match(res.body, /filter=overdue/);
    assert.match(res.body, /filter=today/);
    assert.match(res.body, /bulkDeleteForm/);
    assert.match(res.body, /fuCheckAll/);
});

test('completed followup renders an edit button and prefilled status/result form', () => {
    const res = route({ r: 'followups', filter: 'done', fixtures: { followups: { status: 'booked', result: 'رزرو اولیه' } } });
    assert.equal(res.status, 200);
    assert.match(res.body, /<button[^>]*>[^<]*<i[^>]*><\/i> ویرایش<\/button>/);
    assert.match(res.body, /name="edit_result" value="1"/);
    assert.match(res.body, /<option value="booked" selected>/);
    assert.match(res.body, /name="result"[^>]*value="رزرو اولیه"/);
});

test('editing a completed followup changes status and result without scheduling or duplicate completion events', () => {
    const res = route({ r: 'followups', filter: 'done' }, { id: '1', edit_result: '1', status: 'refused', result: 'نظر تغییر کرد', refollow_days: '7' });
    assert.equal(res.status, 302);
    const updates = res.writes.filter(w => w.operation === 'update' && w.table === 'followups');
    assert.equal(updates.length, 1);
    assert.equal(updates[0].data.status, 'refused');
    assert.equal(updates[0].data.result, 'نظر تغییر کرد');
    assert.equal(res.writes.filter(w => w.operation === 'insert' && w.table === 'followups').length, 0);
    assert.equal(res.writes.filter(w => w.operation === 'insert' && w.table === 'customer_timeline' && w.data.type === 'followup_done').length, 0);
    assert.equal(res.writes.filter(w => w.operation === 'insert' && w.table === 'customer_timeline' && w.data.type === 'followup').length, 1);
});

test('completed followup edit rejects invalid statuses and cannot rewrite pending rows', () => {
    for (const [status, fixture] of [['arbitrary', {}], ['booked', { status: 'pending' }]]) {
        const res = route({ r: 'followups', filter: 'done', fixtures: { followups: fixture } }, { id: '1', edit_result: '1', status, result: 'تحریف' });
        assert.equal(res.writes.filter(w => w.operation === 'update' && w.table === 'followups').length, 0);
    }
});

test('edit form escapes stored result and keeps filter/search on submission', () => {
    const res = route({ r: 'followups', filter: 'done', q: 'مشتری', fixtures: { followups: { status: 'refused', result: '\" autofocus onfocus=\"alert(1)' } } });
    assert.match(res.body, /name="result"[^>]*value="&quot; autofocus onfocus=&quot;alert\(1\)"/);
    assert.match(res.body, /name="edit_result" value="1"/);
});

test('edit form localizes stored Gregorian dates before showing the result', () => {
    const res = route({ r: 'followups', filter: 'done' });
    assert.doesNotMatch(res.body, /2026-03-21/);
    assert.match(res.body, /name="result"[^>]*value="تماس در ۱۴۰۵\/۰۱\/۰۱"/);
});

test('correcting a completed followup to pending clears contact time and creates no new followup', () => {
    const res = route({ r: 'followups', filter: 'done', q: 'مشتری' }, { id: '1', edit_result: '1', status: 'pending', result: '' });
    const edit = res.writes.find(w => w.operation === 'update' && w.table === 'followups');
    assert.equal(edit.data.status, 'pending');
    assert.equal(edit.data.contacted_at, null);
    assert.equal(res.writes.filter(w => w.operation === 'insert' && w.table === 'followups').length, 0);
});

test('contacted requested_later stays in done only and exposes the edit form', () => {
    const res = route({ r: 'followups', filter: 'done', fixtures: { followups: { status: 'requested_later' } } });
    assert.match(res.body, /name="edit_result" value="1"/);
    assert.match(res.body, /<option value="requested_later" selected>/);
    const active = route({ r: 'followups', filter: 'active' });
    const activeGroups = active.queries.filter(q => q.sql.includes('FROM followups f JOIN customers'));
    assert.ok(activeGroups.every(q => !q.sql.includes("f.status IN ('pending','requested_later')")), 'contacted requested_later must not count as active');
});

test('corrected booked timeline labels its original reservation as historical', () => {
    const res = route({ r: 'customers.show', id: 1, fixtures: { customer_timeline: { type: 'followup_done', entity_id: 1, body: 'نتیجه: رزرو شد' }, followups: { status: 'refused' } } });
    assert.match(res.body, /نتیجه قبلی.*بعداً اصلاح شد/);
});

test('edit correction event records the new status explicitly', () => {
    const res = route({ r: 'followups', filter: 'done' }, { id: '1', edit_result: '1', status: 'refused', result: 'نظر تغییر کرد' });
    const correction = res.writes.find(w => w.table === 'customer_timeline' && w.data.title === 'اصلاح نتیجه پیگیری');
    assert.match(correction.data.body, /رد کرد/);
    assert.match(correction.data.body, /نظر تغییر کرد/);
});

test('ordinary result POST refuses completed, deleted and invalid-status followups', () => {
    for (const [fixture, status] of [[{ status: 'booked' }, 'booked'], [{ status: 'pending', deleted_at: '2026-03-22' }, 'booked'], [{ status: 'pending' }, 'invalid']]) {
        const res = route({ r: 'followups', fixtures: { followups: fixture } }, { id: '1', status, result: 'نباید ثبت شود' });
        assert.equal(res.status, 302);
        assert.equal(res.writes.filter(w => w.table === 'followups' || w.table === 'customer_timeline').length, 0);
    }
});

test('pending followup rows render the refollow_days input', () => {
    const res = route({ r: 'followups', filter: 'active', fixtures: { followups: { status: 'pending' } } });
    assert.strictEqual(res.status, 200);
    assert.match(res.body, /refollow_days/);
});

test('overdue filter renders only the overdue group', () => {
    const res = route({ r: 'followups', filter: 'overdue' });
    assert.strictEqual(res.status, 200);
    assert.doesNotMatch(res.body, /۷ روز آینده/);
    assert.doesNotMatch(res.body, /آینده دور/);
});

test('today filter renders only the today group', () => {
    const res = route({ r: 'followups', filter: 'today' });
    assert.strictEqual(res.status, 200);
    assert.doesNotMatch(res.body, /۷ روز آینده/);
});

test('search with q adds LIKE param to group queries', () => {
    const res = route({ r: 'followups', filter: 'active', q: 'Ali' });
    assert.strictEqual(res.status, 200);
    const like = res.queries.filter(q => q.sql.includes('LIKE :qname'));
    assert.ok(like.length >= 1, 'LIKE :qname used in group queries');
    assert.strictEqual(like[0].params[':qname'], '%Ali%', 'binds %Ali%');
});

test('bulk delete POST soft-deletes selected followups', () => {
    const res = route({ r: 'followups', filter: 'all' }, { bulk_action: 'delete', ids: ['1', '2', 'x'], _csrf: 'test-only-csrf-token' });
    assert.strictEqual(res.status, 302);
    const dels = res.writes.filter(w => w.operation === 'exec' && /UPDATE followups SET deleted_at=NOW\(\)/.test(w.sql));
    assert.strictEqual(dels.length, 2, 'two soft-deletes issued for ids 1 and 2');
});

test('result POST with refollow_days=7 inserts a new pending followup + scheduled timeline', () => {
    const res = route({ r: 'followups', filter: 'active', fixtures: { followups: { status: 'pending' } } }, { id: '1', status: 'contacted', result: 'تماس گرفت', refollow_days: '7', _csrf: 'test-only-csrf-token' });
    assert.strictEqual(res.status, 302);
    const newFu = res.writes.filter(w => w.table === 'followups' && w.operation === 'insert' && w.data.status === 'pending');
    assert.strictEqual(newFu.length, 1, 'one new pending followup inserted');
    const sched = res.writes.filter(w => w.table === 'customer_timeline' && w.data.type === 'followup_scheduled');
    assert.strictEqual(sched.length, 1, 'followup_scheduled timeline row written');
});

test('booked POST creates followup_done timeline but no re-followup', () => {
    const res = route({ r: 'followups', filter: 'active', fixtures: { followups: { status: 'pending' } } }, { id: '1', status: 'booked', result: '', refollow_days: '5', _csrf: 'test-only-csrf-token' });
    assert.strictEqual(res.status, 302);
    const newFu = res.writes.filter(w => w.table === 'followups' && w.operation === 'insert');
    assert.strictEqual(newFu.length, 0, 'no followup created for booked');
    const done = res.writes.filter(w => w.table === 'customer_timeline' && w.data.type === 'followup_done');
    assert.strictEqual(done.length, 1, 'followup_done timeline row written');
});

test('non-booked POST without days creates nothing extra', () => {
    const res = route({ r: 'followups', filter: 'active', fixtures: { followups: { status: 'pending' } } }, { id: '1', status: 'not_answered', result: '', _csrf: 'test-only-csrf-token' });
    assert.strictEqual(res.status, 302);
    assert.equal(res.writes.filter(w => w.operation === 'update' && w.table === 'followups').length, 1);
    const newFu = res.writes.filter(w => w.table === 'followups' && w.operation === 'insert');
    assert.strictEqual(newFu.length, 0);
});
