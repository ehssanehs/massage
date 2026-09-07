const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const { routeFixture } = require('./browser-app.cjs');

async function submit(page) {
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'جستجو', exact: true }).click()]);
}
async function screenshot(page, name) {
    if (!process.env.BROWSER_ARTIFACT_DIR) return;
    fs.mkdirSync(process.env.BROWSER_ARTIFACT_DIR, { recursive: true });
    await page.screenshot({ path: path.join(process.env.BROWSER_ARTIFACT_DIR, name), animations: 'disabled' });
}

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox', '--disable-dev-shm-usage'] } : {}) });
    try {
        const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
        const page = await context.newPage();
        const responses = await routeFixture(page, 'birth.test');
        await page.goto('https://birth.test/index.php?r=customers&page=2');
        const month = page.getByLabel('ماه تولد (شمسی)', { exact: true });
        assert.equal(await month.inputValue(), '');
        await month.selectOption('1');
        await page.getByRole('searchbox').fill('آزمون صفحه');
        await submit(page);
        assert.equal(new URL(page.url()).searchParams.has('page'), false);
        assert.equal(await month.inputValue(), '1');
        assert.equal(await page.locator('tbody tr').count(), 20);
        assert.match(await page.locator('thead').innerText(), /تاریخ تولد/);
        assert.match(await page.locator('tbody').innerText(), /۱۳۶۹\/۰۱\/۰۱/);
        assert.doesNotMatch(await page.locator('tbody').innerText(), /1990-03-21/);
        const addButton = await page.getByRole('link', { name: 'افزودن', exact: true }).boundingBox();
        assert.ok(addButton.height < 60, 'Add customer must not stretch to the height of the whole filter form');
        await screenshot(page, 'birthmonth-filter-desktop.png');
        await Promise.all([page.waitForNavigation(), page.getByRole('link', { name: '۲', exact: true }).click()]);
        const params = new URL(page.url()).searchParams;
        assert.equal(params.get('q'), 'آزمون صفحه');
        assert.equal(params.get('birth_month'), '1');
        assert.ok(!params.has('birth_from') && !params.has('birth_to'), 'no legacy range params in pager');
        assert.equal(await page.locator('tbody tr').count(), 5);
        await Promise.all([page.waitForNavigation(), page.getByRole('link', { name: 'پاک‌کردن فیلترها' }).click()]);
        assert.deepEqual([...new URL(page.url()).searchParams.keys()], ['r']);
        assert.equal(await month.inputValue(), '');
        assert.equal(await page.getByRole('searchbox').inputValue(), '');

        await month.selectOption('12');
        await submit(page);
        assert.equal(await page.locator('tbody tr').count(), 20, 'Esfand page 1 is full (30 matches total)');
        await month.selectOption('7');
        await submit(page);
        assert.equal(await page.locator('tbody tr').count(), 0, 'Mehr has no fixture birthdays');
        assert.match(await page.locator('tbody').innerText(), /رکوردی یافت نشد/);

        // With JS enabled the select still submits as a plain GET filter.
        const jsContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const js = await jsContext.newPage();
        const errors = [];
        js.on('pageerror', error => errors.push(error.message));
        await routeFixture(js, 'birth.test');
        const initial = new URL('https://birth.test/index.php');
        initial.search = new URLSearchParams({ r: 'customers', q: 'علی', birth_month: '1' }).toString();
        await js.goto(initial.href);
        const jsMonth = js.getByLabel('ماه تولد (شمسی)', { exact: true });
        assert.equal(await jsMonth.inputValue(), '1');
        assert.equal(await js.locator('tbody tr').count(), 1);
        assert.match(await js.locator('tbody').innerText(), /علي كاظمي/);
        await Promise.all([js.waitForNavigation(), js.getByRole('link', { name: 'پاک‌کردن فیلترها' }).click()]);
        assert.equal(await jsMonth.inputValue(), '');
        await js.setViewportSize({ width: 375, height: 812 });
        await js.locator('#themeToggle').click();
        assert.equal(await js.locator('html').getAttribute('data-bs-theme'), 'dark');
        const box = await jsMonth.boundingBox();
        assert.ok(box.width > 140 && box.x >= 0 && box.x + box.width <= 375, JSON.stringify(box));
        await screenshot(js, 'birthmonth-filter-mobile-dark.png');
        assert.deepEqual(errors, []);
        console.log('OK: Chromium birth-month filter with/without JS, pagination, reset and mobile/dark layout (SQLite fixtures)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
