const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

// Set PHP_BIN when PHP is installed outside PATH. Only the DB is stubbed.
function route(get, post) {
    const input = { get };
    if (post !== undefined) input.post = post;
    const output = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
    const result = JSON.parse(output);
    assert.equal(result.error, null, JSON.stringify(result.error));
    assert.notEqual(result.status, 500);
    return result;
}

const dateFields = {
    customers: ['birth_date'],
    therapists: ['hire_date'],
    appointments: ['appointment_date'],
    sessions: ['massage_date', 'recommended_next_visit_date'],
    expenses: ['expense_date'],
    packages: ['starts_at', 'expires_at'],
    campaigns: ['starts_at', 'ends_at'],
};

test('all module edit forms and record views render stored Gregorian dates as Jalali', () => {
    for (const [module, fields] of Object.entries(dateFields)) {
        const edit = route({ r: `${module}.edit`, id: 1 });
        for (const field of fields) {
            assert.match(edit.body, new RegExp(`<input[^>]+name="${field}"[^>]+value="۱۴۰۵/۰۱/۰۱"[^>]+data-jdp`), `${module}.${field}`);
        }
        assert.doesNotMatch(edit.body, /type="date"/);
        assert.doesNotMatch(edit.body, /value="2026-03-21"/);
        const show = route({ r: `${module}.show`, id: 1 });
        assert.match(show.body, /۱۴۰۵\/۰۱\/۰۱/);
        assert.doesNotMatch(show.body, /2026-03-21/);
    }
});

test('invalid required/optional dates are rejected without writes and redisplayed intact', () => {
    const customer = { first_name: 'مشتری', last_name: 'آزمایشی', mobile: '09120000000', birth_date: '1404/12/30' };
    for (const r of ['customers.create', 'customers.edit']) {
        const result = route({ r, id: 1 }, customer);
        assert.equal(result.writes.length, 0);
        assert.match(result.body, /تاریخ تولد باید یک تاریخ شمسی معتبر/);
        assert.match(result.body, /value="1404\/12\/30"/);
    }
    const required = route({ r: 'appointments.create' }, { appointment_date: '۱۴۰۵/۰۷/۳۱' });
    assert.equal(required.writes.length, 0);
    assert.match(required.body, /تاریخ باید یک تاریخ شمسی معتبر/);
    const arrayDate = route({ r: 'customers.create' }, { ...customer, birth_date: ['1405/01/01'] });
    assert.equal(arrayDate.writes.length, 0);
    assert.match(arrayDate.body, /تاریخ شمسی معتبر/);
    const badGregorian = route({ r: 'customers.create' }, { ...customer, birth_date: '2026-03-21' });
    assert.equal(badGregorian.writes.length, 0);
    assert.match(badGregorian.body, /value="2026-03-21"/);
});

test('hyphenated Jalali posts survive validation errors and save Gregorian dates exactly once', () => {
    const redisplay = route({ r: 'customers.create' }, { birth_date: '۱۴۰۵-۰۱-۰۱' });
    assert.match(redisplay.body, /value="۱۴۰۵\/۰۱\/۰۱"/);
    assert.doesNotMatch(redisplay.body, /۰۷۸/);
    for (const r of ['customers.create', 'customers.edit']) {
        const saved = route({ r, id: 1 }, { first_name: 'مشتری', last_name: 'آزمایشی', mobile: '09120000000', birth_date: '۱۴۰۵-۰۱-۰۱' });
        assert.equal(saved.writes.find(w => w.table === 'customers').data.birth_date, '2026-03-21');
    }
    const blank = route({ r: 'customers.create' }, { first_name: 'مشتری', last_name: 'آزمایشی', mobile: '09120000000', birth_date: '' });
    assert.equal(blank.writes.find(w => w.table === 'customers').data.birth_date, null);
});

test('new sessions keep SQL dates Gregorian and generate Jalali follow-up timeline text', () => {
    const result = route({ r: 'sessions.create' }, {
        customer_id: '1', therapist_id: '1', service_id: '1', massage_date: '۱۴۰۵/۰۱/۰۱',
        price: '1000', final_amount: '1000', status: 'completed', recommended_next_visit_date: '',
    });
    assert.equal(result.writes.find(w => w.table === 'massage_sessions').data.massage_date, '2026-03-21');
    assert.equal(result.writes.find(w => w.table === 'followups').data.due_date, '2026-04-20');
    const timeline = result.writes.find(w => w.table === 'customer_timeline' && w.data.type === 'followup_created');
    assert.equal(timeline.data.body, 'تاریخ پیگیری: ۱۴۰۵/۰۱/۳۱');
    assert.match(timeline.data.created_at, /^\d{4}-\d{2}-\d{2} /);
});

test('historical CRM events and contact/audit timestamps are localized and escaped', () => {
    const profile = route({ r: 'customers.show', id: 1 });
    assert.match(profile.body, /<time class="date-time">۱۴۰۵\/۰۱\/۰۱ ۱۳:۰۵<\/time>/);
    assert.match(profile.body, /تاریخ پیگیری: ۱۴۰۵\/۰۱\/۰۱/);
    assert.match(profile.body, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
    assert.doesNotMatch(profile.body, /<script>alert\(1\)<\/script>/);
    const followups = route({ r: 'followups', filter: 'all' });
    assert.doesNotMatch(followups.body, /2026-03-21/);
    assert.match(followups.body, /۱۴۰۵\/۰۱\/۰۱ ۱۳:۰۵/);
    const audit = route({ r: 'audit' });
    assert.match(audit.body, /۱۴۰۵\/۰۱\/۰۱ ۱۳:۰۵:۰۹/);
});

test('list pages, retention and chart labels do not leak Gregorian dates', () => {
    for (const r of ['customers', 'appointments', 'sessions', 'expenses', 'packages', 'campaigns', 'retention']) {
        const result = route({ r });
        assert.match(result.body, /۱۴۰۵\/۰۱\/۰۱/);
        assert.doesNotMatch(result.body, /2026-03-21/);
    }
    const chart = JSON.parse(route({ r: 'api.revenue' }).body);
    assert.deepEqual(chart.labels, ['۱۴۰۵/۰۱/۰۱']);
});

test('reports, finance and salaries use the same Jalali range and Gregorian SQL parameters', () => {
    for (const r of ['reports', 'finance', 'salaries']) {
        const result = route({ r, from: '۱۴۰۵-۰۱-۰۱', to: '١٤٠٥/٠١/٠٢' });
        assert.match(result.body, /name="from" value="۱۴۰۵\/۰۱\/۰۱"/);
        assert.match(result.body, /name="to" value="۱۴۰۵\/۰۱\/۰۲"/);
        for (const query of result.queries.filter(q => q.sql.includes('BETWEEN ? AND ?'))) {
            assert.deepEqual(query.params.slice(-2), ['2026-03-21', '2026-03-22']);
        }
        assert.ok(result.queries.some(q => q.sql.includes('BETWEEN ? AND ?')));
        if (r !== 'salaries') {
            const link = result.body.match(/href="([^"]*export.csv[^"]*)"/)[1].replaceAll('&amp;', '&');
            const params = new URL(link, 'https://test.invalid/').searchParams;
            assert.equal(params.get('from'), '۱۴۰۵/۰۱/۰۱');
            assert.equal(params.get('to'), '۱۴۰۵/۰۱/۰۲');
        }
    }
    const legacy = route({ r: 'reports', from: '2026-03-21', to: '2026-03-22' });
    assert.match(legacy.body, /name="from" value="۱۴۰۵\/۰۱\/۰۱"/);
});

test('bad filters display actionable errors instead of running misleading calculations or CSV exports', () => {
    for (const r of ['reports', 'finance', 'salaries', 'export.csv']) {
        for (const query of [{ from: '1404/12/30' }, { from: '' }, { from: ['1405/01/01'] }, { from: '1405/01/02', to: '1405/01/01' }]) {
            const result = route({ r, ...query });
            assert.match(result.body, /alert-danger/);
            assert.equal(result.queries.some(q => q.sql.includes('BETWEEN ? AND ?')), false);
            if (r === 'export.csv') assert.equal(result.status, 422);
        }
    }
});

test('CSV accepts Jalali/legacy filter links and exports Jalali dates with intact CSV quoting', () => {
    for (const from of ['۱۴۰۵/۰۱/۰۱', '1405-01-01', '2026-03-21']) {
        const result = route({ r: 'export.csv', from, to: '۱۴۰۵/۰۱/۰۲' });
        assert.match(result.body, /^\uFEFFdate,customer,service,therapist,amount\n/);
        assert.match(result.body, /۱۴۰۵\/۰۱\/۰۱,"مشتری, ""آزمایشی"""/);
        assert.doesNotMatch(result.body, /2026-03-21/);
        assert.deepEqual(result.queries.find(q => q.sql.includes('BETWEEN ? AND ?')).params, ['2026-03-21', '2026-03-22']);
    }
});


test('default finance/salary/export/dashboard ranges all start at the current Jalali month', () => {
    const report = route({ r: 'reports' });
    const parameters = report.queries.find(q => q.sql.includes('BETWEEN ? AND ?')).params;
    assert.match(report.body, /name="from" value="[۰-۹]{4}\/[۰-۹]{2}\/۰۱"/);
    for (const r of ['finance', 'salaries', 'export.csv', 'dashboard']) {
        const result = route({ r });
        const rangeQuery = result.queries.find(q => q.sql.includes('BETWEEN ? AND ?'));
        assert.deepEqual(rangeQuery.params.slice(-2), parameters, r);
        assert.equal(result.queries.some(q => q.sql.includes('DATE_FORMAT')), false);
        if (r === 'dashboard') assert.match(result.body, /درآمد ماه جاری شمسی/);
    }
});
