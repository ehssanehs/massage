const {test} = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

// Customers are rows, follow-up turns are columns. Rows/columns come from explicit
// fixtures (the pivot depends on per-customer counts); real SQL is proven by
// tests/followup-matrix-http.py over MySQL.
const CUSTOMERS = [
  {id: 1, first_name: 'زهرا', last_name: 'کاظمی', mobile: '09121111111', status: 'active'},
  {id: 2, first_name: 'محمد', last_name: 'رضایی', mobile: '09122222222', status: 'active'},
  {id: 3, first_name: 'سمیرا', last_name: 'شریفی', mobile: '09123333333', status: 'inactive'},
];
const FOLLOWUPS = [
  {id: 11, customer_id: 1, due_date: '2026-03-21', status: 'booked', result: 'رزرو شد برای 2026-03-25', priority: 'high'},
  {id: 12, customer_id: 1, due_date: '2026-04-05', status: 'contacted', result: 'تماس گرفته شد', priority: 'normal'},
  {id: 13, customer_id: 1, due_date: '2026-05-01', status: 'refused', result: 'دیگر تماس نگیرید', priority: 'low'},
  {id: 21, customer_id: 2, due_date: '2026-03-22', status: 'pending', result: null, priority: 'normal'},
];

function route(params = {}, session = {uid: 1, tenant: 'men', _csrf: 't'}) {
  const input = {get: {r: 'followups.matrix', ...params}, session,
    fixtures: {matrix_customers: CUSTOMERS, matrix_followups: FOLLOWUPS, matrix_columns: 3, matrix_total: CUSTOMERS.length}};
  const result = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)], {cwd: path.join(__dirname, '..'), encoding: 'utf8'}));
  assert.equal(result.error, null, JSON.stringify(result.error));
  return result;
}
const cells = body => [...body.matchAll(/<td class="matrix-cell(?: matrix-empty)?">([\s\S]*?)<\/td>/g)].map(m => m[1]);

test('every customer is a row, including customers with no follow-up at all', () => {
  const {body, status, writes} = route();
  assert.equal(status, 200);
  assert.deepEqual(writes, [], 'the matrix is read-only');
  for (const name of ['زهرا کاظمی', 'محمد رضایی', 'سمیرا شریفی']) assert.match(body, new RegExp(name), name);
  // 3 customers × 3 columns of cells.
  assert.equal(cells(body).length, 9);
  assert.equal((body.match(/matrix-empty/g) || []).length, 5, 'customers with fewer follow-ups keep empty cells');
});

test('columns are the follow-up turns and cells carry status, date and full result', () => {
  const {body} = route();
  for (const n of ['۱', '۲', '۳']) assert.match(body, new RegExp(`پیگیری ${n}`), `column پیگیری ${n}`);
  for (const label of ['رزرو شد', 'تماس گرفته شد', 'رد کرد', 'در انتظار']) assert.match(body, new RegExp(label), label);
  assert.match(body, /۱۴۰۵\/۰۱\/۰۱/, 'due date renders in Jalali');
  assert.match(body, /رزرو شد برای ۱۴۰۵\/۰۱\/۰۵/, 'the full result text is shown, not truncated');
  assert.match(body, /بدون نتیجهٔ ثبت‌شده/, 'a follow-up with no result says so');
});

test('each cell and customer links back to the customer file', () => {
  const {body} = route();
  assert.match(body, /href="[^"]*r=customers\.show&id=1"/);
  assert.match(body, /href="[^"]*r=customers\.show&id=2"/);
  assert.match(body, /href="[^"]*r=customers\.show&id=3"/, 'even a customer without follow-ups is reachable');
  assert.match(body, /r=followups&filter=all&q=/);
});

test('the matrix filters customers by name while keeping the column count stable', () => {
  const {body, queries} = route({q: 'کاظمی'});
  const listQuery = queries.find(q => q.sql.includes('FROM customers c WHERE'));
  assert.ok(listQuery.params.length > 0, 'the search binds parameters');
  assert.ok(listQuery.sql.includes('CONCAT_WS'), 'compact names are matched through the shared full-name expression');
  assert.match(body, /پیگیری ۱/);
  assert.match(body, /پیگیری ۳/, 'a global column count does not shrink with the filtered rows');
});

test('an invalid or oversized search term is rejected instead of silently ignored', () => {
  const {status, body, queries} = route({q: 'x'.repeat(400)});
  assert.equal(status, 400);
  assert.match(body, /حداکثر/);
  assert.ok(!queries.some(q => q.sql.includes('FROM customers c WHERE')), 'no list query runs on a rejected search');
});

test('the pager keeps the search term and a stale page clamps', () => {
  const {body} = route({q: 'زهرا', page: '99'});
  assert.match(body, /r=followups\.matrix/);
  assert.match(body, /q=%D8%B2%D9%87%D8%B1%D8%A7/, 'the search term survives pagination links');
});

test('only the matrix menu entry is highlighted, not its parent too', () => {
  const matrix = route().body;
  const list = route({r: 'followups', filter: 'all'}).body;
  const active = html => [...html.matchAll(/class="nav-link active" href="[^"]*r=([^"&]+)/g)].map(m => m[1]);
  assert.deepEqual(active(matrix), ['followups.matrix']);
  assert.deepEqual(active(list), ['followups']);
});
