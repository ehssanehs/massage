const {test} = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

// The JSON endpoint behind the search box on relational selects.
function lookup(query, session = {uid: 1, tenant: 'men', _csrf: 't'}, fixtures = {}) {
  const result = JSON.parse(execFileSync(process.env.PHP_BIN || 'php',
    [path.join(__dirname, 'route-harness.php'), JSON.stringify({get: {r: 'lookup', ...query}, session, fixtures})],
    {cwd: path.join(__dirname, '..'), encoding: 'utf8'}));
  assert.equal(result.error, null, JSON.stringify(result.error));
  let body = [];
  try { body = JSON.parse(result.body); } catch (e) { body = null; }
  return {status: result.status, body, raw: result.body};
}

test('lookup requires a signed-in user', () => {
  const {status} = lookup({type: 'customer', q: 'مشتری'}, {});
  assert.equal(status, 302, 'an anonymous request is redirected to the login page');
});

test('lookup refuses unknown types and short queries', () => {
  assert.equal(lookup({type: 'nonsense', q: 'مشتری'}).status, 400);
  assert.deepEqual(lookup({type: 'customer', q: 'م'}).body, [], 'a one-character query returns nothing');
});

test('lookup returns id and label for a real query', () => {
  const {status, body} = lookup({type: 'customer', q: 'مشتری'});
  assert.equal(status, 200);
  assert.ok(Array.isArray(body), 'the response is a JSON array');
  assert.ok(body.length >= 1);
  assert.ok('id' in body[0] && 'label' in body[0], 'each row carries id and label');
});

test('lookup is scoped to the permission of the requested list', () => {
  const staff = {users: {role_slug: 'staff'}, role_permissions: '["customers.view"]'};
  assert.equal(lookup({type: 'customer', q: 'مشتری'}, {uid: 1, tenant: 'men', _csrf: 't'}, staff).status, 200);
  assert.equal(lookup({type: 'therapist', q: 'درمانگر'}, {uid: 1, tenant: 'men', _csrf: 't'}, staff).status, 403,
    'a user without therapists.view cannot enumerate therapists');
});

test('the like pattern is escaped so a query cannot become a wildcard', () => {
  const {body} = lookup({type: 'customer', q: '100%_x'});
  assert.ok(Array.isArray(body), 'wildcard characters are treated literally and still answer');
});
