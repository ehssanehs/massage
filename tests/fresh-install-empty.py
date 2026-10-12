#!/usr/bin/env python3
"""A fresh install must leave the CRM empty — and still be usable.

Guards the requirement that `bin/console install` creates no demo data: only the
minimum needed to log in and operate (roles, one administrator, settings).

Creates a uniquely named scratch database, installs it, checks every business
table is empty, logs in over real HTTP and confirms the panel still renders
(an "empty" install must not be a broken install), then confirms the optional
sample data is only added by the explicit `demo:seed` command.
Never touches .env or the production database.
"""
import os, re, socket, subprocess, tempfile, time, uuid
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request
from urllib.parse import urlencode
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php8.2')
assertions = 0

# Tables a fresh install must not populate. `roles`, `users` and `settings` are
# intentionally absent: they hold the minimum required to run the application.
EMPTY_TABLES = [
    'branches', 'services', 'therapists', 'customers', 'appointments',
    'massage_sessions', 'followups', 'customer_timeline', 'expenses',
    'inventory_items', 'inventory_movements', 'customer_packages',
    'campaigns', 'salary_runs', 'credit_transactions',
]


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

    def close(self):
        self.proc.terminate(); self.proc.wait(timeout=10); self.log.close()


def main():
    db = 'massage_fresh_test_' + uuid.uuid4().hex[:12]
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

        # --- the requirement: no demo rows anywhere -----------------------------
        for table in EMPTY_TABLES:
            count = sql(db, f'SELECT COUNT(*) FROM {table}')
            check(count == '0', f'{table} is empty after install (found {count})')

        # --- and the minimum needed to operate is present ------------------------
        check(sql(db, 'SELECT COUNT(*) FROM roles') == '5', 'the five roles exist')
        check(sql(db, 'SELECT COUNT(*) FROM users') == '1', 'exactly one administrator is created')
        check(sql(db, "SELECT email FROM users WHERE id=1") == 'admin@example.com', 'the administrator is admin@example.com')
        check(sql(db, 'SELECT COUNT(*) FROM settings') >= '1', 'display defaults exist')
        # MySQL compares a JSON column against a string literal as a JSON *string*,
        # so cast explicitly rather than comparing the raw column.
        check(sql(db, "SELECT CAST(permissions AS CHAR) FROM roles WHERE slug='super_admin'") == '["*"]',
              'the super admin role keeps full permissions')

        # An empty install must still be usable.
        server = Server(db)
        status, body, _ = server.req('login')
        check(status == 200, 'the login page renders on a fresh install')
        token = re.search(r'name="_csrf" value="([^"]+)"', body)[1]
        status, _, _ = server.req('login', {'_csrf': token, 'email': 'admin@example.com', 'password': 'password'})
        check(status == 302, 'the administrator can log in')
        for route in ('dashboard', 'customers', 'services', 'followups', 'appointments', 'sessions'):
            code, page, _ = server.req(route)
            check(code == 200, f'{route} renders on an empty database (got {code})')
            check('خطایی رخ داد' not in page, f'{route} does not fall over on empty data')

        # --- sample data only appears when explicitly requested ------------------
        demo = run([PHP, 'bin/console', 'demo:seed'], env=env)
        check(demo.returncode == 0, f'demo:seed exits 0: {demo.stderr}')
        check(sql(db, 'SELECT COUNT(*) FROM services') == '5', 'demo:seed adds the demo services')
        check(sql(db, 'SELECT COUNT(*) FROM customers') == '2', 'demo:seed adds the demo customers')
        check(sql(db, 'SELECT COUNT(*) FROM therapists') == '2', 'demo:seed adds the demo therapists')
        check(run([PHP, 'bin/console', 'demo:seed'], env=env).returncode == 0, 'demo:seed is repeatable')
        check(sql(db, 'SELECT COUNT(*) FROM services') == '5', 'repeating demo:seed does not duplicate rows')

        print(f'OK: {assertions} fresh-install assertions ({db})')
    finally:
        if server:
            server.close()
        run(['mysql', '-e', f'DROP DATABASE IF EXISTS {db}'])


if __name__ == '__main__':
    main()
