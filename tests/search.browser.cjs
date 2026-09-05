// Browser checks of real GET forms + front-controller list SQL. No JS/CDN needed.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { routeFixture } = require('./browser-app.cjs');

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox', '--disable-dev-shm-usage'] } : {}) });
    try {
        const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1100, height: 820 } });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await routeFixture(page);
        await page.goto('https://search.test/index.php?r=customers&page=2');
        const input = page.getByRole('searchbox', { name: 'جستجو در مشتریان' });
        await input.fill('کاظمی  علي');
        await Promise.all([page.waitForURL(url => url.searchParams.get('q') === 'کاظمی  علي'), input.press('Enter')]);
        assert.equal(new URL(page.url()).searchParams.has('page'), false, 'new search resets the old page');
        assert.equal(await input.inputValue(), 'کاظمی  علي', 'user spelling stays in the search field');
        assert.equal(await page.locator('tbody tr').count(), 1);
        assert.match(await page.locator('tbody').innerText(), /علي كاظمي/);

        await input.fill('آزمون صفحه');
        await Promise.all([page.waitForURL(url => url.searchParams.get('q') === 'آزمون صفحه'), page.getByRole('button', { name: 'جستجو', exact: true }).click()]);
        assert.equal(await page.locator('tbody tr').count(), 20);
        await Promise.all([page.waitForURL(url => url.searchParams.get('page') === '2'), page.getByRole('link', { name: '۲', exact: true }).click()]);
        assert.equal(new URL(page.url()).searchParams.get('q'), 'آزمون صفحه');
        assert.equal(await page.locator('tbody tr').count(), 5);
        await Promise.all([page.waitForURL(url => !url.searchParams.has('q')), page.getByRole('link', { name: 'پاک‌کردن جستجو' }).click()]);
        assert.equal(new URL(page.url()).searchParams.has('page'), false);
        assert.equal(await input.inputValue(), '');
        assert.equal(await page.locator('tbody tr').count(), 20);

        await input.fill('اسم ناموجود');
        await Promise.all([page.waitForURL(url => url.searchParams.has('q')), input.press('Enter')]);
        assert.match(await page.locator('.empty').innerText(), /رکوردی یافت نشد/);
        await page.goto('https://search.test/index.php?r=appointments');
        await page.locator('input[name="q"]').fill('علی کاظمی');
        await Promise.all([page.waitForURL(url => url.searchParams.has('q')), page.getByRole('button', { name: 'جستجو', exact: true }).click()]);
        assert.equal(await page.locator('tbody tr').count(), 1);
        assert.match(await page.locator('tbody').innerText(), /علي كاظمي/);
        assert.deepEqual(errors, []);
        console.log('OK: Chromium no-JS search, full/reversed names, GET encoding, count/pagination, clear, no results and joined lists (SQLite fixture SQL)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
