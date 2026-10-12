const {test} = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

function route(get, post, session = {}, tenants = null) {
  const input = {get, session};
  if (post !== undefined) input.post = post;
  if (tenants) input.tenants = tenants;
  const result = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)], {cwd:path.join(__dirname, '..'), encoding:'utf8'}));
  assert.equal(result.error, null, JSON.stringify(result.error));
  return result;
}
const BOTH = {men:'test_men', women:'test_women'};

test('public root presents massage, education and staff entry without opening a CRM database', () => {
  const result = route({r:'home'}, undefined, {}, BOTH);
  assert.equal(result.status, 200);
  assert.match(result.body, /خدمات ماساژ/);
  assert.match(result.body, /بخش آموزش/);
  assert.match(result.body, /ورود پرسنل/);
  assert.match(result.body, /href="\/index\.php\?r=public\.massage"/);
  assert.match(result.body, /href="\/index\.php\?r=public\.education"/);
  assert.match(result.body, /href="\/index\.php\?r=login"/);
  assert.equal(result.queries.length, 0, 'the landing page must not touch a CRM database');
});

test('each public section page is reachable and still opens no database', () => {
  for (const [key, title] of [['massage','خدمات ماساژ'],['education','بخش آموزش']]) {
    const page = route({r:`public.${key}`}, undefined, {}, BOTH);
    assert.equal(page.status, 200);
    assert.match(page.body, new RegExp(title));
    assert.match(page.body, /href="\/index\.php\?r=home"/, 'section pages link back home');
    assert.equal(page.queries.length, 0, 'public section pages must not touch a CRM database');
  }
});

test('each section owns its own database and prefixes its own URLs', () => {
  for (const section of ['men', 'women']) {
    const page = route({r:'customers'}, undefined, {uid:1, tenant:section, _csrf:'t'}, BOTH).body;
    assert.match(page, new RegExp(`"/${section}/index\\.php\\?r=customers\\.create"`), `${section} keeps its own prefix`);
    assert.match(page, /"\/assets\/css\/app\.css"/, 'shared assets stay absolute');
  }
});

test('staff login demands a section and remembers the chosen one', () => {
  const missing = route({r:'login'}, {email:'a@b.c', password:'x', _probe:'p'}, {_csrf:'t', _login_probe:'p'}, BOTH);
  assert.match(missing.body, /بخش آقایان یا خانم‌ها را انتخاب کنید/);
  const chosen = route({r:'login'}, {tenant:'women', email:'a@b.c', password:'wrong', _probe:'p'}, {_csrf:'t', _login_probe:'p'}, BOTH);
  assert.equal(chosen.session.tenant, 'women');
  assert.match(chosen.body, /ایمیل یا رمز عبور اشتباه است/);
  const form = route({r:'login'}, undefined, {}, BOTH).body;
  assert.match(form, /name="tenant"/, 'a two-section install offers the section selector');
});

test('an unknown section is refused instead of silently selecting a database', () => {
  const result = route({r:'login'}, {tenant:'../../etc', email:'a@b.c', password:'x', _probe:'p'}, {_csrf:'t', _login_probe:'p'}, BOTH);
  assert.match(result.body, /انتخاب بخش/);
  assert.equal(result.session.tenant, undefined);
});

test('switching section on an existing session drops the previous login', () => {
  const result = route({r:'dashboard'}, undefined, {uid:7, tenant:'women', _csrf:'t'}, BOTH, );
  assert.equal(result.session.uid, 7);
  const switched = route({r:'dashboard'}, undefined, {uid:7, tenant:'men', _csrf:'t'});
  assert.equal(switched.session.uid, 7, 'single-section installs never switch');
});

test('a section URL redirects into that section when no session exists yet', () => {
  const result = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify({get:{}, session:{}, tenants:BOTH, request_uri:'/women'})], {cwd:path.join(__dirname, '..'), encoding:'utf8'}));
  assert.equal(result.status, 302);
  assert.equal(result.session.tenant, 'women');
});
