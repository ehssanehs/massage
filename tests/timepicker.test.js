const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const fixtures = require('./fixtures/times.json');

function loadPicker(now = '2026-03-20T21:00:00Z', timeZone = 'Asia/Tehran') {
    const document = { addEventListener() {}, documentElement: { dataset: { timezone: timeZone } } };
    const window = { addEventListener() {} };
    const clock = class extends Date { constructor(...args) { super(...(args.length ? args : [now])); } };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/assets/js/timepicker.js'), 'utf8'), { window, document, Date: clock, Intl });
    return window.TimePicker;
}
const picker = loadPicker();
const fa = value => String(value).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[+d]);
const ar = value => String(value).replace(/\d/g, d => '٠١٢٣٤٥٦٧٨٩'[+d]);
const pad = n => String(n).padStart(2, '0');

test('time parsing agrees with PHP fixtures, including compact typing and invalid/blank input', () => {
    for (const { input, normalized } of fixtures) assert.equal(picker.normalize(input), normalized, String(input));
    assert.equal(picker.format('930'), '۰۹:۳۰');
    assert.equal(picker.format('۲۵:۶۰'), '۲۵:۶۰', 'Never replace invalid input with another time');
    assert.equal(picker.format(''), '');
});

test('every clock minute round-trips in all digit sets; stored seconds are preserved', () => {
    for (let hour = 0; hour < 24; hour++) {
        for (let minute = 0; minute < 60; minute++) {
            const time = `${pad(hour)}:${pad(minute)}:00`;
            for (const value of [`${hour}${pad(minute)}`, `${pad(hour)}:${pad(minute)}`]) {
                for (const input of [value, fa(value), ar(value)]) assert.equal(picker.normalize(input), time);
            }
            assert.equal(picker.normalize(picker.format(time)), time);
            const seconds = `${pad(hour)}:${pad(minute)}:${pad((hour + minute) % 60)}`;
            assert.equal(picker.normalize(picker.format(seconds)), seconds);
        }
    }
});

test('now follows APP_TIMEZONE in 24-hour time, including midnight/noon', () => {
    assert.equal(loadPicker().now(), '00:30:00');
    assert.equal(loadPicker(undefined, 'UTC').now(), '21:00:00');
    assert.equal(loadPicker(undefined, 'America/Los_Angeles').now(), '14:00:00');
    assert.equal(loadPicker('2026-03-20T20:30:00Z').now(), '00:00:00');
    assert.equal(loadPicker('2026-03-20T08:30:00Z').now(), '12:00:00');
    assert.equal(loadPicker(undefined, 'UTC').normalize('23:30'), '23:30:00', 'Manual times are not timezone-converted');
});
