#!/usr/bin/env python3
"""Real HTTP/MySQL verification of gift cards and the payment callback.

Runs a scratch database and a real server with the test-only "fake" gateway, so
the whole purchase path is exercised without the network:

  * the gift page offers the panel's presets and refuses bad input,
  * a valid order is created pending, then redirected to the gateway,
  * the callback verifies it, marks it paid and shows the code exactly once,
  * a repeated callback never creates a second card or a second payment,
  * staff see it in the panel and can mark it used,
  * the buyer's mobile never appears on a public page.

Never touches .env or the production database; the scratch database is dropped.
"""
import os, re, socket, subprocess, tempfile, time, uuid
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request
from urllib.parse import urlencode, urlsplit
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

    def request(self, path, data=None):
        url = f'http://127.0.0.1:{self.port}{path}'
        body = urlencode(data).encode() if data is not None else None
        req = Request(url, data=body, method='POST' if data is not None else 'GET')
        try:
            with self.opener.open(req, timeout=30) as response:
                return response.status, response.read().decode(), dict(response.headers)
        except HTTPError as error:
            return error.code, error.read().decode(), dict(error.headers)

    def close(self):
        self.proc.terminate()
        self.proc.wait(timeout=10)


def token(html):
    match = re.search(r'name="_csrf" value="([^"]+)"', html)
    return match.group(1) if match else None


def main():
    suffix = uuid.uuid4().hex[:12]
    db = f'massage_gift_test_{suffix}'
    db_user = 'root'
    env_file = ROOT / '.env'
    if env_file.is_file():
        match = re.search(r'^DB_USERNAME=(.*)$', env_file.read_text(), re.M)
        if match and match.group(1).strip():
            db_user = match.group(1).strip()
    sql('mysql', f'DROP DATABASE IF EXISTS {db}')
    sql('mysql', f'CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; '
                 f"GRANT ALL ON {db}.* TO '{db_user}'@'localhost'")

    env = {**os.environ, 'DB_DATABASE': db, 'DB_DATABASE_MEN': db, 'DB_DATABASE_WOMEN': db,
           'PAYMENT_DRIVER': 'fake', 'SESSION_SECURE': 'false'}
    installed = run([PHP, 'bin/console', 'install'], env=env)
    check(installed.returncode == 0, f'install failed: {installed.stderr[-400:]}')
    check(sql(db, "SHOW TABLES LIKE 'gift_cards'") == 'gift_cards', 'the gift cards table exists after install')

    server = Server(env)
    try:
        # --- the page ---------------------------------------------------------
        status, page, _ = server.request('/index.php?r=gift')
        check(status == 200, 'the gift page renders')
        check('name="amount"' in page, 'amount presets are offered')
        check('name="buyer_name"' in page and 'name="buyer_mobile"' in page, 'the buyer fields exist')
        csrf = token(page)
        check(csrf is not None, 'the form carries a CSRF token')

        # --- rejected input never creates a row -------------------------------
        before = sql(db, 'SELECT COUNT(*) FROM gift_cards')
        for payload, expected in (
            ({'buyer_name': 'ب', 'buyer_mobile': '09121234567', 'amount': '1000000'}, 'نام خریدار'),
            ({'buyer_name': 'خریدار تست', 'buyer_mobile': '123', 'amount': '1000000'}, 'موبایل'),
            ({'buyer_name': 'خریدار تست', 'buyer_mobile': '09121234567', 'amount': '1000'}, 'مبلغ'),
        ):
            body = server.request('/index.php?r=gift', {'_csrf': csrf, **payload})[1]
            check(expected in body, f'invalid input is rejected: {expected}')
        check(sql(db, 'SELECT COUNT(*) FROM gift_cards') == before, 'rejected orders write nothing')

        # --- a valid order ----------------------------------------------------
        status, _, headers = server.request('/index.php?r=gift', {
            '_csrf': csrf, 'buyer_name': 'خریدار تست', 'buyer_mobile': '09121234567',
            'amount': '1000000', 'recipient_name': 'مادر', 'message': 'تولدت مبارک',
        })
        check(status == 302, f'a valid order redirects to the gateway (got {status})')
        location = headers.get('Location', '')
        check('payment.callback' in location, 'the redirect points at the callback')
        authority = (re.search(r'Authority=([^&]+)', location) or [None, ''])[1]
        check(authority != '', 'the redirect carries an authority')

        row = sql(db, f"SELECT CONCAT(status,'|',amount,'|',driver,'|',buyer_name) FROM gift_cards WHERE authority='{authority}'")
        check(row.startswith('pending|1000000.00|fake|خریدار تست'), f'unexpected pending row: {row}')

        # --- the callback -----------------------------------------------------
        status, done, _ = server.request(f'/index.php?r=payment.callback&Authority={authority}&Status=OK')
        check(status == 200 and 'کارت هدیهٔ شما آماده است' in done, 'the callback shows the finished card')
        code = (re.search(r'کد کارت هدیه: <strong dir="ltr">([A-Z0-9-]+)</strong>', done) or [None, ''])[1]
        check(re.fullmatch(r'GIFT-[A-Z0-9]{4}-[A-Z0-9]{4}', code or '') is not None, f'a well-formed code is shown ({code})')
        check('۱٬۰۰۰٬۰۰۰' in done or '1000000' in done or '۱,۰۰۰,۰۰۰' in done, 'the amount is shown')

        paid = sql(db, f"SELECT CONCAT(status,'|',IF(paid_at IS NULL,'no','yes'),'|',IF(expires_at IS NULL,'no','yes'),'|',IF(ref_id IS NULL,'no','yes')) FROM gift_cards WHERE code='{code}'")
        check(paid == 'paid|yes|yes|yes', f'the card is paid with a date, an expiry and a reference (got {paid})')

        # --- a repeated callback is harmless ----------------------------------
        status, again, _ = server.request(f'/index.php?r=payment.callback&Authority={authority}&Status=OK')
        check(status == 200 and code in again, 'a repeated callback still shows the same card')
        check(sql(db, 'SELECT COUNT(*) FROM gift_cards') == '1', 'no second card was created')
        check(sql(db, f"SELECT status FROM gift_cards WHERE code='{code}'") == 'paid', 'the status stayed paid')

        # --- a cancelled payment writes nothing -------------------------------
        status, cancelled, _ = server.request('/index.php?r=payment.callback&Authority=UNKNOWN&Status=NOK')
        check('پرداخت انجام نشد' in cancelled or 'لغو' in cancelled, 'a cancelled payment is reported as failed')
        check(sql(db, 'SELECT COUNT(*) FROM gift_cards') == '1', 'a failed payment adds no card')

        # --- the public site never leaks the buyer ----------------------------
        _, home, _ = server.request('/index.php?r=home')
        check('09121234567' not in home and 'خریدار تست' not in home, 'the public home page exposes no buyer data')

        # --- the panel --------------------------------------------------------
        _, login_page, _ = server.request('/index.php?r=login')
        probe = (re.search(r'name="_probe" value="([^"]+)"', login_page) or [None, ''])[1]
        signed_in = server.request('/index.php?r=login', {
            'tenant': 'men', 'email': 'admin@example.com', 'password': 'password',
            '_probe': probe, '_csrf': token(login_page) or '',
        })
        check(signed_in[0] == 302, f'staff can sign in (got {signed_in[0]})')

        status, panel, _ = server.request('/index.php?r=gifts')
        check(status == 200, 'the gift panel renders')
        check(code in panel, 'the sold card is listed')
        check('پرداخت‌شده' in panel, 'the paid status is shown')
        check('استفاده شد' in panel, 'staff can mark the card used')

        card_id = sql(db, f"SELECT id FROM gift_cards WHERE code='{code}'")
        status, _, _ = server.request('/index.php?r=gifts', {'_csrf': token(panel), 'id': card_id, 'action': 'redeem'})
        check(status == 302, 'redeeming redirects back to the panel')
        check(sql(db, f"SELECT status FROM gift_cards WHERE id={card_id}") == 'redeemed', 'the card is marked redeemed')

        status, panel_after, _ = server.request('/index.php?r=gifts')
        check('استفاده‌شده' in panel_after, 'the redeemed status is shown to staff')
    finally:
        server.close()
        sql('mysql', f'DROP DATABASE IF EXISTS {db}')

    print(f'OK: {assertions} gift-card HTTP/MySQL assertions')


if __name__ == '__main__':
    main()
