#!/usr/bin/env python3
"""Real HTTP/MySQL deposit regression; requires local mysql admin + seeded test auth.
Creates unique scratch databases, never changes .env or the production database.
"""
import os, re, json, socket, subprocess, tempfile, time, uuid
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request
from urllib.parse import urlencode
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[1]
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
        raise RuntimeError(result.stderr)
    return result.stdout.strip()

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

class Server:
    def __init__(self, db):
        sock = socket.socket(); sock.bind(('127.0.0.1', 0))
        self.port = sock.getsockname()[1]; sock.close()
        self.log = tempfile.TemporaryFile()
        self.proc = subprocess.Popen(['php8.2', '-S', f'127.0.0.1:{self.port}', '-t', 'public'], cwd=ROOT,
            env={**os.environ, 'DB_DATABASE': db}, stdout=self.log, stderr=self.log)
        self.opener = build_opener(HTTPCookieProcessor(), NoRedirect())
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', self.port), timeout=.2): break
            except OSError: time.sleep(.05)
        else: raise RuntimeError('PHP server not ready')
    def req(self, route, data=None, **params):
        url = f'http://127.0.0.1:{self.port}/index.php?' + urlencode({'r': route, **params})
        request = Request(url, data=urlencode(data).encode() if data is not None else None)
        try: response = self.opener.open(request, timeout=15)
        except HTTPError as e: response = e
        return response.code, response.read().decode(), response.headers
    def csrf(self, body):
        return re.search(r'name="_csrf" value="([^"]+)"', body)[1]
    def login(self):
        status, body, _ = self.req('login')
        check(status == 200, 'login form')
        status, _, _ = self.req('login', {'_csrf':self.csrf(body), 'email':'admin@example.com', 'password':'password'})
        check(status == 302, 'seeded scratch login')
    def close(self):
        self.proc.terminate(); self.proc.wait(timeout=10); self.log.close()

def main():
    names = []
    servers = []
    try:
        for mode in ['old', 'fresh']:
            db = 'massage_deposit_test_' + uuid.uuid4().hex[:12]
            names.append(db)
            sql('mysql', f"CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON {db}.* TO 'massage_user'@'localhost'")
            env = {**os.environ, 'DB_DATABASE': db}
            if mode == 'old':
                # Pin the pre-deposit baseline so this test remains valid after merge.
                schema = run(['git','show','6e3acf8:database/schema.sql'], check=True).stdout
                run(['mysql', db], input=schema, check=True)
                check(sql(db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='appointments' AND COLUMN_NAME='deposit_amount'") == '0', 'old DB really lacks deposit column')
                before = sql(db, 'SELECT id,customer_id,appointment_date,status,notes FROM appointments ORDER BY id')
                report = run(['php8.2','bin/console','backup:check'], env=env)
                check(report.returncode == 2 and 'deposit_amount' in report.stdout, 'old schema check exits 2')
                server = Server(db); servers.append(server); server.login()
                for route, params in [('appointments',{}), ('appointments.show',{'id':1}), ('appointments.create',{})]:
                    check(server.req(route, **params)[0] == 200, 'old DB renders '+route)
                status, body, _ = server.req('appointments', deposit='1')
                check(status == 400 and 'migrate' in body, 'old deposit filter explains upgrade, not 500')
                _, body, _ = server.req('appointments.create')
                status, body, _ = server.req('appointments.create', {'_csrf':server.csrf(body), 'customer_id':'1', 'therapist_id':'1','service_id':'1','appointment_date':'۱۴۰۵/۰۱/۰۱','start_time':'18','end_time':'19','status':'confirmed','deposit_amount':'500'})
                check(status == 200 and 'migrate' in body, 'old write blocked with migration notice')
                check(sql(db,'SELECT id,customer_id,appointment_date,status,notes FROM appointments ORDER BY id') == before, 'old data unchanged by blocked write')
                server.close(); servers.remove(server)
            else:
                result = run(['php8.2','bin/console','install'], env=env)
                check(result.returncode == 0, 'fresh install: '+result.stderr)
            for _ in range(2):
                result = run(['php8.2','bin/console','migrate'], env=env)
                check(result.returncode == 0, 'repeat migration: '+result.stderr)
            result = run(['php8.2','bin/console','backup:check'], env=env)
            check(result.returncode == 0, 'upgraded schema matches')
            if mode == 'old':
                check(sql(db,'SELECT id,customer_id,appointment_date,status,notes FROM appointments ORDER BY id') == before, 'migration preserves all old appointment data')
            server = Server(db); servers.append(server); server.login()
            _, body, _ = server.req('appointments.create')
            token = server.csrf(body)
            data = {'_csrf':token, 'customer_id':'1','therapist_id':'1','service_id':'1', 'appointment_date':'۱۴۰۵/۰۱/۰۱','start_time':'18','end_time':'19','status':'confirmed','deposit_amount':'۵۰۰۰۰۰٫۵۰'}
            status, body, headers = server.req('appointments.create', data)
            check(status == 302, 'create appointment through HTTP')
            aid = int(re.search(r'[?&]id=(\d+)', headers['Location'])[1])
            check(sql(db, f'SELECT deposit_amount FROM appointments WHERE id={aid}') == '500000.50', 'actual SQL stored received deposit exactly')
            status, body, _ = server.req('appointments.show', id=aid)
            check(status == 200 and 'بیعانه' in body, 'show saved amount')
            status, body, _ = server.req('appointments', deposit='1')
            check(status == 200 and f'appointments.show&id={aid}' in body, 'positive-deposit filter includes row')
            data['deposit_amount'] = '-1'
            status, body, _ = server.req('appointments.edit', data, id=aid)
            check(status == 200 and 'غیرمنفی' in body, 'negative edit refused')
            check(sql(db, f'SELECT deposit_amount FROM appointments WHERE id={aid}') == '500000.50', 'invalid edit preserves previous money')
            data['deposit_amount'] = '0'
            status, body, _ = server.req('appointments.edit', data, id=aid)
            check(status == 302 and sql(db, f'SELECT deposit_amount FROM appointments WHERE id={aid}') == '0.00', 'zero edit stored')
            status, body, _ = server.req('appointments', deposit='1')
            check(status == 200 and f'appointments.show&id={aid}' not in body, 'zero removed from deposit filter')
            for testfile in ['deposit.php','credit.php','followup.php']:
                result = run(['php8.2', 'tests/'+testfile], env=env)
                check(result.returncode == 0 and 'SKIP' not in result.stdout, testfile+': '+result.stdout+result.stderr)
                print(mode, result.stdout.strip())
            server.close(); servers.remove(server)
            print(mode, 'HTTP + SQL + migration checks OK')
        print(f'deposit-http.py: {assertions} assertions OK; unique scratch databases cleaned')
    finally:
        for server in servers: server.close()
        for db in names: sql('mysql', f'DROP DATABASE IF EXISTS {db}')

if __name__ == '__main__': main()
