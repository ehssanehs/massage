const {test} = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

// The weekly working-schedule editor. Online booking reads exactly this JSON,
// so the form has to produce it correctly and refuse nonsense hours.
function route(get, post, session = {uid: 1, tenant: 'men', _csrf: 't'}, fixtures = undefined) {
  const input = {get, session};
  if (post !== undefined) input.post = post;
  if (fixtures !== undefined) input.fixtures = fixtures;
  const result = JSON.parse(execFileSync(process.env.PHP_BIN || 'php',
    [path.join(__dirname, 'route-harness.php'), JSON.stringify(input)],
    {cwd: path.join(__dirname, '..'), encoding: 'utf8'}));
  assert.equal(result.error, null, JSON.stringify(result.error));
  return result;
}

const day = (start, end, closed) => closed ? {closed: '1'} : {start, end};

test('the therapist form shows a week grid, not a JSON box', () => {
  const {status, body} = route({r: 'therapists.create'});
  assert.equal(status, 200);
  assert.match(body, /schedule-grid/);
  for (const key of ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri']) {
    assert.match(body, new RegExp(`name="working_schedule\\[${key}\\]\\[start\\]"`), `${key} start input exists`);
    assert.match(body, new RegExp(`name="working_schedule\\[${key}\\]\\[end\\]"`), `${key} end input exists`);
    assert.match(body, new RegExp(`name="working_schedule\\[${key}\\]\\[closed\\]"`), `${key} closed toggle exists`);
  }
  assert.doesNotMatch(body, /<textarea[^>]*working_schedule/, 'the raw JSON textarea is gone');
});

test('saving the grid stores the JSON the booking page reads', () => {
  const {status, writes} = route({r: 'therapists.create'}, {
    name: 'درمانگر آزمایشی', code: 'T-9', status: 'active',
    working_schedule: {
      sat: day('09:00', '17:00'),
      sun: day('09:00', '17:00'),
      mon: day('', '', true),
      tue: day('12:00', '20:00'),
      wed: {closed: '1'},
      thu: day('10:30', '18:30'),
      fri: {closed: '1'},
    },
  });
  assert.equal(status, 302, 'a successful save redirects');
  const insert = writes.find(w => w.table === 'therapists' && w.operation === 'insert');
  assert.ok(insert, 'the therapist row was written');
  const stored = JSON.parse(insert.data.working_schedule);
  assert.deepEqual(stored, {
    sat: '09:00-17:00',
    sun: '09:00-17:00',
    tue: '12:00-20:00',
    thu: '10:30-18:30',
  }, 'closed days are omitted and open days keep their hours');
});

test('a reversed shift is rejected instead of being stored', () => {
  const {status, writes, body} = route({r: 'therapists.create'}, {
    name: 'درمانگر اشتباه', code: 'T-8', status: 'active',
    working_schedule: {sat: day('18:00', '09:00')},
  });
  assert.equal(status, 200, 'the form is shown again');
  assert.match(body, /ساعت پایان باید بعد از ساعت شروع باشد/);
  assert.equal(writes.some(w => w.table === 'therapists'), false, 'nothing was saved');
});

test('a therapist with every day closed gets a null schedule', () => {
  const {writes} = route({r: 'therapists.create'}, {
    name: 'درمانگر تعطیل', code: 'T-7', status: 'active',
    working_schedule: {sat: {closed: '1'}, sun: {closed: '1'}, mon: {closed: '1'}, tue: {closed: '1'},
                       wed: {closed: '1'}, thu: {closed: '1'}, fri: {closed: '1'}},
  });
  const insert = writes.find(w => w.table === 'therapists' && w.operation === 'insert');
  assert.ok(insert);
  assert.equal(insert.data.working_schedule, null);
});

test('the show page renders the schedule in words', () => {
  const {body} = route({r: 'therapists.show', id: 1}, undefined, undefined,
    {therapists: {working_schedule: '{"sat":"09-17","mon":"12:00-20:00"}'}});
  assert.match(body, /شنبه/);
  assert.match(body, /۰۹:۰۰ تا ۱۷:۰۰|09:00 تا 17:00/);
});
