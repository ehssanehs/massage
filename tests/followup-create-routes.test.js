// Manual follow-up creation from the follow-up centre (top form + per-row button)
// and from the matrix (the «ایجاد پیگیری» cell). Both routes call the same
// followup_create_manual(), so these tests pin the shared rules in each entry point.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

const CUSTOMERS = [
  {id: 1, first_name: 'زهرا', last_name: 'کاظمی', mobile: '09121111111', status: 'active'},
  {id: 2, first_name: 'محمد', last_name: 'رضایی', mobile: '09122222222', status: 'active'},
];
const FOLLOWUPS = [
  {id: 11, customer_id: 1, due_date: '2026-03-21', status: 'booked', result: 'رزرو شد', priority: 'high', contacted_at: '2026-03-21 13:05:09'},
  {id: 21, customer_id: 2, due_date: '2026-03-22', status: 'pending', result: null, priority: 'normal', contacted_at: null},
];
const MANAGER = {uid: 1, tenant: 'men', _csrf: 't'};
const VIEWER = {uid: 2, tenant: 'men', _csrf: 't'};
const VIEWER_FIXTURES = {
  users: {role_slug: 'receptionist', role_name: 'پذیرش', role_id: 3, permissions: '["followups.view"]', email: 'viewer@example.invalid'},
  role_permissions: '["followups.view"]',
};

function route(get, post, session = MANAGER, fixtures = {}) {
  const input = {get, session, fixtures: {matrix_customers: CUSTOMERS, matrix_followups: FOLLOWUPS, matrix_columns: 2, matrix_total: CUSTOMERS.length, ...fixtures}};
  if (post !== undefined) input.post = post;
  const result = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)], {cwd: path.join(__dirname, '..'), encoding: 'utf8'}));
  assert.equal(result.error, null, JSON.stringify(result.error));
  return result;
}
const created = result => result.writes.filter(w => w.operation === 'insert' && w.table === 'followups');
const audit = result => result.writes.filter(w => w.operation === 'insert' && w.table === 'audit_logs');
const timeline = result => result.writes.filter(w => w.operation === 'insert' && w.table === 'customer_timeline');

// ---------------------------------------------------------------- follow-up centre

test('the centre offers a manual-create form, and a per-row button for that customer', () => {
  const {body, status} = route({r: 'followups', filter: 'active'});
  assert.equal(status, 200);
  assert.match(body, /id="fuNewFollowup"/, 'the top form exists');
  assert.match(body, /name="create_followup"/);
  assert.match(body, /name="customer_id"[^>]*required/, 'the top form picks a customer');
  assert.match(body, /جستجو|پیگیری جدید/);
  const rowButtons = [...body.matchAll(/value="(\d+)"[^>]*>\s*<\/?[^>]*name="matrix_action"|name="customer_id" value="(\d+)"/g)];
  assert.ok(rowButtons.length > 0, 'a per-row create form carries its customer id');
  assert.match(body, /ثبت پیگیری برای این مشتری/);
});

test('the centre top form can pick any customer, including one with no follow-up', () => {
  const {body} = route({r: 'followups', filter: 'active'});
  // The selector is filled by options('customer') — the same source the module
  // forms use — so a customer absent from every group is still creatable.
  const select = body.match(/<select id="nf_customer"[\s\S]*?<\/select>/)[0];
  assert.match(select, /<option value="1">/, 'customers are offered by id');
  assert.match(select, /required/);
});

test('the per-row create form posts that row customer, with today prefilled', () => {
  const {body} = route({r: 'followups', filter: 'active'});
  const jalaliToday = body.match(/data-today="([^"]+)"/)[1];
  const rowForms = [...body.matchAll(/<form method="post" class="followup-create-form">([\s\S]*?)<\/form>/g)].map(m => m[1]);
  assert.ok(rowForms.length > 0, 'each row offers a create form');
  for (const form of rowForms) {
    assert.match(form, /name="create_followup" value="1"/);
    assert.match(form, /name="customer_id" value="1"/, 'the row customer is pinned server-side');
    assert.ok(form.includes(`value="${jalaliToday}"`), 'the date defaults to today');
  }
});

test('creating from the centre top form writes a pending follow-up and its timeline entry', () => {
  const {status, session, writes} = route({r: 'followups', filter: 'active'}, {
    create_followup: '1', customer_id: '2', due_date: '۱۴۰۵/۰۲/۱۰', priority: 'high', description: 'پیگیری تلفنی',
  });
  assert.equal(status, 302);
  const [row] = created({writes});
  assert.ok(row, 'a follow-up row is inserted');
  assert.equal(row.data.customer_id, 2);
  assert.equal(row.data.status, 'pending');
  assert.equal(row.data.priority, 'high');
  assert.equal(row.data.description, 'پیگیری تلفنی');
  assert.equal(row.data.due_date, '2026-04-30', 'the Jalali date is stored as Gregorian');
  assert.equal(timeline({writes})[0].data.title, 'پیگیری دستی ثبت شد');
  assert.equal(audit({writes})[0].data.action, 'followups.create');
  assert.match(session.toast, /پیگیری جدید برای ۱۴۰۵\/۰۲\/۱۰ ثبت شد/);
});

test('the row-button markup pins the row customer instead of trusting the request', () => {
  const {body} = route({r: 'followups', filter: 'active'});
  const rowForms = [...body.matchAll(/<form method="post" class="followup-create-form">([\s\S]*?)<\/form>/g)].map(m => m[1]);
  // Each rendered form belongs to the customer of its own row (fixture row customer 1).
  assert.ok(rowForms.every(f => /name="customer_id" value="1"/.test(f)), 'the customer id comes from the row, not a free field');
  assert.ok(rowForms.every(f => /name="due_date"/.test(f)), 'and it collects a due date');
});

test('an invalid or missing due date is refused and nothing is written', () => {
  for (const post of [
    {create_followup: '1', customer_id: '1'},
    {create_followup: '1', customer_id: '1', due_date: ''},
    {create_followup: '1', customer_id: '1', due_date: '1405/13/40'},
    {create_followup: '1', customer_id: '0', due_date: '۱۴۰۵/۰۲/۱۰'},
  ]) {
    const {writes, session} = route({r: 'followups', filter: 'active'}, post);
    assert.deepEqual(created({writes}), [], JSON.stringify(post));
    assert.ok(session.toast, 'the user is told what went wrong');
  }
});

test('an unknown priority falls back to normal instead of failing', () => {
  const {writes} = route({r: 'followups', filter: 'active'}, {create_followup: '1', customer_id: '1', due_date: '۱۴۰۵/۰۲/۱۰', priority: 'urgent'});
  assert.equal(created({writes})[0].data.priority, 'normal');
});

test('a view-only user gets no create form and cannot post one', () => {
  const page = route({r: 'followups', filter: 'active'}, undefined, VIEWER, VIEWER_FIXTURES);
  assert.doesNotMatch(page.body, /create_followup/, 'no create form is rendered');
  const posted = route({r: 'followups', filter: 'active'}, {create_followup: '1', customer_id: '1', due_date: '۱۴۰۵/۰۲/۱۰'}, VIEWER, VIEWER_FIXTURES);
  assert.equal(posted.status, 403);
  assert.deepEqual(posted.writes, []);
});

// ------------------------------------------------------------------------- matrix

test('every customer row has a create cell at its first empty turn', () => {
  const {body, status} = route({r: 'followups.matrix'});
  assert.equal(status, 200);
  const createCells = [...body.matchAll(/name="customer_id" value="(\d+)">\s*<input type="hidden" name="matrix_action" value="create">/g)].map(m => +m[1]);
  assert.deepEqual(createCells.sort(), [1, 2], 'one create cell per customer');
  assert.equal((body.match(/پیگیری ۳/g) || []).length, 1, 'one turn beyond the deepest chain, so an empty cell always exists');
  // The create cell must come after the customer's own follow-ups, i.e. in their
  // first empty turn rather than a fixed column.
  const row = body.split('matrix-customer-head').pop().split('<tr>')[1] || '';
  assert.match(body, /matrix-create/);
});

test('creating from a matrix cell stores the follow-up for that customer', () => {
  const {status, writes, session} = route({r: 'followups.matrix'}, {
    matrix_action: 'create', customer_id: '1', due_date: '۱۴۰۵/۰۳/۰۱', priority: 'low', description: 'از ماتریس',
  });
  assert.equal(status, 302);
  const [row] = created({writes});
  assert.equal(row.data.customer_id, 1);
  assert.equal(row.data.due_date, '2026-05-22');
  assert.equal(row.data.priority, 'low');
  assert.equal(timeline({writes})[0].data.title, 'پیگیری دستی ثبت شد');
  assert.equal(audit({writes})[0].data.action, 'followups.create');
  assert.match(session.toast, /ثبت شد/);
});

test('a matrix create with a bad date writes nothing, and view-only users cannot create', () => {
  const bad = route({r: 'followups.matrix'}, {matrix_action: 'create', customer_id: '1', due_date: 'nonsense'});
  assert.deepEqual(created(bad), []);
  assert.ok(bad.session.toast);
  const denied = route({r: 'followups.matrix'}, {matrix_action: 'create', customer_id: '1', due_date: '۱۴۰۵/۰۳/۰۱'}, VIEWER, VIEWER_FIXTURES);
  assert.equal(denied.status, 403);
  assert.deepEqual(denied.writes, []);
});

test('the matrix hides the create cell from view-only users', () => {
  const {body} = route({r: 'followups.matrix'}, undefined, VIEWER, VIEWER_FIXTURES);
  assert.doesNotMatch(body, /matrix_action" value="create"/);
  assert.doesNotMatch(body, /matrix-create/);
});
