const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

function route(query = {}, module = 'customers', fixtures = {}) {
    const output = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify({ get: { r: module, ...query }, fixtures, search_fixture: true })], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
    const result = JSON.parse(output);
    assert.equal(result.error, null, JSON.stringify(result.error));
    assert.notEqual(result.status, 500);
    assert.deepEqual(result.writes, [], 'filtering does not modify data');
    const ids = [...result.body.replaceAll('&amp;', '&').matchAll(new RegExp(`href="index.php\\?r=${module}\\.show&id=(\\d+)"`, 'g'))].map(match => +match[1]);
    return { ...result, ids };
}
const countQuery = result => result.queries.find(query => query.sql.startsWith('SELECT COUNT(*) FROM '));
const dataQuery = result => result.queries.find(query => query.sql.startsWith('SELECT customers.*') && query.sql.includes(' LIMIT 20 OFFSET '));
const input = (body, name) => body.match(new RegExp(`<[^>]+name="${name}"[^>]*>`))[0];
const selectBlock = body => body.match(/<select[^>]+name="birth_month"[\s\S]*?<\/select>/)[0];

test('customer list shows a Jalali birth-month select and a Jalali birth-date column without default filtering', () => {
    const result = route();
    assert.equal(result.status, 200);
    const select = selectBlock(result.body);
    assert.match(select, /<option value="">— همهٔ ماه‌ها —<\/option>/);
    for (const name of ['فروردین', 'مهر', 'اسفند']) assert.match(result.body, new RegExp(`<option value="\\d+">${name}<\\/option>`));
    assert.doesNotMatch(result.body, /name="birth_from"|name="birth_to"/);
    assert.match(result.body, /<th>تاریخ تولد<\/th>/);
    assert.match(result.body, /۱۳۶۹\/۰۱\/۰۱/);
    assert.doesNotMatch(result.body, /1990-03-21/);
    assert.match(result.body, /تعداد: ۳۷/);
    assert.doesNotMatch(countQuery(result).sql, /birth_date/);
});

test('birth-month filter applies to counting/pagination and combines with normalized name search', () => {
    // Farvardin: ids 1,2,3,9,11 (plus page fixtures); Ali (id 1, "علي كاظمي") + deleted 99 excluded.
    const result = route({ birth_month: '1', q: 'علی' });
    assert.deepEqual(result.ids, [1]);
    assert.match(result.body, /تعداد: ۱/);
    const count = countQuery(result), data = dataQuery(result);
    assert.deepEqual(count.params, ['%علی%']);
    assert.deepEqual(data.params, count.params);
    assert.equal(count.sql.slice('SELECT COUNT(*) '.length), data.sql.slice(data.sql.lastIndexOf('FROM customers'), data.sql.lastIndexOf(' ORDER BY ')));
    assert.match(count.sql, /YEAR\(customers\.birth_date\)/);
    assert.match(count.sql, /MONTH\(customers\.birth_date\)/);
    assert.match(count.sql, /DAY\(customers\.birth_date\)/);
    assert.doesNotMatch(count.sql, /birth_date >=|birth_date <=/);
    // Persian digits work too.
    const fa = route({ birth_month: '۷' });
    assert.deepEqual(fa.ids, []);
    assert.match(fa.body, /تعداد: ۰/);
    assert.match(fa.body, /رکوردی یافت نشد/);
});

test('unknown/empty/zero birth dates never match a month filter', () => {
    // Dey (10): only id 0 (1985-01-01). NULL/''/zero ids 5,6,7 must be excluded.
    const dey = route({ birth_month: '10' });
    assert.deepEqual(dey.ids, [0]);
    // Esfand (12): ids 4,8,10 (+ boundary id 1) plus 25 page fixtures (1990-03-21).
    // Unknown ids 5,6,7 still out. 30 matches over 2 pages: page 1 has 20.
    const esfand = route({ birth_month: '12' });
    assert.match(esfand.body, /تعداد: ۳۰/);
    for (const id of [5, 6, 7, 99]) assert.ok(!esfand.ids.includes(id), `unknown/deleted ID ${id}`);
    const esfandPage2 = route({ birth_month: '12', page: 2 });
    assert.ok(esfandPage2.ids.includes(10));
    assert.ok(esfandPage2.ids.includes(4), 'boundary Esfand birthday on page 2');
    // Clearing the month includes unknown DOB records again.
    const all = route({ birth_month: '', q: "sara o'connor" });
    assert.deepEqual(all.ids, [6], 'clearing the month includes unknown DOB records again');
    assert.match(all.body, /text-muted">—/);
    assert.doesNotMatch(countQuery(all).sql, /birth_date/);
});

test('month and q survive pagination, stale pages clamp, and reset removes every filter', () => {
    const result = route({ birth_month: '1', q: 'آزمون صفحه', page: 999 });
    assert.deepEqual(result.ids, [105, 104, 103, 102, 101]);
    assert.match(result.body, /تعداد: ۲۵/);
    assert.match(dataQuery(result).sql, /LIMIT 20 OFFSET 20$/);
    for (const [, href] of result.body.matchAll(/class="page-link" href="([^"]+)"/g)) {
        const params = new URL(href.replaceAll('&amp;', '&'), 'https://example.invalid').searchParams;
        assert.equal(params.get('q'), 'آزمون صفحه');
        assert.equal(params.get('birth_month'), '1');
        assert.ok(!params.has('birth_from') && !params.has('birth_to'), 'no legacy range params in pager');
    }
    const form = result.body.match(/<form method="get"[\s\S]*?<\/form>/)[0];
    assert.doesNotMatch(form, /name="page"/);
    assert.match(result.body, /href="index.php\?r=customers">پاک‌کردن فیلترها<\/a>/);
    const cleared = route();
    assert.match(cleared.body, /تعداد: ۳۷/);
    assert.match(dataQuery(cleared).sql, /OFFSET 0$/);
});

test('bad months/arrays block list queries while preserving selection safely', () => {
    for (const query of [
        { birth_month: '0' }, { birth_month: '13' }, { birth_month: 'abc' },
        { birth_month: 'مهر' }, { birth_month: ['7'] },
        { birth_month: '<script>alert(1)</script>', q: 'علی' },
    ]) {
        const result = route(query);
        if (Array.isArray(query.birth_month)) {
            // Array input is ignored, like other filters — the list still renders.
            assert.equal(result.status, 200);
            continue;
        }
        assert.equal(result.status, 400);
        assert.match(result.body, /alert-danger/);
        assert.match(result.body, /ماه تولد/);
        assert.equal(countQuery(result), undefined);
        assert.equal(dataQuery(result), undefined);
        assert.match(result.body, /پاک‌کردن فیلترها/);
        if (query.q) {
            assert.match(result.body, /value="علی"/);
            assert.doesNotMatch(result.body, /<script>alert\(1\)<\/script>/);
        }
    }
    const mixed = route({ birth_month: 'bad', q: ['علی'] });
    assert.match(mixed.body, /عبارت جستجو باید متن معتبر/);
    assert.match(mixed.body, /ماه تولد باید یکی از ماه‌های ۱ تا ۱۲ باشد/);
});

test('birth-month filter is scoped to customers', () => {
    const other = route({ birth_month: 'bad' }, 'appointments');
    assert.equal(other.status, 200);
    assert.doesNotMatch(other.body, /name="birth_month"/);
    assert.doesNotMatch(countQuery(other).sql, /birth_date/);
});
