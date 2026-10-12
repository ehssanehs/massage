#!/usr/bin/env python3
"""Real HTTP/MySQL verification of the public site and the two independent staff sections.

Creates two uniquely named scratch databases, installs each with `bin/console install`
(which must migrate both sections), starts one real PHP server, and proves over real
HTTP that:

  * the public landing page is reachable with no CRM database open,
  * /men and /women are separate entry points that force a section choice,
  * each section authenticates against its OWN database and admins,
  * credentials from one section never work in the other,
  * switching section on an existing cookie drops the previous login.

Never touches .env or the production database; both scratch databases are dropped.
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
    """One server serving BOTH sections; the tenant is chosen per request path."""

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
                with socket.create_connection(('127.0.0.1', self.port), timeout=.2):
                    break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('PHP server not ready')

    def get(self, path):
        return self._open(f'http://127.0.0.1:{self.port}{path}')

    def post(self, path, data):
        return self._open(f'http://127.0.0.1:{self.port}{path}', urlencode(data).encode())

    def _open(self, url, data=None):
        try:
            response = self.opener.open(Request(url, data=data), timeout=20)
        except HTTPError as e:
            response = e
        return response.code, response.read().decode(), response.headers

    def login(self, section, email, password):
        """GET the section login form, then POST it with its own CSRF token."""
        status, body, _ = self.get(f'/{section}/index.php?r=login')
        check(status == 200, f'{section} login form renders')
        check('name="tenant"' in body, f'{section} login asks for the section')
        token = re.search(r'name="_csrf" value="([^"]+)"', body)[1]
        probe = re.search(r'name="_probe" value="([^"]+)"', body)
        data = {'_csrf': token, 'tenant': section, 'email': email, 'password': password}
        if probe:
            data['_probe'] = probe[1]
        return self.post(f'/{section}/index.php?r=login', data)

    def close(self):
        self.proc.terminate(); self.proc.wait(timeout=10); self.log.close()


def main():
    suffix = uuid.uuid4().hex[:10]
    men_db = f'massage_men_test_{suffix}'
    women_db = f'massage_women_test_{suffix}'
    server = None
    try:
        db_user = 'root'
        env_file = ROOT / '.env'
        if env_file.is_file():
            match = re.search(r'^DB_USERNAME=(.*)$', env_file.read_text(), re.M)
            if match and match.group(1).strip():
                db_user = match.group(1).strip()
        for db in (men_db, women_db):
            sql('mysql', f"CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
                         f"GRANT ALL ON {db}.* TO '{db_user}'@'localhost'")

        env = {**os.environ, 'DB_DATABASE_MEN': men_db, 'DB_DATABASE_WOMEN': women_db,
               'SESSION_SECURE': 'false'}

        # --- bin/console install must migrate every configured section ------
        installed = run([PHP, 'bin/console', 'install'], env=env)
        check(installed.returncode == 0, f'install exits 0: {installed.stderr}')
        check('بخش آقایان' in installed.stdout and 'بخش خانم‌ها' in installed.stdout,
              'install reports both sections')
        for db in (men_db, women_db):
            check(sql(db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'") == '1',
                  f'{db} has the users table')
            check(sql(db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='followups'") == '1',
                  f'{db} migrated to the latest schema, not just the first migration')

        # --- one distinct admin per section ---------------------------------
        for db, email in ((men_db, 'boss@men.local'), (women_db, 'boss@women.local')):
            sql(db, f"UPDATE users SET email='{email}' WHERE email='admin@example.com'")
        for section, email, password in (('men', 'boss@men.local', 'MenPass123'),
                                         ('women', 'boss@women.local', 'WomenPass123')):
            result = run([PHP, 'bin/console', 'user:password', email, password, f'--tenant={section}'], env=env)
            check(result.returncode == 0, f'password set for {section}: {result.stderr}')
        check(sql(men_db, "SELECT COUNT(*) FROM users WHERE email='boss@women.local'") == '0',
              'the women admin does not exist in the men database')
        check(sql(women_db, "SELECT COUNT(*) FROM users WHERE email='boss@men.local'") == '0',
              'the men admin does not exist in the women database')

        server = Server(env)

        # --- public landing page ---------------------------------------------
        status, body, _ = server.get('/')
        check(status == 200, 'public home renders at /')
        for text in ('خدمات ماساژ', 'بخش آموزش', 'ورود پرسنل'):
            check(text in body, f'public home shows «{text}»')
        check('href="/index.php?r=login"' in body, 'public home links to the staff login')
        check('href="/index.php?r=public.massage"' in body and 'href="/index.php?r=public.education"' in body,
              'public home offers both main sections')

        for route, title in (('public.massage', 'خدمات ماساژ'), ('public.education', 'بخش آموزش')):
            status, page, _ = server.get(f'/index.php?r={route}')
            check(status == 200 and title in page, f'public section page renders: {route}')
            check('ورود پرسنل' in page, f'{route} still offers the staff entry')
            check('href="/index.php?r=home"' in page, f'{route} links back to the home page')

        # --- /men and /women are real, separate entry points ------------------
        for section in ('men', 'women'):
            status, _, headers = server.get(f'/{section}')
            check(status == 302, f'/{section} redirects to its own login')
            check(headers.get('Location') == f'/{section}/index.php?r=login',
                  f'/{section} keeps the section in the redirect target (got {headers.get("Location")})')

        # --- section choice is enforced ---------------------------------------
        status, body, _ = server.post('/men/index.php?r=login', {'email': 'x@y.z', 'password': 'nope'})
        check(status == 200 and 'انتخاب بخش' in body, 'login without a section is rejected')

        # --- credentials never cross sections ---------------------------------
        for section, email, password in (('men', 'boss@women.local', 'WomenPass123'),
                                         ('women', 'boss@men.local', 'MenPass123'),
                                         ('men', 'boss@men.local', 'WomenPass123')):
            status, body, headers = server.login(section, email, password)
            check(status == 200 and 'اشتباه' in body,
                  f'{section} rejects foreign credentials ({email})')
            check('Location' not in headers, 'a rejected login does not redirect')

        # --- each admin reaches only their own CRM ----------------------------
        for section, email, password, foreign in (('men', 'boss@men.local', 'MenPass123', 'boss@women.local'),
                                                  ('women', 'boss@women.local', 'WomenPass123', 'boss@men.local')):
            status, _, headers = server.login(section, email, password)
            check(status == 302 and headers.get('Location') == f'/{section}/index.php?r=dashboard',
                  f'{section} admin logs in to the {section} dashboard')
            status, body, _ = server.get(f'/{section}/index.php?r=dashboard')
            check(status == 200, f'{section} dashboard renders for its own admin')
            check(foreign not in body, f'{section} dashboard never shows the other section admin')
            check(f'/assets/css/app.css' in body, 'assets resolve through the shared absolute path')
            # Cookies are shared, so moving to the other section must drop the login.
            status, _, headers = server.get(f'/{"women" if section == "men" else "men"}')
            check(status == 302 and 'index.php?r=login' in (headers.get('Location') or ''),
                  'changing section drops the previous login')

        print(f'OK: {assertions} two-section HTTP/MySQL assertions '
              f'({men_db}, {women_db})')
    finally:
        if server:
            server.close()
        for db in (men_db, women_db):
            run(['mysql', '-e', f'DROP DATABASE IF EXISTS {db}'])


if __name__ == '__main__':
    main()
