const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

// The DB is stubbed; Credit service calls go through App\Core\DB::insert/update —
// recorded in result.writes so we can assert the exact ledger behavior.
function route(get, post) {
    const input = { get, search_fixture: false };
    if (post !== undefined) input.post = post;
    const output = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
    const result = JSON.parse(output);
    assert.equal(result.error, null, JSON.stringify(result.error));
    assert.notEqual(result.status, 500);
    return result;
}

const sessionPost = {
    customer_id: 1, therapist_id: 1, service_id: 1, massage_date: '۱۴۰۵/۰۱/۰۱',
    price: '2000000', discount: '0', final_amount: '2000000',
    payment_method: 'card', payment_status: 'paid', status: 'completed', credit_used: '0',
};

test('session form renders the credit_used field with a balance hint and no stored value', () => {
    const create = route({ r: 'sessions.create' });
    assert.match(create.body, /name="credit_used"[^>]+data-credit="1"/);
    assert.match(create.body, /value=""/);
    assert.doesNotMatch(create.body, /min="-/);
    const edit = route({ r: 'sessions.edit', id: 1 });
    assert.match(edit.body, /name="credit_used"/);
});

test('creating a paid session posts an earn transaction (10% of final_amount)', () => {
    const result = route({ r: 'sessions.create' }, sessionPost);
    const inserts = result.writes.filter(w => w.operation === 'insert');
    const earn = inserts.find(w => w.table === 'credit_transactions' && w.data.kind === 'earn');
    assert.ok(earn, 'earn row posted');
    assert.equal(earn.data.amount, 200000, '10% of 2,000,000');
    assert.equal(earn.data.entity, 'massage_sessions');
    assert.equal(earn.data.entity_id, 1);
});

test('creating a session with credit_used posts both earn and spend', () => {
    const result = route({ r: 'sessions.create' }, { ...sessionPost, credit_used: '150000' });
    const inserts = result.writes.filter(w => w.operation === 'insert' && w.table === 'credit_transactions');
    assert.ok(inserts.some(w => w.data.kind === 'earn' && w.data.amount === 200000), 'earn posted');
    const spend = inserts.find(w => w.data.kind === 'spend');
    assert.ok(spend, 'spend posted');
    assert.equal(spend.data.amount, -150000, 'spend is negative');
});

test('unpaid sessions post no credit transactions', () => {
    const result = route({ r: 'sessions.create' }, { ...sessionPost, payment_status: 'unpaid' });
    const ledger = result.writes.filter(w => w.table === 'credit_transactions');
    assert.equal(ledger.length, 0, 'no ledger writes for unpaid');
});

test('credit_used is a virtual field: never written into the sessions table', () => {
    const result = route({ r: 'sessions.create' }, sessionPost);
    const sessionInsert = result.writes.find(w => w.table === 'massage_sessions' && w.operation === 'insert');
    assert.ok(sessionInsert);
    assert.equal(false, 'credit_used' in sessionInsert.data, 'credit_used not persisted as a column');
});

test('packages use price for the earn calculation', () => {
    const result = route({ r: 'packages.create' }, {
        customer_id: 1, title: 'پکیج آزمایشی', total_sessions: '10', used_sessions: '0',
        price: '1000000', payment_status: 'paid', status: 'active', credit_used: '0',
    });
    const earn = result.writes.find(w => w.table === 'credit_transactions' && w.data.kind === 'earn');
    assert.ok(earn, 'package earn posted');
    assert.equal(earn.data.amount, 100000, '10% of package price');
    assert.equal(earn.data.entity, 'customer_packages');
});
