#!/usr/bin/env python3
"""Real HTTP/MySQL verification of the follow-up matrix.

Creates a uniquely named scratch database, seeds customers with 0, 1 and 3
follow-ups, and proves over real HTTP that the pivot is correct: one row per
customer (empty follow-ups included), one column per follow-up turn, each cell
carrying the status, the Jalali due date and the full result text. Also proves
the matrix is read-only and needs the follow-ups permission.

Never touches .env or the production database; the scratch database is dropped.
"""
import os, re, socket, subprocess, tempfile, time, uuid
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


def run(args, **kw):
    return subprocess.run(args, cwd=ROOT, text=True, capture_output=True, **kw)


def sql(db, statement):
    result = run(['mysql', '-N', '-B', db, '-e', statement])
    if result.returncode:
        raise RuntimeError(f'{db}: {result.stderr}')
    return result.stdout.strip()


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Server:
    def __init__(self, db):
        sock = socket.socket(); sock.bind(('127.0.0.1', 0))
        self.port = sock.getsockname()[1]; sock.close()
        self.log = tempfile.TemporaryFile()
        self.proc = subprocess.Popen([PHP, '-S', f'127.0.0.1:{self.port}', '-t', 'public'],
            cwd=ROOT, env={**os.environ, 'DB_DATABASE': db}, stdout=self.log, stderr=self.log)
        self.opener = build_opener(HTTPCookieProcessor(), NoRedirect())
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', self.port), timeout=.2):
                    break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('PHP server not ready')

    def req(self, route, data=None, **params):
        url = f'http://127.0.0.1:{self.port}/index.php?' + urlencode({'r': route, **params})
        request = Request(url, data=urlencode(data).encode() if data is not None else None)
        try:
            response = self.opener.open(request, timeout=20)
        except HTTPError as e:
            response = e
        return response.code, response.read().decode(), response.headers

    def csrf(self, body):
        return re.search(r'name="_csrf" value="([^"]+)"', body)[1]

    def login(self):
        status, body, _ = self.req('login')
        check(status == 200, 'login form renders')
        status, _, _ = self.req('login', {'_csrf': self.csrf(body), 'email': 'admin@example.com', 'password': 'password'})
        check(status == 302, 'seeded admin logs in')

    def close(self):
        self.proc.terminate(); self.proc.wait(timeout=10); self.log.close()


def main():
    db = 'massage_matrix_test_' + uuid.uuid4().hex[:12]
    server = None
    try:
        db_user = 'root'
        env_file = ROOT / '.env'
        if env_file.is_file():
            match = re.search(r'^DB_USERNAME=(.*)$', env_file.read_text(), re.M)
            if match and match.group(1).strip():
                db_user = match.group(1).strip()
        run(['mysql', '-e', f"CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
                            f"GRANT ALL ON {db}.* TO '{db_user}'@'localhost'"], check=True)
        env = {**os.environ, 'DB_DATABASE': db}
        installed = run([PHP, 'bin/console', 'install'], env=env)
        check(installed.returncode == 0, f'install exits 0: {installed.stderr}')

        # Three customers: no follow-up, one follow-up, three follow-ups.
        sql(db, "INSERT INTO customers (customer_code,first_name,last_name,mobile,registration_date,status) VALUES "
                "('C-MX-1','زهرا','کاظمی','09121111111','2026-03-01','active'),"
                "('C-MX-2','محمد','رضایی','09122222222','2026-03-01','active'),"
                "('C-MX-3','سمیرا','شریفی','09123333333','2026-03-01','inactive')")
        ids = dict(line.split('\t') for line in sql(db, "SELECT customer_code, id FROM customers WHERE customer_code LIKE 'C-MX-%'").splitlines())
        sql(db, f"INSERT INTO followups (customer_id,due_date,status,priority,result) VALUES "
                f"({ids['C-MX-1']},'2026-03-21','booked','high','رزرو شد برای 2026-03-25'),"
                f"({ids['C-MX-1']},'2026-04-05','contacted','normal','تماس گرفته شد'),"
                f"({ids['C-MX-1']},'2026-05-01','refused','low','دیگر تماس نگیرید'),"
                f"({ids['C-MX-2']},'2026-03-22','pending','normal',NULL)")

        # Expected empty cells are derived from the real data (the seeder adds its
        # own customers and follow-ups), not hardcoded.
        counts = [int(line.split('\t')[1]) for line in sql(db, "SELECT customer_id, COUNT(*) FROM followups WHERE deleted_at IS NULL GROUP BY customer_id").splitlines() if line.strip()]
        columns = max(counts) if counts else 0
        customer_total = int(sql(db, 'SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL'))
        empty_expected = sum(max(0, columns - c) for c in counts) + (customer_total - len(counts)) * columns

        server = Server(db)

        # The matrix is behind the follow-ups permission.
        status, _, headers = server.req('followups.matrix')
        check(status == 302 and 'r=login' in (headers.get('Location') or ''), 'matrix requires login')

        server.login()
        status, body, _ = server.req('followups.matrix')
        check(status == 200, f'matrix renders for an allowed user ({status})')
        before = sql(db, 'SELECT COUNT(*), MAX(updated_at) FROM followups')

        # Rows: every customer, including the one with no follow-up.
        for name in ('زهرا کاظمی', 'محمد رضایی', 'سمیرا شریفی'):
            check(name in body, f'{name} has a row')

        # Columns: exactly the follow-up turns that exist, plus one extra turn so
        # every row (including the deepest chain) has an empty cell to create in.
        for turn in ('پیگیری ۱', 'پیگیری ۲', 'پیگیری ۳'):
            check(turn in body, f'column {turn} exists')
        check('پیگیری ۴' in body, 'one turn beyond the deepest chain hosts the create button')
        check('پیگیری ۵' not in body, 'but no further empty turns')

        # Cells: status, Jalali date and the FULL result text of each follow-up.
        for label in ('رزرو شد', 'تماس گرفته شد', 'رد کرد', 'در انتظار'):
            check(label in body, f'status «{label}» is rendered')
        check('رزرو شد برای ۱۴۰۵/۰۱/۰۵' in body, 'the full result text is rendered (with its date in Jalali)')
        check('دیگر تماس نگیرید' in body, 'the last follow-up result is rendered too')
        check('بدون نتیجهٔ ثبت‌شده' in body, 'a follow-up without a result says so')
        check('۱۴۰۵/۰۱/۰۱' in body, 'due dates render in Jalali')
        check(body.count('matrix-empty') == empty_expected, f'missing turns stay visibly empty ({body.count("matrix-empty")} vs {empty_expected})')

        # Read-only checks: rendering must not touch a single row.
        check(sql(db, 'SELECT COUNT(*), MAX(updated_at) FROM followups') == before, 'opening the matrix writes nothing')
        check('matrix-result' in body, 'cells show the result')

        # Customer links, and search.
        check(f"r=customers.show&id={ids['C-MX-1']}" in body, 'cells link to the customer file')
        status, filtered, _ = server.req('followups.matrix', q='کاظمی')
        check(status == 200 and 'زهرا کاظمی' in filtered, 'search by name keeps the matching customer')
        check('سمیرا شریفی' not in filtered, 'search drops non-matching customers')
        check('پیگیری ۳' in filtered, 'the column count stays global when a filter narrows the rows')

        status, bad, _ = server.req('followups.matrix', q='x' * 400)
        check(status == 400, 'an oversized search term is rejected')

        # The list page offers the matrix, and the matrix offers the list.
        _, list_page, _ = server.req('followups', filter='all')
        check('r=followups.matrix' in list_page, 'the follow-up list links to the matrix')
        check('r=followups&filter=active' in body, 'the matrix links back to the list')

        # --- recording a result from inside a cell -----------------------------
        pending_id = ids['C-MX-2']  # customer 2 carries the single pending follow-up
        pending = sql(db, f'SELECT id FROM followups WHERE customer_id={pending_id} AND status=\'pending\'')
        check(pending != '', 'the pending follow-up exists')
        token = server.csrf(body)
        status, _, headers = server.req('followups.matrix', {
            '_csrf': token, 'id': pending, 'matrix_action': 'record', 'status': 'booked', 'result': 'رزرو از ماتریس',
        }, q='رضایی', page='1')
        check(status == 302, 'recording from a cell redirects')
        check(sql(db, f"SELECT status,result FROM followups WHERE id={pending}") == 'booked\tرزرو از ماتریس',
              'the result and status are stored')
        check(sql(db, f'SELECT COUNT(*) FROM customer_timeline WHERE entity=\'followups\' AND entity_id={pending} AND title=\'نتیجه پیگیری\'') == '1',
              'the result is written to the customer timeline')
        check(sql(db, f'SELECT COUNT(*) FROM customer_timeline WHERE entity=\'followups\' AND entity_id={pending} AND title=\'پیگیری انجام شد\'') == '1',
              'a booked result records its completion event exactly once')
        location = headers.get('Location') or ''
        check('r=followups.matrix' in location, f'the user returns to the matrix (got {location})')

        # The cell now offers correction instead of recording, and editing works.
        status, refreshed, _ = server.req('followups.matrix', q='رضایی')
        check('value="edit"' in refreshed and 'value="record"' not in refreshed,
              'a recorded follow-up offers editing, not recording')
        token = server.csrf(refreshed)
        status, _, _ = server.req('followups.matrix', {
            '_csrf': token, 'id': pending, 'matrix_action': 'edit', 'status': 'refused', 'result': 'اصلاح از ماتریس',
        }, q='رضایی')
        check(status == 302, 'editing from a cell redirects')
        check(sql(db, f"SELECT status,result FROM followups WHERE id={pending}") == 'refused\tاصلاح از ماتریس',
              'the correction is stored')
        check(sql(db, f'SELECT COUNT(*) FROM customer_timeline WHERE entity=\'followups\' AND entity_id={pending} AND title=\'اصلاح نتیجه پیگیری\'') == '1',
              'the correction is recorded in the timeline')
        check(sql(db, f"SELECT COUNT(*) FROM audit_logs WHERE action='followups.edit' AND entity_id={pending}") == '1',
              'the correction is audited')

        # Recording an already-recorded row must not create a second result.
        token = server.csrf(refreshed)
        status, _, _ = server.req('followups.matrix', {
            '_csrf': token, 'id': pending, 'matrix_action': 'record', 'status': 'booked', 'result': 'دوباره',
        })
        check(status == 302, 'the invalid recording is answered with a redirect, not an error page')
        check(sql(db, f"SELECT COUNT(*) FROM customer_timeline WHERE entity='followups' AND entity_id={pending} AND title='پیگیری انجام شد'") == '1',
              'a refused recording adds no completion event')

        # --- manual creation from the follow-up centre --------------------------
        # filter=all so the page lists rows whatever their status (this scratch
        # database only holds the follow-ups this suite created).
        status, centre, _ = server.req('followups', filter='all')
        check(status == 200, 'the follow-up centre renders')
        check('create_followup' in centre, 'the centre offers the manual-create form')
        check('followup-create-form' in centre, 'and a per-row create form')

        before_centre = int(sql(db, "SELECT COUNT(*) FROM followups WHERE customer_id=%s" % ids['C-MX-3']))
        token = server.csrf(centre)
        status, _, headers = server.req('followups', {
            '_csrf': token, 'create_followup': '1', 'customer_id': ids['C-MX-3'],
            'due_date': '۱۴۰۵/۰۳/۰۱', 'priority': 'high', 'description': 'پیگیری دستی از مرکز',
        }, filter='all')
        check(status == 302, 'creating from the centre redirects')
        after_centre = int(sql(db, "SELECT COUNT(*) FROM followups WHERE customer_id=%s" % ids['C-MX-3']))
        check(after_centre == before_centre + 1, 'exactly one follow-up is created')
        new_id = sql(db, "SELECT id FROM followups WHERE customer_id=%s AND description='پیگیری دستی از مرکز'" % ids['C-MX-3'])
        check(new_id != '', 'the new follow-up exists')
        check(sql(db, f"SELECT status,priority FROM followups WHERE id={new_id}") == 'pending\thigh',
              'a manual follow-up starts pending with the chosen priority')
        expected_due = run([PHP, '-r', "require 'app/bootstrap.php'; echo App\\Support\\Jalali::toGregorian('1405/03/01');"], env=env).stdout.strip()
        check(sql(db, f'SELECT due_date FROM followups WHERE id={new_id}') == expected_due,
              'the Jalali due date is stored as the matching Gregorian date')
        check(sql(db, f"SELECT COUNT(*) FROM customer_timeline WHERE entity='followups' AND entity_id={new_id} AND title='پیگیری دستی ثبت شد'") == '1',
              'scheduling it is recorded in the customer timeline')
        check(sql(db, f"SELECT COUNT(*) FROM audit_logs WHERE action='followups.create' AND entity_id={new_id}") == '1',
              'creating it is audited')

        # An unusable date must not create a row.
        token = server.csrf(centre)
        status, _, _ = server.req('followups', {
            '_csrf': token, 'create_followup': '1', 'customer_id': ids['C-MX-3'],
            'due_date': '۱۴۰۵/۱۳/۴۰', 'description': 'تاریخ خراب',
        }, filter='all')
        check(status == 302, 'an invalid date is answered with a redirect')
        check(sql(db, "SELECT COUNT(*) FROM followups WHERE description='تاریخ خراب'") == '0',
              'nothing is created for an invalid date')

        # --- manual creation from a matrix cell ---------------------------------
        status, matrix_page, _ = server.req('followups.matrix', q='شریفی')
        check('matrix_action" value="create"' in matrix_page, 'the matrix offers a create cell')
        check('ایجاد پیگیری' in matrix_page, 'the cell is labelled for the user')
        token = server.csrf(matrix_page)
        status, _, _ = server.req('followups.matrix', {
            '_csrf': token, 'matrix_action': 'create', 'customer_id': ids['C-MX-3'],
            'due_date': '۱۴۰۵/۰۴/۱۵', 'priority': 'low', 'description': 'پیگیری دستی از ماتریس',
        }, q='شریفی')
        check(status == 302, 'creating from a matrix cell redirects')
        matrix_id = sql(db, "SELECT id FROM followups WHERE customer_id=%s AND description='پیگیری دستی از ماتریس'" % ids['C-MX-3'])
        check(matrix_id != '', 'the matrix creates a follow-up for that customer')
        check(sql(db, f"SELECT priority FROM followups WHERE id={matrix_id}") == 'low', 'with its own priority')
        status, matrix_after, _ = server.req('followups.matrix', q='شریفی')
        # A matrix cell shows status, due date and result — descriptions are not
        # rendered there, so the new rows are found by their due dates.
        check('۱۴۰۵/۰۴/۱۵' in matrix_after, 'the follow-up created from the matrix shows in the row')
        check('۱۴۰۵/۰۳/۰۱' in matrix_after, 'alongside the one created from the centre')
        check(matrix_after.count('در انتظار') >= 2, 'both new follow-ups render as pending')

        print(f'OK: {assertions} follow-up matrix HTTP/MySQL assertions ({db})')
    finally:
        if server:
            server.close()
        run(['mysql', '-e', f'DROP DATABASE IF EXISTS {db}'])


if __name__ == '__main__':
    main()
