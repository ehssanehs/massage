const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const fixtures = require('./fixtures/dates.json');

function loadPicker(now = '2026-09-05T12:00:00Z', timeZone = 'Asia/Tehran') {
    const document = { addEventListener() {}, documentElement: { dataset: { timezone: timeZone } } };
    const window = { addEventListener() {} };
    const clock = class extends Date { constructor(...args) { super(...(args.length ? args : [now])); } };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/assets/js/jalalidatepicker.js'), 'utf8'), { window, document, Date: clock, Intl });
    return window.JalaliPicker;
}
const picker = loadPicker();
const fa = value => String(value).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[+d]);
const ar = value => String(value).replace(/\d/g, d => '٠١٢٣٤٥٦٧٨٩'[+d]);
const nativeArray = value => value === null ? null : Array.from(value);

test('known Jalali/Gregorian dates match the PHP fixtures', () => {
    for (const { gregorian, jalali } of fixtures) {
        const j = jalali.split('/').map(Number);
        const g = gregorian.split('-').map(Number);
        assert.deepEqual(nativeArray(picker.g2j(...g)), j);
        assert.deepEqual(nativeArray(picker.j2g(...j)), g);
        assert.equal(picker.fromGregorian(gregorian), fa(jalali));
        for (const delimiter of ['/', '-', '.']) {
            const input = jalali.replaceAll('/', delimiter);
            for (const value of [input, fa(input), ar(input)]) assert.deepEqual(nativeArray(picker.parseInput(value)), j);
        }
    }
});

test('manual entry rejects invalid month lengths, leap days and malformed input', () => {
    for (const input of ['', ' ', '1404/12/30', '1405/07/31', '1405/00/01', '1405/13/01', '1405/01/00', '1405/01/32', '0000/01/01', '1701/01/01', '1405/01-01', '1405/1/1junk', '1405abc/1/1', '2026-03-21', '2026/03/21']) {
        assert.equal(picker.parseInput(input), null, input);
    }
    assert.deepEqual(nativeArray(picker.parseInput(' ۱۴۰۵/۱/۱ ')), [1405, 1, 1]);
    assert.equal(picker.monthLength(1403, 12), 30);
    assert.equal(picker.monthLength(1404, 12), 29);
    assert.equal(picker.monthLength(1405, 6), 31);
    assert.equal(picker.monthLength(1405, 7), 30);
    for (const input of ['0000-00-00', '2026-02-30', '2026-13-01', '2026-01-00', 'junk']) assert.equal(picker.fromGregorian(input), '');
});

test('today follows APP_TIMEZONE around midnight and Nowruz', () => {
    const now = '2026-03-20T21:00:00Z';
    assert.deepEqual(nativeArray(loadPicker(now, 'Asia/Tehran').todayJalali()), [1405, 1, 1]);
    assert.deepEqual(nativeArray(loadPicker(now, 'UTC').todayJalali()), [1404, 12, 29]);
    assert.deepEqual(nativeArray(loadPicker(now, 'America/Los_Angeles').todayJalali()), [1404, 12, 29]);
});

test('calendar day counts, input parsing and conversions round-trip for eleven years', () => {
    for (let year = 1398; year <= 1408; year++) {
        for (let month = 1; month <= 12; month++) {
            for (let day = 1; day <= 31; day++) {
                const j = [year, month, day];
                const text = picker.formatInput(j);
                const parsed = picker.parseInput(text);
                if (day > picker.monthLength(year, month)) assert.equal(parsed, null);
                else {
                    assert.deepEqual(nativeArray(parsed), j);
                    assert.deepEqual(nativeArray(picker.g2j(...picker.j2g(...j))), j);
                }
            }
        }
    }
});
