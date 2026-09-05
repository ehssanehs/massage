// Optional Chromium interaction tests using the REAL PHP time-input renderer.
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.join(__dirname, '..');
const snippets = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', ['-r', `
    require 'app/bootstrap.php';
    $html = [];
    foreach (['start'=>'10:07:09', 'end'=>null, 'readonly'=>'09:00:00', 'disabled'=>'10:00:00', 'dynamic'=>'10:00:00'] as $name=>$value) {
        $html[$name] = App\\Support\\View::timeInput($name, $value, $name === 'start');
    }
    echo json_encode($html, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
`], { cwd: root, encoding: 'utf8' }));

async function setup(context, errors) {
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.clock.install({ time: new Date('2026-03-20T21:00:00Z') });
    await page.setContent(`<!doctype html><html lang="fa" dir="rtl" data-timezone="Asia/Tehran" data-bs-theme="light"><head>
        <meta name="viewport" content="width=device-width, initial-scale=1"><style>
        body{margin:0;padding:20px}main{max-width:760px;margin:auto}h1{font-size:24px}.time-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;padding:24px}.form-label{display:block;margin-bottom:8px}.form-control{border:1px solid #ddd;box-sizing:border-box}.btn{font:inherit}#other{margin-top:24px} @media(max-width:600px){.time-form{grid-template-columns:1fr;padding:16px}body{padding:14px}}
        </style></head><body><main><h1>ورود آسان ساعت</h1><form id="form" class="time-form card">
        <div><label class="form-label" for="f_start">ساعت شروع</label>${snippets.start}</div>
        <div><label class="form-label" for="f_end">ساعت پایان (اختیاری)</label>${snippets.end}</div>
        <div><label for="date">تاریخ شمسی</label><input id="date" data-jdp value="۱۴۰۵/۰۱/۰۱"></div>
        <div><button id="submit">ذخیره</button><button id="reset" type="reset">بازنشانی</button></div>
        </form><div id="other"><label for="f_readonly">فقط خواندنی</label>${snippets.readonly}<label for="f_disabled">غیرفعال</label>${snippets.disabled}</div></main></body></html>`);
    await page.evaluate(() => {
        document.getElementById('f_readonly').readOnly = true;
        document.getElementById('f_disabled').disabled = true;
        window.submissions = 0;
        document.getElementById('form').addEventListener('submit', event => { event.preventDefault(); window.submissions++; });
    });
    for (const name of ['app', 'jalalidatepicker', 'timepicker']) await page.addStyleTag({ path: path.join(root, `public/assets/css/${name}.css`) });
    for (const name of ['jalalidatepicker', 'timepicker']) await page.addScriptTag({ path: path.join(root, `public/assets/js/${name}.js`) });
    await page.evaluate(() => JalaliPicker.init());
    return page;
}
const toggle = (page, id = 'start') => page.locator(`#f_${id}`).locator('..').locator('[data-time-toggle]');
const action = (page, name) => page.locator(`[data-time-action="${name}"]`);
async function screenshot(page, name) {
    if (!process.env.BROWSER_ARTIFACT_DIR) return;
    fs.mkdirSync(process.env.BROWSER_ARTIFACT_DIR, { recursive: true });
    await page.screenshot({ path: path.join(process.env.BROWSER_ARTIFACT_DIR, name) });
}

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox', '--disable-dev-shm-usage'] } : {}) });
    const errors = [];
    try {
        const context = await browser.newContext({ timezoneId: 'America/Los_Angeles', viewport: { width: 1100, height: 900 } });
        const page = await setup(context, errors);
        const start = page.locator('#f_start');
        assert.equal(await start.inputValue(), '۱۰:۰۷:۰۹');
        await page.evaluate(() => { TimePicker.init(); TimePicker.init(); });
        assert.equal(await start.inputValue(), '۱۰:۰۷:۰۹');
        assert.equal(await toggle(page).isVisible(), true);
        assert.equal(await start.getAttribute('inputmode'), 'numeric');

        for (const [value, expected] of [['۹', '۰۹:۰۰'], ['۹۳۰', '۰۹:۳۰'], ['٠٩:٣٠', '۰۹:۳۰'], ['9:5', '۰۹:۰۵'], ['0930', '۰۹:۳۰']]) {
            await start.fill(value);
            await start.press('Tab');
            assert.equal(await start.inputValue(), expected);
            assert.equal(await start.evaluate(el => el.checkValidity()), true);
        }
        for (const value of ['1260', '24:00', '9:', '9:30 PM']) {
            await start.fill(value);
            await start.press('Tab');
            assert.equal(await start.inputValue(), value);
            assert.equal(await start.evaluate(el => el.checkValidity()), false);
            assert.equal(await start.getAttribute('aria-invalid'), 'true');
            assert.equal(await page.locator('#f_start_error').isVisible(), true);
            await page.evaluate(() => document.getElementById('form').requestSubmit());
            assert.equal(await page.evaluate(() => window.submissions), 0);
        }
        await start.fill('930');
        await page.evaluate(() => document.getElementById('form').requestSubmit());
        assert.equal(await page.evaluate(() => window.submissions), 1);
        assert.equal(await start.inputValue(), '۰۹:۳۰', 'submission normalizes even without blur');

        await start.fill('');
        await toggle(page).click();
        await page.locator('[data-time-hour="14"]').click();
        await page.locator('[data-time-minute="45"]').click();
        assert.equal(await start.inputValue(), '', 'draft selection does not fill a field');
        await action(page, 'cancel').press('Enter');
        assert.equal(await start.inputValue(), '', 'Cancel never applies draft');
        await toggle(page).click();
        await page.evaluate(() => {
            window.events = [];
            for (const type of ['input', 'change']) document.getElementById('f_start').addEventListener(type, e => window.events.push(e.type));
        });
        await page.locator('[data-time-hour="9"]').click();
        await page.locator('[data-time-minute="30"]').click();
        await screenshot(page, 'time-picker-desktop.png');
        await action(page, 'apply').click();
        assert.equal(await start.inputValue(), '۰۹:۳۰');
        assert.deepEqual(await page.evaluate(() => window.events), ['input', 'change']);
        assert.equal(await page.locator('.time-picker').isVisible(), false);
        assert.equal(await toggle(page).getAttribute('aria-expanded'), 'false');
        assert.equal(await toggle(page).evaluate(el => el === document.activeElement), true);
        assert.equal(await page.evaluate(() => window.submissions), 1, 'picker buttons do not submit the form');

        await start.press('ArrowDown');
        await page.locator('[data-time-part="hour"]').fill('۱۱');
        await page.locator('[data-time-part="hour"]').press('ArrowUp');
        await page.locator('[data-time-part="minute"]').fill('۰۷');
        await page.locator('[data-time-part="minute"]').press('Enter');
        assert.equal(await start.inputValue(), '۱۲:۰۷', 'exact minutes need not be quarter hours');
        assert.equal(await start.evaluate(el => el === document.activeElement), true);
        await start.press('ArrowDown');
        await page.locator('[data-time-hour="12"]').focus();
        await page.keyboard.press('ArrowRight');
        assert.equal(await page.locator('[data-time-hour="13"]').getAttribute('aria-pressed'), 'true');
        await page.keyboard.press('Escape');
        assert.equal(await start.inputValue(), '۱۲:۰۷');
        await toggle(page).click();
        await page.locator('[data-time-part="minute"]').fill('۶۰');
        assert.equal(await action(page, 'apply').isDisabled(), true);
        assert.equal(await page.locator('#time-picker-error').isVisible(), true);
        await page.keyboard.press('Escape');

        await start.fill('10:07:09');
        await toggle(page).click();
        await action(page, 'apply').click();
        assert.equal(await start.inputValue(), '۱۰:۰۷:۰۹', 'unchanged legacy seconds survive opening/applying');
        await toggle(page).click();
        await action(page, 'now').press('Enter');
        assert.equal(await start.inputValue(), '۰۰:۳۰', 'now uses Tehran, not browser timezone');
        await toggle(page).click();
        await action(page, 'clear').click();
        assert.equal(await start.inputValue(), '');
        assert.equal(await start.evaluate(el => el.validity.valueMissing), true);
        await toggle(page, 'end').click();
        await action(page, 'clear').click();
        assert.equal(await page.locator('#f_end').evaluate(el => el.checkValidity()), true);
        await page.locator('#reset').click();
        await page.waitForTimeout(10);
        assert.equal(await start.inputValue(), '۱۰:۰۷:۰۹');
        assert.equal(await start.evaluate(el => el.checkValidity()), true);
        assert.equal(await page.locator('#f_start_error').isVisible(), false);

        await page.evaluate(() => { TimePicker.open(document.getElementById('f_readonly')); TimePicker.open(document.getElementById('f_disabled')); });
        assert.equal(await page.locator('.time-picker').isVisible(), false);
        assert.equal(await toggle(page, 'readonly').isDisabled(), true);
        assert.equal(await toggle(page, 'disabled').isDisabled(), true);
        await page.evaluate(html => { const wrapper = document.createElement('div'); wrapper.innerHTML = '<label for="f_dynamic">ساعت پویا</label>' + html; document.getElementById('other').appendChild(wrapper); }, snippets.dynamic);
        await page.locator('#f_dynamic').focus();
        assert.equal(await toggle(page, 'dynamic').isVisible(), true);
        await page.locator('#f_dynamic').press('ArrowDown');
        await page.locator('[data-time-hour="22"]').click();
        await action(page, 'apply').click();
        assert.equal(await page.locator('#f_dynamic').inputValue(), '۲۲:۰۰');

        await toggle(page).click();
        await page.locator('#date').focus(); // keyboard focus can leave the non-modal picker
        assert.equal(await page.locator('.time-picker').isVisible(), false);
        assert.equal(await page.locator('.jdp-popup').isVisible(), true);
        await toggle(page).click();
        assert.equal(await page.locator('.jdp-popup').isVisible(), false);
        await page.keyboard.press('Escape');
        assert.equal(await start.inputValue(), '۱۰:۰۷:۰۹', 'Jalali picker never changes clock fields');

        const mobileContext = await browser.newContext({ timezoneId: 'America/Los_Angeles', viewport: { width: 375, height: 740 }, isMobile: true, hasTouch: true });
        const mobile = await setup(mobileContext, errors);
        await mobile.evaluate(() => document.documentElement.setAttribute('data-bs-theme', 'dark'));
        await toggle(mobile).click();
        assert.equal(await mobile.evaluate(() => document.activeElement.tagName), 'BUTTON', 'touch picker does not open the text keyboard');
        const box = await mobile.locator('.time-picker').boundingBox();
        assert.ok(box.x >= 0 && box.x + box.width <= 375 && box.y >= 0 && box.y + box.height <= 740, JSON.stringify(box));
        await mobile.locator('[data-time-hour="23"]').click();
        await mobile.locator('[data-time-minute="45"]').click();
        await screenshot(mobile, 'time-picker-mobile.png');
        await action(mobile, 'apply').click();
        assert.equal(await mobile.locator('#f_start').inputValue(), '۲۳:۴۵');
        assert.equal(await toggle(mobile).evaluate(el => el === document.activeElement), true);
        const noJSContext = await browser.newContext({ javaScriptEnabled: false });
        const noJS = await noJSContext.newPage();
        await noJS.setContent(`<html lang="fa" dir="rtl"><head><style>${fs.readFileSync(path.join(root, 'public/assets/css/timepicker.css'), 'utf8')}</style></head><body><form><label for="f_start">ساعت شروع</label>${snippets.start}</form></body></html>`);
        assert.equal(await toggle(noJS).isVisible(), false, 'picker button stays hidden without JS');
        await noJS.locator('#f_start').fill('۹۳۰');
        await noJS.locator('#f_start').press('Tab');
        assert.equal(await noJS.locator('#f_start').inputValue(), '۹۳۰', 'no-JS typing is left for the PHP normalizer');
        assert.equal(await noJS.locator('#f_start').evaluate(el => el.checkValidity()), true);
        assert.deepEqual(errors, []);
        console.log('OK: Chromium real PHP markup, typing, validation, popup, keyboard, seconds, reset, timezone, Jalali coexistence, mobile/dark mode and no-JS fallback');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
