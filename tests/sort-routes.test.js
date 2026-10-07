const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const harness = path.join(__dirname, 'route-harness.php');
function route(r, params = {}) {
  return JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [harness, JSON.stringify({get: {r, ...params}})], {encoding: 'utf8'}));
}
function listQuery(result, table) {
  return result.queries.find(x => x.sql.startsWith(`SELECT ${table}.*`) && x.sql.includes('ORDER BY'))?.sql;
}
test('customer name sorts across all pages and header toggles direction with active filters', () => {
  const res = route('customers', {q: 'مریم', birth_month: '2', sort: 'full_name', dir: 'asc', page: '2'});
  assert.match(listQuery(res, 'customers'), /ORDER BY full_name ASC, customers\.id ASC LIMIT 20 OFFSET/);
  assert.match(res.body, /aria-sort="ascending"/);
  assert.match(res.body, /sort=full_name[^"<>]*dir=desc/);
  assert.match(res.body, /birth_month=2/);
  assert.match(res.body, /q=%D9%85/);
  assert.match(res.body, /name="sort" value="full_name"><input type="hidden" name="dir" value="asc"/);
});
test('joined customer and date sort safely in appointments and preserve deposit filter', () => {
  const byName = route('appointments', {sort:'customer_name', dir:'desc', deposit:'1'});
  assert.match(listQuery(byName, 'appointments'), /ORDER BY customer_name DESC, appointments\.id DESC/);
  assert.match(byName.body, /deposit=1/);
  const byDate = route('appointments', {sort:'appointment_date', dir:'asc'});
  assert.match(listQuery(byDate, 'appointments'), /ORDER BY appointments\.appointment_date ASC, appointments\.id ASC/);
});
test('numeric derived columns and joined columns are sortable', () => {
  assert.match(listQuery(route('customers', {sort:'total_spent', dir:'desc'}), 'customers'), /ORDER BY total_spent DESC, customers\.id DESC/);
  assert.match(listQuery(route('sessions', {sort:'service_name', dir:'asc'}), 'massage_sessions'), /ORDER BY service_name ASC, massage_sessions\.id ASC/);
});
test('rejects unlisted or malformed column and direction without SQL injection', () => {
  for (const input of [{sort:'id;DROP TABLE customers',dir:'asc'}, {sort:'mobile',dir:'asc;DROP'}, {sort:['mobile'],dir:'asc'}]) {
    const res = route('customers',input);
    assert.match(listQuery(res,'customers'), /ORDER BY customers\.id DESC LIMIT 20/);
    assert.doesNotMatch(listQuery(res,'customers'), /DROP|ORDER BY customers\.mobile/);
  }
});
test('default ordering and count query remain unchanged', () => {
  const res = route('expenses');
  assert.match(listQuery(res,'expenses'), /ORDER BY expenses\.id DESC LIMIT 20/);
  assert.ok(res.queries.some(x => x.sql.startsWith('SELECT COUNT(*) FROM expenses')));
  assert.doesNotMatch(res.body,/aria-sort="ascending"|aria-sort="descending"/);
});
