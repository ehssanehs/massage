const {test} = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

const TYPES = [
  {id: 1, slug: 'relax', title: 'ماساژ ریلکسی', summary: 'آرام‌سازی بدن', body: 'متن کامل ریلکسی',
   illustration: 'relax', duration_minutes: 60, price_from: null, sort_order: 10},
  {id: 2, slug: 'hot-stone', title: 'ماساژ سنگ داغ', summary: 'گرمای سنگ', body: 'متن کامل سنگ داغ',
   illustration: 'hot-stone', duration_minutes: 75, price_from: null, sort_order: 20},
];

const BLOCKS = {
  'home.hero.title': {key: 'home.hero.title', value: 'تیتر دلخواه مدیر', group_name: 'home', type: 'text'},
  'home.hero.lead': {key: 'home.hero.lead', value: 'متن معرفی دلخواه', group_name: 'home', type: 'textarea'},
  'centers.men.body': {key: 'centers.men.body', value: 'توضیح آقایان', group_name: 'centers', type: 'textarea'},
  'contact.phone': {key: 'contact.phone', value: '02100000000', group_name: 'contact', type: 'text'},
};

function route(get, post, options = {}) {
  const input = {get, session: options.session ?? {_csrf: 'test-only-csrf-token'}};
  if (post !== undefined) input.post = post;
  if (options.site !== undefined) input.site = options.site;
  if (options.fixtures !== undefined) input.fixtures = options.fixtures;
  if (options.scriptName) input.script_name = options.scriptName;
  const result = JSON.parse(execFileSync(process.env.PHP_BIN || 'php',
    [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)],
    {cwd: path.join(__dirname, '..'), encoding: 'utf8'}));
  assert.equal(result.error, null, JSON.stringify(result.error));
  return result;
}

const ADMIN = {uid: 1, _csrf: 'test-only-csrf-token'};
const withSite = {site: {blocks: BLOCKS, types: TYPES}};
const adminSite = {...withSite, session: ADMIN};

test('home renders the hero, both centers and the catalog from site content', () => {
  const {status, body, queries} = route({r: 'home'}, undefined, withSite);
  assert.equal(status, 200);
  assert.match(body, /تیتر دلخواه مدیر/);
  assert.match(body, /متن معرفی دلخواه/);
  assert.match(body, /مرکز ماساژ آقایان/);
  assert.match(body, /مرکز ماساژ بانوان/);
  assert.match(body, /ماساژ ریلکسی/);
  assert.match(body, /ماساژ سنگ داغ/);
  assert.equal(queries.length, 0, 'the public page must not query a CRM database');
});

test('home hides blocks the owner left empty instead of printing placeholders', () => {
  const {body} = route({r: 'home'}, undefined, {site: {blocks: {}, types: TYPES}});
  assert.doesNotMatch(body, /hero-lead/, 'an empty lead must not render an empty paragraph');
  assert.doesNotMatch(body, /site-footer-phone/, 'no phone link until a phone is entered');
});

test('an empty catalog shows an empty state, not a broken grid', () => {
  const {status, body} = route({r: 'home'}, undefined, {site: {blocks: {}, types: []}});
  assert.equal(status, 200);
  assert.match(body, /فهرست ماساژها هنوز وارد نشده است/);
  assert.doesNotMatch(body, /type-card-title/);

  const services = route({r: 'massage-types'}, undefined, {site: {blocks: {}, types: []}});
  assert.equal(services.status, 200);
  assert.match(services.body, /هنوز نوع ماساژی ثبت نشده است/);
});

test('the services page lists every active type and links to its page', () => {
  const {status, body} = route({r: 'massage-types'}, undefined, withSite);
  assert.equal(status, 200);
  assert.equal((body.match(/class="type-card"/g) || []).length, TYPES.length);
  assert.match(body, /r=massage-type&amp;slug=relax/);
  assert.match(body, /۶۰ دقیقه/, 'duration is shown in Persian digits');
});

test('a catalog page renders the full description, an unknown slug is a 404', () => {
  const found = route({r: 'massage-type', slug: 'hot-stone'}, undefined, withSite);
  assert.equal(found.status, 200);
  assert.match(found.body, /متن کامل سنگ داغ/);
  assert.match(found.body, /۷۵ دقیقه/);

  const missing = route({r: 'massage-type', slug: 'nope'}, undefined, withSite);
  assert.equal(missing.status, 404);
  assert.match(missing.body, /این صفحه پیدا نشد/);
  assert.match(missing.body, /r=massage-types/, 'the 404 offers a way back');
});

test('the old public section routes keep working', () => {
  const massage = route({r: 'public.massage'}, undefined, withSite);
  assert.equal(massage.status, 200);
  assert.match(massage.body, /type-card/);

  const education = route({r: 'public.education'}, undefined, withSite);
  assert.equal(education.status, 200);
  assert.match(education.body, /بخش آموزش/);
});

test('an authenticated staff member is sent to the dashboard instead of the public site', () => {
  const {status, session} = route({r: 'home'}, undefined, {...withSite, session: {uid: 1}});
  assert.equal(status, 302);
  assert.ok(session);
});

test('the content panel requires settings.manage and saves only known blocks', () => {
  const allowed = route({r: 'site-content'}, undefined, adminSite);
  assert.equal(allowed.status, 200);
  assert.match(allowed.body, /محتوای سایت عمومی/);

  const denied = route({r: 'site-content'}, undefined, {
    ...adminSite,
    fixtures: {users: {role_slug: 'staff'}, role_permissions: '["customers.view"]'},
  });
  assert.equal(denied.status, 403, 'a user without settings.manage cannot open the panel');

  const saved = route({r: 'site-content'}, {blocks: {'home.hero.title': 'تیتر تازه', 'not.a.block': 'x'}}, adminSite);
  assert.equal(saved.status, 302, 'a successful save redirects back to the panel');
  const writes = saved.site_writes ?? [];
  assert.equal(writes.length, 1, 'an unknown key must not be written');
  assert.deepEqual(writes[0], {key: 'home.hero.title', value: 'تیتر تازه'});
});

test('the catalog module is guarded by the services permission', () => {
  assert.equal(route({r: 'site_types'}, undefined, adminSite).status, 200);
  const denied = route({r: 'site_types'}, undefined, {
    ...adminSite,
    fixtures: {users: {role_slug: 'staff'}, role_permissions: '["customers.view"]'},
  });
  assert.equal(denied.status, 403);
});
