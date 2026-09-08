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
    const res = route({ r: 'followups', filter: 'active' }, { id: '1', status: 'contacted', result: 'تماس گرفت', refollow_days: '7', _csrf: 'test-only-csrf-token' });
    assert.strictEqual(res.status, 302);
    const newFu = res.writes.filter(w => w.table === 'followups' && w.operation === 'insert' && w.data.status === 'pending');
    assert.strictEqual(newFu.length, 1, 'one new pending followup inserted');
    const sched = res.writes.filter(w => w.table === 'customer_timeline' && w.data.type === 'followup_scheduled');
    assert.strictEqual(sched.length, 1, 'followup_scheduled timeline row written');
});

test('booked POST creates followup_done timeline but no re-followup', () => {
    const res = route({ r: 'followups', filter: 'active' }, { id: '1', status: 'booked', result: '', refollow_days: '5', _csrf: 'test-only-csrf-token' });
    assert.strictEqual(res.status, 302);
    const newFu = res.writes.filter(w => w.table === 'followups' && w.operation === 'insert');
    assert.strictEqual(newFu.length, 0, 'no followup created for booked');
    const done = res.writes.filter(w => w.table === 'customer_timeline' && w.data.type === 'followup_done');
    assert.strictEqual(done.length, 1, 'followup_done timeline row written');
});

test('non-booked POST without days creates nothing extra', () => {
    const res = route({ r: 'followups', filter: 'active' }, { id: '1', status: 'not_answered', result: '', _csrf: 'test-only-csrf-token' });
    assert.strictEqual(res.status, 302);
    const newFu = res.writes.filter(w => w.table === 'followups' && w.operation === 'insert');
    assert.strictEqual(newFu.length, 0);
});
