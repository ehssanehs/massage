const {test} = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

function route(get, post, fixtures = {}) {
  const input = {get, fixtures};
  if (post !== undefined) input.post = post;
  const res = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)], {cwd: path.join(__dirname, '..'), encoding: 'utf8'}));
  assert.equal(res.error, null, JSON.stringify(res.error));
  assert.notEqual(res.status, 500);
  return res;
}

const customer = {first_name: 'مریم', last_name: 'آزمایشی', mobile: '09120000000'};
const session = {customer_id: '1', therapist_id: '1', service_id: '1', massage_date: '۱۴۰۵/۰۱/۰۱', price: '1000', final_amount: '1000', status: 'completed'};
const sessionPost = (overrides = {}) => ({...session, ...overrides});

test('settings displays an Arab-customer commission percentage field', () => {
  const page = route({r: 'settings'}).body;
  assert.match(page, /درصد مشتری عرب/);
  assert.match(page, /name="settings\[arab_customer_commission_percent\]"/);
});

test('customer create/edit renders Arab checkbox and persists checked or unchecked value', () => {
  for (const r of ['customers.create', 'customers.edit']) {
    const page = route({r, id: 1}).body;
    assert.match(page, /type="checkbox"[^>]*name="is_arab_customer"/);
    const checked = route({r, id: 1}, {...customer, is_arab_customer: '1'});
    assert.equal(checked.writes.find(w => w.table === 'customers').data.is_arab_customer, 1);
    const unchecked = route({r, id: 1}, customer);
    assert.equal(unchecked.writes.find(w => w.table === 'customers').data.is_arab_customer, 0);
  }
});

test('old schema blocks customer writes and keeps the Arab choice checked after validation errors', () => {
  const schema={'customers.is_arab_customer':0,'massage_sessions.arab_commission_percent':0};
  const blocked=route({r:'customers.create'}, {...customer,is_arab_customer:'1'}, {schema_columns:schema});
  assert.equal(blocked.writes.some(w => w.table==='customers'),false);
  assert.match(blocked.body,/migrate/);
  assert.match(blocked.body,/name="is_arab_customer"[^>]*checked/);
});


test('failed customer edit retains an unchecked Arab choice instead of restoring the saved flag', () => {
  const failed=route({r:'customers.edit',id:1}, {...customer,mobile:''}, {customers:{is_arab_customer:1}});
  assert.equal(failed.writes.some(w=>w.table==='customers'),false);
  assert.match(failed.body,/name="is_arab_customer"/);
  assert.doesNotMatch(failed.body,/name="is_arab_customer"[^>]*checked/);
});

test('an Arab session stores an immutable percentage snapshot, ordinary session stores NULL', () => {
  const arab = route({r: 'sessions.create'}, session, {customers: {is_arab_customer: 1}, settings: {arab_customer_commission_percent: '۱۲٫۵'}});
  assert.equal(arab.writes.find(w => w.table === 'massage_sessions').data.arab_commission_percent, '12.50');
  const ordinary = route({r: 'sessions.create'}, session, {customers: {is_arab_customer: 0}, settings: {arab_customer_commission_percent: '12.50'}});
  assert.equal(ordinary.writes.find(w => w.table === 'massage_sessions').data.arab_commission_percent, null);
});

test('session edit preserves historical rate for the same customer, and changes it with the customer', () => {
  const existing = {customer_id: 1, arab_commission_percent: '12.50'};
  const same = route({r:'sessions.edit',id:1}, sessionPost(), {massage_sessions: existing, customers:{is_arab_customer:0},settings:{arab_customer_commission_percent:'25'}});
  const write = same.writes.find(w => w.table === 'massage_sessions' && w.operation === 'update');
  assert.ok(write);
  assert.ok(!Object.hasOwn(write.data, 'arab_commission_percent'));
  const changed = route({r:'sessions.edit',id:1}, sessionPost({customer_id:'2'}), {massage_sessions: existing, customers:{is_arab_customer:1},settings:{arab_customer_commission_percent:'25'}});
  assert.equal(changed.writes.find(w => w.table === 'massage_sessions' && w.operation === 'update')?.data.arab_commission_percent, '25.00');
});

test('an Arab session is blocked when the rate is unset or the schema is not migrated', () => {
  const missing = route({r:'sessions.create'}, sessionPost(), {customers:{is_arab_customer:1}});
  assert.equal(missing.writes.some(w => w.table === 'massage_sessions'), false);
  assert.match(missing.body, /درصد مشتری عرب در تنظیمات/);
  const schema = {'customers.is_arab_customer':1,'massage_sessions.arab_commission_percent':0};
  const old = route({r:'sessions.create'}, sessionPost(), {customers:{is_arab_customer:1},settings:{arab_customer_commission_percent:'12.5'},schema_columns:schema});
  assert.equal(old.writes.some(w => w.table === 'massage_sessions'), false);
  assert.match(old.body, /migrate/);
});

test('percentage input accepts Persian digits, and rejects arrays, negatives and values above 100', () => {
  const saved = route({r:'settings'}, {settings:{arab_customer_commission_percent:'۱۲٫۵'}}, {});
  assert.equal(saved.writes.find(w => w.operation === 'exec' && w.params?.[0] === 'arab_customer_commission_percent')?.params[1], '12.50');
  for (const invalid of [[], '-1','100.01','1.234']) {
    const result=route({r:'settings'}, {settings:{arab_customer_commission_percent:invalid}}, {});
    assert.equal(result.writes.some(w => w.operation === 'exec'), false);
  }
});

test('settings rejects invalid percentage without writing any general settings', () => {
  for (const value of ['-1', '101', '12.345', 'bad', ['12']]) {
    const res = route({r: 'settings'}, {settings: {arab_customer_commission_percent: value, brand_name: 'should-not-save'}});
    assert.match(res.body, /درصد مشتری عرب.*معتبر/);
    assert.equal(res.writes.filter(w => w.operation === 'exec' && /INSERT INTO settings/.test(w.sql)).length, 0);
  }
});
