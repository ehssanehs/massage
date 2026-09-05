// Optional browser regression suite. Requires Playwright + an installed Chromium.
// Run: node tests/datepicker.browser.cjs
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({
        headless: true,
        ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox', '--disable-dev-shm-usage'] } : {}),
    });
    try {
        const context = await browser.newContext({ timezoneId: 'America/Los_Angeles' });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.clock.install({ time: new Date('2026-03-20T21:00:00Z') });
        await page.setContent(`<!doctype html><html lang="fa" dir="rtl" data-timezone="Asia/Tehran" data-today="۱۴۰۵/۰۱/۰۱"><body>
            <form id="form"><label for="date">تاریخ شمسی</label><input id="date" data-jdp required>
            <input id="optional" data-jdp><button id="submit">ذخیره</button><button id="reset" type="reset">بازنشانی</button></form>
            <form id="legacy"><input id="native" type="date" value="2026-03-21" min="2026-03-20" max="2026-03-22"></form>
            <input id="readonly" data-jdp readonly><input id="disabled" data-jdp disabled>
        </body></html>`);
        await page.addStyleTag({ path: path.join(__dirname, '../public/assets/css/jalalidatepicker.css') });
        await page.addScriptTag({ path: path.join(__dirname, '../public/assets/js/jalalidatepicker.js') });
        await page.evaluate(() => {
            JalaliPicker.init();
            window.submissions = 0;
            document.getElementById('form').addEventListener('submit', e => { e.preventDefault(); window.submissions++; });
        });

        const native = page.locator('#native');
        assert.equal(await native.getAttribute('type'), 'text');
        assert.equal(await native.inputValue(), '۱۴۰۵/۰۱/۰۱');
        assert.equal(await native.getAttribute('data-jdp-min'), '۱۴۰۴/۱۲/۲۹');
        assert.equal(await native.getAttribute('data-jdp-max'), '۱۴۰۵/۰۱/۰۲');
        await page.evaluate(() => JalaliPicker.init());
        assert.equal(await native.inputValue(), '۱۴۰۵/۰۱/۰۱', 'init must not double-convert');
        await native.click();
        assert.equal(await page.locator('[data-d="3"]').isDisabled(), true);
        await page.locator('[data-d="2"]').click();
        assert.equal(await native.inputValue(), '۱۴۰۵/۰۱/۰۲');
        await page.evaluate(() => document.getElementById('legacy').reset());
        await page.waitForTimeout(10);
        assert.equal(await native.inputValue(), '۱۴۰۵/۰۱/۰۱', 'native defaultValue is also converted');

        const input = page.locator('#date');
        for (const invalid of ['1404/12/30', '۱۴۰۵/۰۷/۳۱', '2026-03-21']) {
            await input.fill(invalid);
            await input.press('Tab');
            assert.equal(await input.inputValue(), invalid);
            assert.equal(await input.evaluate(el => el.checkValidity()), false);
            assert.match(await input.evaluate(el => el.validationMessage), /تاریخ شمسی/);
            await page.evaluate(() => document.getElementById('form').requestSubmit());
            assert.equal(await page.evaluate(() => window.submissions), 0);
        }
        await input.fill('١٤٠٣-١٢-٣٠');
        await input.press('Tab');
        assert.equal(await input.inputValue(), '۱۴۰۳/۱۲/۳۰');
        assert.equal(await input.evaluate(el => el.checkValidity()), true);
        await page.evaluate(() => document.getElementById('form').requestSubmit());
        assert.equal(await page.evaluate(() => window.submissions), 1);

        await input.click();
        assert.equal(await page.locator('.jdp-title').innerText(), 'اسفند ۱۴۰۳');
        assert.equal(await page.locator('[data-d="30"]').count(), 1);
        await page.locator('[data-a="ny"]').click();
        assert.equal(await page.locator('.jdp-title').innerText(), 'اسفند ۱۴۰۴');
        assert.equal(await page.locator('[data-d="30"]').count(), 0, 'non-leap Esfand has 29 days');
        await page.locator('[data-a="nm"]').click();
        assert.equal(await page.locator('.jdp-title').innerText(), 'فروردین ۱۴۰۵');
        await page.locator('[data-d="1"]').click();
        assert.equal(await input.inputValue(), '۱۴۰۵/۰۱/۰۱');
        assert.equal(await page.locator('.jdp-popup').isVisible(), false);

        await input.click();
        await page.locator('[data-a="today"]').click();
        assert.equal(await input.inputValue(), '۱۴۰۵/۰۱/۰۱', 'today uses Tehran, not Los Angeles');
        await input.click();
        await page.locator('[data-a="clear"]').click();
        assert.equal(await input.inputValue(), '');
        assert.equal(await input.evaluate(el => el.validity.valueMissing), true);
        await page.locator('#optional').fill('1405/01/01');
        await page.locator('#optional').click();
        await page.locator('[data-a="clear"]').click();
        assert.equal(await page.locator('#optional').evaluate(el => el.checkValidity()), true);

        await page.evaluate(() => {
            const dynamic = document.createElement('input');
            dynamic.id = 'dynamic';
            dynamic.type = 'date';
            dynamic.value = '2026-03-21';
            document.body.appendChild(dynamic);
        });
        await page.locator('#dynamic').focus();
        assert.equal(await page.locator('#dynamic').inputValue(), '۱۴۰۵/۰۱/۰۱');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('.jdp-popup').isVisible(), false);
        await page.evaluate(() => {
            JalaliPicker.open(document.getElementById('readonly'));
            JalaliPicker.open(document.getElementById('disabled'));
        });
        assert.equal(await page.locator('.jdp-popup').isVisible(), false);
        assert.deepEqual(errors, []);
        console.log('OK: Chromium picker, validation, native/dynamic inputs, leap days, reset and timezone checks');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
