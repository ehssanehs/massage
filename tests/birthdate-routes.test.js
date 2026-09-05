const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

function route(query = {}, module = 'customers') {
    const output = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify({ get: { r: module, ...query }, search_fixture: true })], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
    const result = JSON.parse(output);
    assert.equal(result.error, null, JSON.stringify(result.error));
    assert.notEqual(result.status, 500);
    assert.deepEqual(result.writes, [], 'filtering does not modify data');
    const ids = [...result.body.replaceAll('&amp;', '&').matchAll(new RegExp(`href="index.php\\?r=${module}\\.show&id=(\\d+)"`, 'g'))].map(match => +match[1]);
    return { ...result, ids };
}
const countQuery = result => result.queries.find(query => query.sql.startsWith('SELECT COUNT(*) FROM '));
const dataQuery = result => result.queries.find(query => query.sql.startsWith('SELECT customers.*') && query.sql.includes(' LIMIT 20 OFFSET '));
const bounds = { birth_from: '۱۳۶۹/۰۱/۰۱', birth_to: '۱۳۶۹/۰۱/۰۳' };
const input = (body, name) => body.match(new RegExp(`<input[^>]+name="${name}"[^>]*>`))[0];

test('customer list shows optional Jalali birth fields and a Jalali birth-date column without default filtering', () => {
    const result = route();
    assert.equal(result.status, 200);
    for (const name of ['birth_from', 'birth_to']) {
        const field = input(result.body, name);
        assert.match(field, /type="text"/);
        assert.match(field, /value=""/);
        assert.match(field, /data-jdp/);
        assert.doesNotMatch(field, /required/);
        assert.match(result.body, new RegExp(`<label[^>]+for="f_${name}"`));
    }
    assert.match(result.body, /<th>تاریخ تولد<\/th>/);
    assert.match(result.body, /۱۳۶۹\/۰۱\/۰۱/);
    assert.doesNotMatch(result.body, /1990-03-21/);
    assert.match(result.body, /تعداد: ۳۷/);
    assert.doesNotMatch(countQuery(result).sql, /birth_date/);
});

test('inclusive bounds filter before counting/pagination and combine with normalized name search', () => {
    const result = route({ ...bounds, q: 'علی' });
    assert.deepEqual(result.ids, [1], 'older Ali and deleted Ali are excluded');
    assert.match(result.body, /تعداد: ۱/);
    assert.match(result.body, /علي كاظمي/);
    const count = countQuery(result), data = dataQuery(result);
    assert.deepEqual(count.params, ['%علی%', '1990-03-21', '1990-03-23']);
    assert.deepEqual(data.params, count.params);
    assert.equal(count.sql.slice('SELECT COUNT(*) '.length), data.sql.slice(data.sql.lastIndexOf('FROM customers'), data.sql.lastIndexOf(' ORDER BY ')));
    assert.match(count.sql, /customers\.birth_date >= \?/);
    assert.match(count.sql, /customers\.birth_date <= \?/);
    const singleDay = route({ birth_from: '۱۳۶۹/۰۱/۰۲', birth_to: '۱۳۶۹/۰۱/۰۲' });
    assert.deepEqual(singleDay.ids, [2], 'the selected day is included and deleted ID 99 is not');
    const leap = route({ birth_from: '۱۴۰۳/۱۲/۳۰', birth_to: '۱۴۰۴/۰۱/۰۱' });
    assert.deepEqual(leap.ids, [9, 8], 'leap Esfand and next-year day one are both included');
    assert.deepEqual(countQuery(leap).params, ['2025-03-20', '2025-03-21']);
});

test('one-sided bounds work and NULL/empty/zero birth dates do not become matches', () => {
    const from = route({ birth_from: '۱۳۹۰/۰۱/۰۱' });
    assert.deepEqual(from.ids, [10, 9, 8]);
    assert.doesNotMatch(countQuery(from).sql, /birth_date <=/);
    const to = route({ birth_to: '۱۳۶۸/۱۲/۲۹' });
    assert.deepEqual(to.ids, [4, 0]);
    assert.doesNotMatch(countQuery(to).sql, /birth_date >=/);
    for (const id of [5, 6, 7, 99]) assert.ok(!to.ids.includes(id), `unknown/deleted ID ${id}`);
    const all = route({ birth_from: '', birth_to: '', q: "sara o'connor" });
    assert.deepEqual(all.ids, [6], 'clearing both dates includes unknown DOB records again');
    assert.match(all.body, /text-muted">—/);
    assert.doesNotMatch(countQuery(all).sql, /birth_date/);
});

test('both dates and q survive pagination, stale pages clamp, and reset removes every filter', () => {
    const result = route({ birth_from: '١٣٦٩-١-١', birth_to: '1369.1.3', q: 'آزمون صفحه', page: 999 });
    assert.deepEqual(result.ids, [105, 104, 103, 102, 101]);
    assert.match(result.body, /تعداد: ۲۵/);
    assert.match(dataQuery(result).sql, /LIMIT 20 OFFSET 20$/);
    for (const [, href] of result.body.matchAll(/class="page-link" href="([^"]+)"/g)) {
        const params = new URL(href.replaceAll('&amp;', '&'), 'https://example.invalid').searchParams;
        assert.equal(params.get('q'), 'آزمون صفحه');
        assert.equal(params.get('birth_from'), '۱۳۶۹/۰۱/۰۱');
        assert.equal(params.get('birth_to'), '۱۳۶۹/۰۱/۰۳');
    }
    const form = result.body.match(/<form method="get"[\s\S]*?<\/form>/)[0];
    assert.doesNotMatch(form, /name="page"/);
    assert.match(result.body, /href="index.php\?r=customers">پاک‌کردن فیلترها<\/a>/);
    const cleared = route();
    assert.match(cleared.body, /تعداد: ۳۷/);
    assert.match(dataQuery(cleared).sql, /OFFSET 0$/);
});

test('bad dates/reversed bounds/arrays block list queries while preserving input and escaping HTML', () => {
    for (const query of [
        { birth_from: '۱۴۰۴/۱۲/۳۰' }, { birth_to: '1369/07/31' }, { birth_from: ['1369/01/01'] },
        { birth_from: '1990-03-21' }, { birth_from: '1369/01/03', birth_to: '1369/01/01' },
        { birth_from: '<script>alert(1)</script>', q: 'علی' },
    ]) {
        const result = route(query);
        assert.equal(result.status, 400);
        assert.match(result.body, /alert-danger/);
        assert.match(result.body, /تاریخ تولد/);
        assert.equal(countQuery(result), undefined);
        assert.equal(dataQuery(result), undefined);
        assert.match(result.body, /پاک‌کردن فیلترها/);
        if (query.birth_from === '1990-03-21') assert.match(input(result.body, 'birth_from'), /value="1990-03-21"/);
        if (query.birth_from === '۱۴۰۴/۱۲/۳۰') assert.match(input(result.body, 'birth_from'), /value="۱۴۰۴\/۱۲\/۳۰"/);
        if (query.q) {
            assert.match(result.body, /value="علی"/);
            assert.match(input(result.body, 'birth_from'), /&lt;script&gt;/);
            assert.doesNotMatch(result.body, /<script>alert\(1\)<\/script>/);
        }
    }
    const mixed = route({ birth_from: 'bad', q: ['علی'] });
    assert.match(mixed.body, /عبارت جستجو باید متن معتبر/);
    assert.match(mixed.body, /تاریخ تولد از باید یک تاریخ شمسی معتبر/);
});

test('birth bounds are scoped to customers; an empty matching cohort displays a proper empty state', () => {
    const other = route({ birth_from: ['bad'], birth_to: 'bad' }, 'appointments');
    assert.equal(other.status, 200);
    assert.doesNotMatch(other.body, /name="birth_from"|name="birth_to"/);
    assert.doesNotMatch(countQuery(other).sql, /birth_date/);
    const empty = route({ birth_from: '۱۳۷۵/۰۱/۰۱', birth_to: '۱۳۷۶/۰۱/۰۱' });
    assert.deepEqual(empty.ids, []);
    assert.match(empty.body, /تعداد: ۰/);
    assert.match(empty.body, /رکوردی یافت نشد/);
});
