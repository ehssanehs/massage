#!/usr/bin/env python3
"""Deployment check: the app must work from a subdirectory and on servers without rewrites.

Reproduces the two ways "the site loads but staff login 404s":

  1. the project is served from a SUBDIRECTORY of the document root, so every
     root-absolute link ("/index.php?...", "/assets/...") points above the app;
  2. the server cannot rewrite URLs (nginx without try_files, Apache with
     AllowOverride None), so the bare-path section entry point does not exist.

Copies the working tree into a temporary document root, then crawls every link
the home page and the login page emit and requires each one to resolve. Uses
scratch databases for the two-section mode and drops them afterwards; .env and
the real database are never touched.
"""
import os, re, shutil, socket, subprocess, tempfile, time, uuid
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request
from urllib.parse import urlencode, urljoin, urlparse
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php8.2')
assertions = 0


def check(condition, message):
    global assertions
    assertions += 1
    if not condition:
        raise AssertionError(message)


def run(args, cwd=None, **kw):
    return subprocess.run(args, cwd=cwd or ROOT, text=True, capture_output=True, **kw)


def sql(db, statement):
    result = run(['mysql', '-N', '-B', db, '-e', statement])
    if result.returncode:
        raise RuntimeError(f'{db}: {result.stderr}')
    return result.stdout.strip()


def db_user():
    env_file = ROOT / '.env'
    if env_file.is_file():
        match = re.search(r'^DB_USERNAME=(.*)$', env_file.read_text(), re.M)
        if match and match.group(1).strip():
            return match.group(1).strip()
    return 'root'


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Server:
    """A deliberately rewrite-free server: document root only, no router script."""

    def __init__(self, docroot, env):
        sock = socket.socket(); sock.bind(('127.0.0.1', 0))
        self.port = sock.getsockname()[1]; sock.close()
        self.log = tempfile.TemporaryFile()
        self.proc = subprocess.Popen([PHP, '-S', f'127.0.0.1:{self.port}', '-t', str(docroot)],
            cwd=docroot, env=env, stdout=self.log, stderr=self.log)
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
        try:
            response = self.opener.open(f'http://127.0.0.1:{self.port}{path}', timeout=20)
        except HTTPError as e:
            response = e
        return response.code, response.read().decode(), response.headers

    def post(self, path, data):
        body = urlencode(data).encode()
        try:
            response = self.opener.open(Request(f'http://127.0.0.1:{self.port}{path}', data=body), timeout=20)
        except HTTPError as e:
            response = e
        return response.code, response.read().decode(), response.headers

    def close(self):
        self.proc.terminate(); self.proc.wait(timeout=10); self.log.close()


def links(html, page_path):
    """In-app links only: absolute URLs and the navigation chrome are skipped."""
    out = []
    for href in re.findall(r'href="([^"]+)"', html):
        if href.startswith(('http://', 'https://', '#', 'mailto:', 'tel:', '//')):
            continue
        out.append(href.replace('&amp;', '&'))
    return out


def main():
    suffix = uuid.uuid4().hex[:10]
    men_db = f'massage_deploy_men_{suffix}'
    women_db = f'massage_deploy_women_{suffix}'
    tmp = Path(tempfile.mkdtemp(prefix='massage-deploy-'))
    server = None
    try:
        # The working tree, mounted one level below the document root.
        app_dir = tmp / 'massage'
        shutil.copytree(ROOT, app_dir, ignore=shutil.ignore_patterns('.git', 'node_modules', 'storage/sessions', '*.log'))
        check((app_dir / 'public' / 'men' / 'index.php').is_file(), 'the men entry point ships as a real file')
        check((app_dir / 'public' / 'women' / 'index.php').is_file(), 'the women entry point ships as a real file')

        user = db_user()
        for db in (men_db, women_db):
            run(['mysql', '-e', f"CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
                                f"GRANT ALL ON {db}.* TO '{user}'@'localhost'"], check=True)

        env = {**os.environ, 'DB_DATABASE_MEN': men_db, 'DB_DATABASE_WOMEN': women_db}
        installed = run([PHP, 'bin/console', 'install'], env={**env, 'DB_DATABASE_MEN': men_db, 'DB_DATABASE_WOMEN': women_db}, cwd=app_dir)
        check(installed.returncode == 0, f'install in the copied tree: {installed.stderr}')

        # The copy keeps its own .env pointing at the real database; the server
        # env below overrides both section names, so nothing else is touched.
        server = Server(tmp, env)
        base = '/massage/public'
        prefix = f'http://127.0.0.1:{server.port}'

        # --- the reported symptom: home loads, its links do not -----------------
        status, home, _ = server.get(f'{base}/')
        check(status == 200, 'the home page loads from a subdirectory')
        emitted = links(home, base)
        check(emitted, 'the home page emits in-app links')
        check(any(h.startswith(base + '/') for h in emitted),
              f'links keep the subdirectory prefix (got {emitted[:3]})')
        check(not any(h.startswith('/index.php') for h in emitted),
              'no link points at the document root any more')
        for href in emitted:
            code, _, _ = server.get(href)
            check(code == 200, f'{href} resolves (got {code})')

        # --- the staff entry points, on a server with no rewrite at all ---------
        for section in ('men', 'women'):
            path = f'{base}/{section}/index.php?r=login'
            code, body, _ = server.get(path)
            check(code == 200, f'{path} resolves without any URL rewriting (got {code})')
            check('ورود پرسنل' in body, f'{section} login page renders')
            check('name="tenant"' in body, f'{section} login page offers the section selector')

        # A section URL that only exists through rewriting must still work when
        # the directory exists, e.g. the directory form without index.php.
        code, _, _ = server.get(f'{base}/men/')
        check(code == 200, 'the directory form /men/ also resolves')

        # --- and the login actually works from the subdirectory ----------------
        status, body, _ = server.get(f'{base}/men/index.php?r=login')
        token = re.search(r'name="_csrf" value="([^"]+)"', body)[1]
        probe = re.search(r'name="_probe" value="([^"]+)"', body)
        data = {'_csrf': token, 'tenant': 'men', 'email': 'admin@example.com', 'password': 'password'}
        if probe:
            data['_probe'] = probe[1]
        status, _, headers = server.post(f'{base}/men/index.php?r=login', data)
        check(status == 302, f'the subdirectory login succeeds (got {status})')
        location = headers.get('Location') or ''
        check(location.startswith(base + '/men/'), f'the redirect stays inside the app (got {location})')
        status, dashboard, _ = server.get(location)
        check(status == 200, 'the dashboard loads after the subdirectory login')
        check('رزرو سریع' in dashboard, 'the dashboard is the real CRM page')

        # Every link on the dashboard must resolve too — this is where a wrong
        # base would surface next. Logout is skipped: it is an action that ends
        # the session (302 by design), not a page to render.
        dashboard_links = [h for h in links(dashboard, base) if 'r=logout' not in h]
        check(any('r=logout' in h for h in links(dashboard, base)), 'the dashboard still offers logout')
        for href in dashboard_links:
            code, _, _ = server.get(href)
            check(code == 200, f'dashboard link {href} resolves (got {code})')

        # Assets must come from the same mount point.
        check(f'src="{base}/assets/js/app.js"' in dashboard, 'assets resolve under the subdirectory')

        print(f'OK: {assertions} subdirectory/no-rewrite deployment assertions ({tmp})')
    finally:
        if server:
            server.close()
        for db in (men_db, women_db):
            run(['mysql', '-e', f'DROP DATABASE IF EXISTS {db}'])
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == '__main__':
    main()
