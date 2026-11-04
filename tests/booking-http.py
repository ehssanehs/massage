#!/usr/bin/env python3
"""Real HTTP/MySQL verification of online booking.

Creates a scratch database, installs it, adds two therapists with a known
schedule and three services, then proves over real HTTP that:

  * the booking page only offers times that come from the panel's schedule,
  * a slot disappears once it is taken, and an overlapping slot is never offered,
  * a booking creates the customer (once) and a pending appointment with a
    reference, and never trusts the posted time/date/service,
  * the guest can read the status with that reference,
  * staff confirm the booking from the panel and the status changes.

Never touches .env or the production database; the scratch database is dropped.
"""
import os, re, socket, subprocess, tempfile, time, uuid, json
from datetime import date, timedelta
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request
from urllib.parse import urlencode
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php8.2')
assertions = 0


def check(condition, message):
    global assertions
    assertions += 1
    if not condition:
        raise AssertionError(message)


def run(args, env=None):
    return subprocess.run(args, cwd=ROOT, text=True, capture_output=True, env=env)


def sql(db, statement):
    result = run(['mysql', '-N', '-B', db, '-e', statement])
    if result.returncode:
        raise RuntimeError(f'{db}: {result.stderr}')
    return result.stdout.strip()


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Server:
    def __init__(self, env):
        sock = socket.socket(); sock.bind(('127.0.0.1', 0))
        self.port = sock.getsockname()[1]; sock.close()
        self.log = tempfile.TemporaryFile()
        self.proc = subprocess.Popen(
            [PHP, '-S', f'127.0.0.1:{self.port}', '-t', 'public', 'public/router.php'],
            cwd=ROOT, env=env, stdout=self.log, stderr=self.log)
        self.opener = build_opener(HTTPCookieProcessor(), NoRedirect())
        for _ in range(100):
            try:
                socket.create_connection(('127.0.0.1', self.port), timeout=0.2).close()
                return
            except OSError:
                time.sleep(0.1)
        raise RuntimeError('server did not start')

    def raw(self, path, data=None):
        url = f'http://127.0.0.1:{self.port}{path}'
        body = urlencode(data).encode() if data is not None else None
        request = Request(url, data=body, method='POST' if data is not None else 'GET')
        try:
            with self.opener.open(request, timeout=30) as response:
                return response.status, response.read().decode(), dict(response.headers)
        except HTTPError as error:
            return error.code, error.read().decode(), dict(error.headers)

    def guest(self, path):
        """One request with a fresh cookie jar: a visitor who is not signed in."""
        opener = build_opener(HTTPCookieProcessor(), NoRedirect())
        url = f'http://127.0.0.1:{self.port}{path}'
        try:
            with opener.open(Request(url), timeout=30) as response:
                return response.status, response.read().decode(), dict(response.headers)
        except HTTPError as error:
            return error.code, error.read().decode(), dict(error.headers)

    def get(self, path):
        return self.raw(path)

    def post(self, path, data):
        return self.raw(path, data)

    def close(self):
        self.proc.terminate()
        self.proc.wait(timeout=10)


def token(html):
    match = re.search(r'name="_csrf" value="([^"]+)"', html)
    return match.group(1) if match else None


def main():
    suffix = uuid.uuid4().hex[:12]
    db = f'massage_booking_test_{suffix}'
    db_user = 'root'
    env_file = ROOT / '.env'
    if env_file.is_file():
        match = re.search(r'^DB_USERNAME=(.*)$', env_file.read_text(), re.M)
        if match and match.group(1).strip():
            db_user = match.group(1).strip()
    sql('mysql', f'DROP DATABASE IF EXISTS {db}')
    sql('mysql', f'CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; '
                 f"GRANT ALL ON {db}.* TO '{db_user}'@'localhost'")

    env = os.environ.copy()
    # Both section variables point at the scratch database: the install runs in
    # two-section mode (the real deployment shape) and never touches .env's DBs.
    env.update({'DB_DATABASE': db, 'DB_DATABASE_MEN': db, 'DB_DATABASE_WOMEN': db, 'APP_ENV': 'test'})
    installed = run([PHP, 'bin/console', 'install'], env=env)
    check(installed.returncode == 0, f'install failed: {installed.stderr[-400:]}')

    # Two therapists, every day 09:00-17:00, both able to do every service.
    sql(db, """INSERT INTO therapists (name, code, status, service_ids, working_schedule, salary_model)
               VALUES ('درمانگر الف', 'T-A', 'active', '[1,2,3]',
                       '{"sat":"09-17","sun":"09-17","mon":"09-17","tue":"09-17","wed":"09-17","thu":"09-17","fri":"09-17"}', 'percentage'),
                      ('درمانگر ب', 'T-B', 'active', '[1,2,3]',
                       '{"sat":"09-17","sun":"09-17","mon":"09-17","tue":"09-17","wed":"09-17","thu":"09-17","fri":"09-17"}', 'percentage')""")
    sql(db, """INSERT INTO services (name, default_price, duration_minutes, status)
               VALUES ('ماساژ تست ۶۰', 1000000, 60, 'active'),
                      ('ماساژ تست ۹۰', 1500000, 90, 'active'),
                      ('ماساژ غیرفعال', 900000, 60, 'inactive')""")
    service_id = int(sql(db, "SELECT id FROM services WHERE name='ماساژ تست ۶۰'"))
    tomorrow = (date.today() + timedelta(days=1)).isoformat()

    server = Server(env)
    try:
        # --- the page only offers what the panel says ---------------------------
        status, chooser, _ = server.get('/index.php?r=booking')
        check(status == 200, 'the booking page renders')
        check('انتخاب این مرکز' in chooser, 'a two-section install asks which center first')
        check('ماساژ تست ۶۰' not in chooser, 'no service is listed before a center is chosen')

        status, page, _ = server.get('/index.php?r=booking&section=men')
        check(status == 200, 'booking page renders for one center')
        check('ماساژ تست ۶۰' in page, 'active services are offered')
        check('ماساژ غیرفعال' not in page, 'inactive services are not offered')

        status, page, _ = server.get(f'/index.php?r=booking&section=men&service={service_id}&date={tomorrow}')
        check(status == 200, 'slot list renders')
        slots = re.findall(r'name="time" value="(\d{2}:\d{2}:\d{2})"', page)
        check(len(slots) > 0, 'free slots exist for a working day with two therapists')
        check(slots[0] == '09:00:00', f'the first slot is the shift start (got {slots[0]})')
        check(slots[-1] == '16:00:00', f'the last 60-minute slot ends at 17:00 (got {slots[-1]})')
        check('17:00:00' not in slots, 'a slot that would run past closing is not offered')

        # --- a booking is created with server-side values ----------------------
        csrf = token(page)
        check(csrf is not None, 'the booking form carries a CSRF token')
        status, done, _ = server.post('/index.php?r=booking', {
            '_csrf': csrf, 'section': 'men', 'service_id': service_id, 'date': tomorrow,
            'time': '10:00:00', 'name': 'مهمان آزمایشی', 'mobile': '09121234567',
            'note': 'کمر درد دارم',
        })
        if 'نوبت شما ثبت شد' not in done:
            errors = re.findall(r'<li>([^<]+)</li>', done)
            raise AssertionError(f'the booking was rejected: {errors} | status={status}')
        check(status == 200, 'the booking is accepted')
        reference = re.search(r'کد پیگیری: <strong dir="ltr">([A-F0-9]+)</strong>', done)
        check(reference is not None, 'a booking reference is shown to the guest')
        reference = reference.group(1)

        check(sql(db, "SELECT COUNT(*) FROM appointments WHERE source='online'") == '1',
              'exactly one online appointment exists')
        row = sql(db, f"SELECT CONCAT(status,'|',DATE_FORMAT(start_time,'%H:%i'),'|',DATE_FORMAT(end_time,'%H:%i'),'|',booking_ref,'|',service_id) FROM appointments WHERE source='online'")
        check(row == f'pending|10:00|11:00|{reference}|{service_id}', f'unexpected appointment row: {row}')
        check(sql(db, "SELECT COUNT(*) FROM customers") == '1', 'the guest became a customer')
        check(sql(db, "SELECT status FROM customers") == 'new', 'the guest customer starts as new')

        # --- the reference can be tracked without knowing the centre --------
        status, track_form, _ = server.guest('/index.php?r=booking-status')
        check(status == 200 and 'کد پیگیری نوبت' in track_form, 'the tracking page offers a code input')
        check('name="ref"' in track_form, 'the tracking form posts a ref field')

        status, tracked, _ = server.guest(f'/index.php?r=booking-status&ref={reference}')
        check(status == 200 and 'وضعیت نوبت' in tracked, 'a known code shows the booking status')
        check('در انتظار تأیید' in tracked, 'the pending status is shown')
        check('ماساژ تست ۶۰' in tracked, 'the booked service is shown')

        status, missing, _ = server.guest('/index.php?r=booking-status&ref=ZZZZZZ')
        check('پیدا نشد' in missing, 'an unknown code says so instead of failing silently')

        # --- capacity is respected across therapists -------------------------
        # A second therapist is still free at 10:00, so the time is offered again.
        status, page, _ = server.get(f'/index.php?r=booking&section=men&service={service_id}&date={tomorrow}')
        slots_now = re.findall(r'name="time" value="(\d{2}:\d{2}:\d{2})"', page)
        check('10:00:00' in slots_now, 'a second therapist keeps 10:00 bookable')

        second = server.post('/index.php?r=booking', {
            '_csrf': csrf, 'section': 'men', 'service_id': service_id, 'date': tomorrow,
            'time': '10:00:00', 'name': 'مهمان دوم', 'mobile': '09129999999',
        })[1]
        check('نوبت شما ثبت شد' in second, 'the second guest takes the second therapist at the same time')
        check(sql(db, "SELECT COUNT(*) FROM appointments WHERE source='online'") == '2',
              'two appointments exist at the same hour')

        # Both therapists are now busy 10:00-11:00: that hour and everything that
        # would overlap it must disappear from the page.
        status, page, _ = server.get(f'/index.php?r=booking&section=men&service={service_id}&date={tomorrow}')
        slots_now = re.findall(r'name="time" value="(\d{2}:\d{2}:\d{2})"', page)
        for gone in ('09:30:00', '10:00:00', '10:30:00'):
            check(gone not in slots_now, f'{gone} overlaps two busy therapists and must not be offered')
        check('11:00:00' in slots_now, 'a slot right after the bookings is still free')

        third = server.post('/index.php?r=booking', {
            '_csrf': csrf, 'section': 'men', 'service_id': service_id, 'date': tomorrow,
            'time': '10:00:00', 'name': 'مهمان سوم', 'mobile': '09128888888',
        })[1]
        check('خالی نیست' in third, 'a fully booked slot is rejected on submit')
        check(sql(db, "SELECT COUNT(*) FROM appointments WHERE source='online'") == '2',
              'a rejected submission writes nothing')

        bad_mobile = server.post('/index.php?r=booking', {
            '_csrf': csrf, 'section': 'men', 'service_id': service_id, 'date': tomorrow,
            'time': '13:00:00', 'name': 'مهمان چهارم', 'mobile': '123',
        })[1]
        check('موبایل' in bad_mobile, 'an invalid mobile is rejected')

        past = server.post('/index.php?r=booking', {
            '_csrf': csrf, 'section': 'men', 'service_id': service_id, 'date': '2020-01-01',
            'time': '13:00:00', 'name': 'مهمان پنجم', 'mobile': '09121112222',
        })[1]
        check('تاریخ' in past, 'a past date is rejected')

        # --- the guest can follow the booking ---------------------------------
        status, status_page, _ = server.guest(f'/index.php?r=booking&section=men&ref={reference}')
        check(status == 200 and 'در انتظار تأیید' in status_page, 'the guest sees the pending status')
        status, unknown, _ = server.guest('/index.php?r=booking&section=men&ref=ZZZZZZ')
        check('پیدا نشد' in unknown, 'an unknown reference is answered honestly')

        # --- staff confirm it from the panel ----------------------------------
        _, login_page, _ = server.get('/index.php?r=login')
        probe = re.search(r'name="_probe" value="([^"]+)"', login_page)
        check(probe is not None, 'the login form carries a session probe')
        logged_in = server.post('/index.php?r=login', {
            'tenant': 'men', 'email': 'admin@example.com', 'password': 'password',
            '_probe': probe.group(1), '_csrf': token(login_page) or '',
        })
        check(logged_in[0] == 302, f'the default admin can sign in on a fresh install (got {logged_in[0]})')

        status, panel, _ = server.get('/index.php?r=online')
        check(status == 200, 'the online bookings panel renders')
        check(reference in panel, 'the pending booking is listed')
        check('مهمان آزمایشی' in panel, 'the guest name is shown to staff')
        check('name="action" value="confirm"' in panel, 'staff get a confirm action')
        check('name="action" value="cancel"' in panel, 'staff get a cancel action')

        appointment_id = sql(db, f"SELECT id FROM appointments WHERE booking_ref='{reference}'")
        status, confirm_body, _ = server.post('/index.php?r=online', {'_csrf': token(panel), 'id': appointment_id, 'action': 'confirm'})
        check(status == 302, f'confirming redirects back to the panel (got {status}: {confirm_body[:200]})')
        check(sql(db, f"SELECT status FROM appointments WHERE id={appointment_id}") == 'confirmed',
              'the appointment is confirmed in the database')

        # The guest session is separate from the staff session used above.
        status, status_page, _ = server.guest(f'/index.php?r=booking&section=men&ref={reference}')
        check('تأیید شده' in status_page, f'the guest now sees the confirmed status (got {status})')

        # --- public pages never leak the customer list ------------------------
        _, home, _ = server.get('/index.php?r=home')
        check('مهمان آزمایشی' not in home and '09121234567' not in home,
              'the public site never exposes a guest name or mobile')
    finally:
        server.close()
        sql('mysql', f'DROP DATABASE IF EXISTS {db}')

    print(f'OK: {assertions} online-booking HTTP/MySQL assertions')


if __name__ == '__main__':
    main()
