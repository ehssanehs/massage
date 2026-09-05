const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

// Only auth/settings stay stubbed. The actual list/count SQL executes on SQLite
// with CONCAT_WS/REGEXP_REPLACE adapters and deliberately case-sensitive LIKE.
function route(module, q, page) {
    const get = { r: module };
    if (q !== undefined) get.q = q;
    if (page !== undefined) get.page = page;
    const output = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify({ get, search_fixture: true })], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
    const result = JSON.parse(output);
    assert.equal(result.error, null, JSON.stringify(result.error));
    assert.notEqual(result.status, 500);
    assert.deepEqual(result.writes, [], 'search is read-only');
    const links = result.body.replaceAll('&amp;', '&');
    const ids = [...links.matchAll(new RegExp(`href="index.php\\?r=${module}\\.show&id=(\\d+)"`, 'g'))].map(match => +match[1]);
    return { ...result, ids };
}
const countQuery = result => result.queries.find(query => query.sql.startsWith('SELECT COUNT(*) FROM '));
const dataQuery = result => result.queries.find(query => /^SELECT \w+\.\*/.test(query.sql) && query.sql.includes(' LIMIT 20 OFFSET '));

test('customer searches find full/reversed/compact names and preserve legacy spellings', () => {
    for (const q of ['علی کاظمی', 'كاظمي علي', 'علیکاظمی']) {
        const result = route('customers', q);
        assert.deepEqual(result.ids, [1], q);
        assert.match(result.body, /علي كاظمي/);
        assert.match(result.body, /تعداد: ۱/);
        assert.ok(!result.ids.includes(99), 'deleted customer must never be returned');
    }
    assert.deepEqual(route('customers', 'محمد رضا نیک فر').ids, [3, 2]);
    assert.deepEqual(route('customers', 'محمد‌رضا نیکفر').ids, [3, 2]);
    assert.deepEqual(route('customers', 'علی رضایی').ids, [4]);
    assert.deepEqual(route('customers', 'ساراسادات مهدوی').ids, [5]);
    assert.deepEqual(route('customers', 'شریفی').ids, [7], 'NULL first name does not hide a last name');
});

test('phone/code searches support Persian/Arabic digits, case, zero and literal wildcards', () => {
    assert.deepEqual(route('customers', '۰۹۱۲۱۲۳۴۵۶۷').ids, [1]);
    assert.deepEqual(route('customers', '٠٩٣٥١٢٣٤٥٦٧').ids, [9]);
    assert.deepEqual(route('customers', 'c-001').ids, [1]);
    assert.deepEqual(route('customers', 'C_08%!').ids, [8]);
    const zero = route('customers', '0', 999); // The ID-zero fixture is on the last page of matches.
    assert.ok(zero.ids.includes(0));
    assert.ok(!zero.ids.includes(6), 'zero is not ignored');
    assert.deepEqual(countQuery(zero).params, ['%0%']);
});

test('joined customer/therapist/service names participate in both data and count queries', () => {
    for (const module of ['appointments', 'sessions', 'packages']) {
        const expected = { appointments: [1], sessions: [11], packages: [21] }[module];
        const result = route(module, 'علی کاظمی');
        assert.deepEqual(result.ids, expected, module);
        const count = countQuery(result), data = dataQuery(result);
        assert.match(count.sql, /LEFT JOIN customers c/);
        assert.deepEqual(count.params, data.params);
        const table = { appointments: 'appointments', sessions: 'massage_sessions', packages: 'customer_packages' }[module];
        const suffix = data.sql.slice(data.sql.indexOf(`FROM ${table}`), data.sql.lastIndexOf(' ORDER BY '));
        assert.equal(count.sql, `SELECT COUNT(*) ${suffix}`, 'same joins, soft-delete scope and predicate');
        assert.match(result.body, /تعداد: ۱/);
    }
    assert.deepEqual(route('appointments', 'آرش کریمی').ids, [2, 1]);
    assert.deepEqual(route('sessions', 'ماساژ سویدی').ids, [11]);
    assert.deepEqual(route('packages', '۰۹۳۵۱۲۳۴۵۶۷').ids, [22]);
    assert.deepEqual(route('appointments', 'بدون رابطه').ids, [3], 'LEFT JOIN preserves orphan/base-text matches');
});

test('other list modules use the same Persian and literal-substring search rules', () => {
    assert.deepEqual(route('therapists', 'آرش کریمی').ids, [1]);
    assert.deepEqual(route('inventory', 'روغن کنجد').ids, [31]);
    assert.deepEqual(route('services', 'ماساژ سوی').ids, [1]);
    assert.deepEqual(route('services', '100%_firm!').ids, [2]);
});

test('search count, preserved pagination query, stale pages and reset link stay consistent', () => {
    const result = route('customers', 'آزمون صفحه', 999);
    assert.equal(result.ids.length, 5);
    assert.deepEqual(result.ids, [105, 104, 103, 102, 101]);
    assert.match(result.body, /تعداد: ۲۵/);
    assert.match(dataQuery(result).sql, /LIMIT 20 OFFSET 20$/);
    assert.match(result.body, /page-item active[^>]*><a[^>]*>۲<\/a>/);
    const links = [...result.body.matchAll(/class="page-link" href="([^"]+)"/g)];
    assert.ok(links.length > 0);
    for (const [, href] of links) assert.equal(new URL(href.replaceAll('&amp;', '&'), 'https://example.invalid').searchParams.get('q'), 'آزمون صفحه');
    assert.match(result.body, /href="index.php\?r=customers">پاک‌کردن جستجو<\/a>/);
    const form = result.body.match(/<form method="get"[\s\S]*?<\/form>/)[0];
    assert.doesNotMatch(form, /name="page"/, 'new search starts at the first page');
    const cleared = route('customers');
    assert.equal(cleared.ids.length, 20);
    assert.match(cleared.body, /تعداد: ۳۷/);
    assert.doesNotMatch(cleared.body, /پاک‌کردن جستجو/);
});

test('bad queries fail safely without list SQL, and unmatched/XSS input stays escaped', () => {
    for (const q of [['علی'], 'ا'.repeat(301), Array.from({ length: 13 }, (_, n) => `${n}`).join(' '), 'bad\u001fquery']) {
        const result = route('customers', q);
        assert.equal(result.status, 400);
        assert.match(result.body, /alert-danger/);
        assert.equal(countQuery(result), undefined);
        assert.equal(dataQuery(result), undefined);
    }
    const result = route('customers', '<script>alert(1)</script>');
    assert.deepEqual(result.ids, []);
    assert.match(result.body, /value="&lt;script&gt;alert\(1\)&lt;\/script&gt;"/);
    assert.doesNotMatch(result.body, /<script>alert\(1\)<\/script>/);
    assert.match(result.body, /تعداد: ۰/);
    assert.match(result.body, /رکوردی یافت نشد/);
});
