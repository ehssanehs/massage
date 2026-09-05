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
        // Manual entry must work even when every script is disabled.
        const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
        const page = await context.newPage();
        const responses = await routeFixture(page, 'birth.test');
        await page.goto('https://birth.test/index.php?r=customers&page=2');
        const from = page.getByLabel('از تاریخ تولد', { exact: true });
        const to = page.getByLabel('تا تاریخ تولد', { exact: true });
        assert.equal(await from.inputValue(), '');
        assert.equal(await to.inputValue(), '');
        await from.fill('١٣٦٩-١-١');
        await to.fill('1369/01/03');
        await page.getByRole('searchbox').fill('آزمون صفحه');
        await submit(page);
        assert.equal(new URL(page.url()).searchParams.has('page'), false);
        assert.equal(await from.inputValue(), '۱۳۶۹/۰۱/۰۱');
        assert.equal(await to.inputValue(), '۱۳۶۹/۰۱/۰۳');
        assert.equal(await page.locator('tbody tr').count(), 20);
        assert.match(await page.locator('thead').innerText(), /تاریخ تولد/);
        assert.match(await page.locator('tbody').innerText(), /۱۳۶۹\/۰۱\/۰۱/);
        assert.doesNotMatch(await page.locator('tbody').innerText(), /1990-03-21/);
        const addButton = await page.getByRole('link', { name: 'افزودن', exact: true }).boundingBox();
        assert.ok(addButton.height < 60, 'Add customer must not stretch to the height of the whole filter form');
        await screenshot(page, 'birthdate-filter-desktop.png');
        await Promise.all([page.waitForNavigation(), page.getByRole('link', { name: '۲', exact: true }).click()]);
        const params = new URL(page.url()).searchParams;
        assert.equal(params.get('q'), 'آزمون صفحه');
        assert.equal(params.get('birth_from'), '۱۳۶۹/۰۱/۰۱');
        assert.equal(params.get('birth_to'), '۱۳۶۹/۰۱/۰۳');
        assert.equal(await page.locator('tbody tr').count(), 5);
        await Promise.all([page.waitForNavigation(), page.getByRole('link', { name: 'پاک‌کردن فیلترها' }).click()]);
        assert.deepEqual([...new URL(page.url()).searchParams.keys()], ['r']);
        assert.equal(await from.inputValue(), '');
        assert.equal(await to.inputValue(), '');
        assert.equal(await page.getByRole('searchbox').inputValue(), '');

        await to.fill('۱۳۶۸/۱۲/۲۹');
        await submit(page);
        assert.equal(await page.locator('tbody tr').count(), 2, 'one-sided range excludes unknown DOB');
        await from.fill('۱۴۰۴/۱۲/۳۰');
        await submit(page);
        assert.equal(responses.at(-1).status, 400);
        assert.equal(await from.inputValue(), '۱۴۰۴/۱۲/۳۰');
        assert.match(await page.getByRole('alert').innerText(), /تاریخ تولد از/);
        await from.fill('۱۳۶۹/۰۱/۰۳');
        await to.fill('۱۳۶۹/۰۱/۰۱');
        await submit(page);
        assert.equal(responses.at(-1).status, 400);
        assert.match(await page.getByRole('alert').innerText(), /ابتدای بازهٔ تاریخ تولد/);

        // Use the shipped scripts, including the real app initialization.
        const jsContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const js = await jsContext.newPage();
        const errors = [];
        js.on('pageerror', error => errors.push(error.message));
        const jsResponses = await routeFixture(js, 'birth.test');
        const initial = new URL('https://birth.test/index.php');
        initial.search = new URLSearchParams({ r: 'customers', q: 'محمد', birth_from: '1369/01/01', birth_to: '1369/01/03' }).toString();
        await js.goto(initial.href);
        const jsFrom = js.getByLabel('از تاریخ تولد', { exact: true });
        const jsTo = js.getByLabel('تا تاریخ تولد', { exact: true });
        await jsFrom.click();
        assert.equal(await js.locator('.jdp-popup').isVisible(), true);
        assert.match(await js.locator('.jdp-title').innerText(), /۱۳۶۹/);
        await js.locator('[data-d="2"]').click();
        await jsTo.click();
        await js.locator('[data-d="2"]').click();
        await submit(js);
        assert.equal(await js.locator('tbody tr').count(), 1);
        assert.match(await js.locator('tbody').innerText(), /محمد رضا نیک فر/);
        assert.match(await js.locator('tbody').innerText(), /۱۳۶۹\/۰۱\/۰۲/);
        const before = jsResponses.length;
        await jsFrom.fill('۱۴۰۴/۱۲/۳۰');
        await jsFrom.press('Tab');
        await js.keyboard.press('Escape');
        assert.equal(await jsFrom.evaluate(el => el.checkValidity()), false);
        await js.getByRole('button', { name: 'جستجو', exact: true }).click();
        assert.equal(jsResponses.length, before, 'invalid calendar date is blocked before navigation');
        await js.keyboard.press('Escape');
        await Promise.all([js.waitForNavigation(), js.getByRole('link', { name: 'پاک‌کردن فیلترها' }).click()]);
        assert.equal(await jsFrom.inputValue(), '');
        assert.equal(await jsTo.inputValue(), '');
        await js.setViewportSize({ width: 375, height: 812 });
        await js.locator('#themeToggle').click();
        assert.equal(await js.locator('html').getAttribute('data-bs-theme'), 'dark');
        for (const field of [jsFrom, jsTo]) {
            const box = await field.boundingBox();
            assert.ok(box.width > 140 && box.x >= 0 && box.x + box.width <= 375, JSON.stringify(box));
        }
        await screenshot(js, 'birthdate-filter-mobile-dark.png');
        assert.deepEqual(errors, []);
        console.log('OK: Chromium birth-date filters with/without JS, Jalali picker, inclusive range, pagination, reset, validation and mobile/dark layout (SQLite fixtures)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
