const {test} = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

// Customers are rows, follow-up turns are columns, and each cell records or
// corrects its own follow-up. Rows/columns come from explicit fixtures (the pivot
// depends on per-customer counts); real SQL is proven by
// tests/followup-matrix-http.py over MySQL.
const CUSTOMERS = [
  {id: 1, first_name: 'زهرا', last_name: 'کاظمی', mobile: '09121111111', status: 'active'},
  {id: 2, first_name: 'محمد', last_name: 'رضایی', mobile: '09122222222', status: 'active'},
  {id: 3, first_name: 'سمیرا', last_name: 'شریفی', mobile: '09123333333', status: 'inactive'},
];
const FOLLOWUPS = [
  {id: 11, customer_id: 1, due_date: '2026-03-21', status: 'booked', result: 'رزرو شد برای 2026-03-25', priority: 'high', contacted_at: '2026-03-21 13:05:09'},
  {id: 12, customer_id: 1, due_date: '2026-04-05', status: 'contacted', result: 'تماس گرفته شد', priority: 'normal', contacted_at: '2026-04-05 09:00:00'},
  {id: 13, customer_id: 1, due_date: '2026-05-01', status: 'refused', result: 'دیگر تماس نگیرید', priority: 'low', contacted_at: '2026-05-01 11:30:00'},
  {id: 21, customer_id: 2, due_date: '2026-03-22', status: 'pending', result: null, priority: 'normal', contacted_at: null},
];
const MANAGER = {uid: 1, tenant: 'men', _csrf: 't'};
const VIEWER = {uid: 2, tenant: 'men', _csrf: 't'};
const VIEWER_FIXTURES = {
  users: {role_slug: 'receptionist', role_name: 'پذیرش', role_id: 3, permissions: '["followups.view"]', email: 'viewer@example.invalid'},
  role_permissions: '["followups.view"]',
};

function route(params = {}, session = MANAGER, extra = {}) {
  const input = {
    get: {r: 'followups.matrix', ...params},
    session,
    fixtures: {matrix_customers: CUSTOMERS, matrix_followups: FOLLOWUPS, matrix_columns: 3, matrix_total: CUSTOMERS.length, ...(extra.fixtures || {})},
  };
  if (extra.post) input.post = extra.post;
  if (extra.requestUri) input.request_uri = extra.requestUri;
  const result = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)], {cwd: path.join(__dirname, '..'), encoding: 'utf8'}));
  assert.equal(result.error, null, JSON.stringify(result.error));
  return result;
}
const updates = result => result.writes.filter(w => w.operation === 'update' && w.table === 'followups');
const timeline = result => result.writes.filter(w => w.operation === 'insert' && w.table === 'customer_timeline');
const audit = result => result.writes.filter(w => w.operation === 'insert' && w.table === 'audit_logs');
const cells = body => [...body.matchAll(/<td class="matrix-cell(?: matrix-empty)?">([\s\S]*?)<\/td>/g)].map(m => m[1]);

test('every customer is a row, including customers with no follow-up at all', () => {
  const {body, status, writes} = route();
  assert.equal(status, 200);
  assert.deepEqual(writes, [], 'simply opening the matrix writes nothing');
  for (const name of ['زهرا کاظمی', 'محمد رضایی', 'سمیرا شریفی']) assert.match(body, new RegExp(name), name);
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

test('an open follow-up gets a record form, a recorded one gets an edit form', () => {
  const {body} = route();
  const openCell = cells(body).find(c => c.includes('value="record"'));
  const editCell = cells(body).find(c => c.includes('value="edit"'));
  assert.ok(openCell, 'the pending follow-up offers recording');
  assert.match(openCell, /name="status"/);
  assert.match(openCell, /name="result"/);
  assert.match(openCell, /fu-refollow-days/, 'the re-followup field keeps the shared show/hide hook');
  assert.match(openCell, /followup-action-form/, 'the shared class makes the existing JS drive it');
  assert.ok(editCell, 'a recorded follow-up offers correction');
  assert.match(editCell, /name="status"[\s\S]*selected/, 'the edit form preselects the current status');
  assert.match(editCell, /value="رزرو شد برای ۱۴۰۵\/۰۱\/۰۵"/, 'the edit form is prefilled with the current result');
  assert.equal((body.match(/value="record"/g) || []).length, 1, 'only the open follow-up can be recorded');
  assert.equal((body.match(/value="edit"/g) || []).length, 3, 'each recorded follow-up can be corrected');
});

test('recording a result from a cell follows the same rules as the follow-up list', () => {
  const {status, session, writes} = route({}, MANAGER, {post: {id: '21', matrix_action: 'record', status: 'booked', result: 'رزرو تلفنی'}});
  assert.equal(status, 302);
  const [update] = updates({writes});
  assert.ok(update, 'the follow-up row is updated');
  assert.equal(update.data.status, 'booked');
  assert.equal(update.data.result, 'رزرو تلفنی');
  assert.ok(update.data.contacted_at, 'contacting is timestamped');
  const titles = timeline({writes}).map(w => w.data.title);
  assert.ok(titles.includes('نتیجه پیگیری'), 'the result is written to the customer timeline');
  assert.ok(titles.includes('پیگیری انجام شد'), 'a booked result adds its completion event exactly once');
  assert.equal(session.toast, 'نتیجه پیگیری ثبت شد.');
});

test('recording schedules the next follow-up when a day count is given', () => {
  const {writes, session} = route({}, MANAGER, {post: {id: '21', matrix_action: 'record', status: 'interested', result: 'علاقه‌مند', refollow_days: '7'}});
  const created = writes.filter(w => w.operation === 'insert' && w.table === 'followups');
  assert.equal(created.length, 1, 'exactly one follow-up is scheduled');
  assert.equal(created[0].data.status, 'pending');
  assert.ok(created[0].data.due_date > '2026-01-01');
  assert.match(session.toast, /پیگیری بعدی برای/);
});

test('a follow-up that already has a result cannot be recorded again', () => {
  const {session, writes} = route({}, MANAGER, {post: {id: '11', matrix_action: 'record', status: 'booked', result: 'دوباره'}});
  assert.deepEqual(updates({writes}), [], 'no second result is written');
  assert.deepEqual(timeline({writes}), []);
  assert.equal(session.toast, 'ثبت نتیجه پیگیری نامعتبر است.');
});

test('an open follow-up cannot be edited as if it had a result', () => {
  const {session, writes} = route({}, MANAGER, {post: {id: '21', matrix_action: 'edit', status: 'refused', result: 'x'}});
  assert.deepEqual(updates({writes}), []);
  assert.equal(session.toast, 'ویرایش پیگیری نامعتبر است.');
});

test('editing a cell corrects the row and leaves an audit trail', () => {
  const {status, session, writes} = route({}, MANAGER, {post: {id: '11', matrix_action: 'edit', status: 'refused', result: 'اصلاح شد'}});
  assert.equal(status, 302);
  const [update] = updates({writes});
  assert.equal(update.data.status, 'refused');
  assert.equal(update.data.result, 'اصلاح شد');
  assert.equal(timeline({writes})[0].data.title, 'اصلاح نتیجه پیگیری');
  assert.equal(audit({writes})[0].data.action, 'followups.edit');
  assert.equal(session.toast, 'نتیجه پیگیری ویرایش شد.');
});

test('an unknown action or a missing id is refused without touching a row', () => {
  for (const post of [{id: '11', matrix_action: 'delete'}, {id: '0', matrix_action: 'record', status: 'booked', result: 'x'}, {id: '11'}]) {
    const {writes, session} = route({}, MANAGER, {post});
    assert.deepEqual(updates({writes}), [], JSON.stringify(post));
    assert.ok(session.toast, 'the user is told what happened');
  }
});

test('a user with view-only rights sees no forms and cannot post', () => {
  const fixtures = {fixtures: VIEWER_FIXTURES};
  const page = route({}, VIEWER, fixtures);
  assert.equal(page.status, 200, 'viewing the matrix is still allowed');
  assert.doesNotMatch(page.body, /matrix_action/, 'no cell form is rendered');
  const posted = route({}, VIEWER, {...fixtures, post: {id: '21', matrix_action: 'record', status: 'booked', result: 'x'}});
  assert.equal(posted.status, 403, 'posting is refused');
  assert.deepEqual(posted.writes, [], 'and writes nothing');
});

// The redirect target itself is asserted over real HTTP in
// tests/followup-matrix-http.py: PHP's CLI SAPI has no response headers, so the
// harness cannot see a Location.
test('the pager keeps the search term so a saved row stays in reach', () => {
  const {body} = route({q: 'زهرا', page: '2'});
  assert.match(body, /q=%D8%B2%D9%87%D8%B1%D8%A7/, 'the search term survives pagination');
});
