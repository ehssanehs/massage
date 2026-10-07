#!/usr/bin/env python3
"""Real HTTP/MySQL Arab commission regression on disposable databases."""
import json, os, re, runpy, subprocess, uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
helpers = runpy.run_path(str(ROOT / 'tests/deposit-http.py'))
sql, run, Server = (helpers[key] for key in ('sql', 'run', 'Server'))

def check(condition, message):
    if not condition: raise AssertionError(message)

def commission(db):
    code = "require 'app/bootstrap.php'; echo json_encode(App\\Services\\SalaryService::calculate(1,'2026-10-01','2026-10-31'));"
    result = run(['php8.2', '-r', code], env={**os.environ, 'DB_DATABASE':db})
    check(result.returncode == 0, result.stderr)
    return json.loads(result.stdout)

def main():
    names, servers = [], []
    try:
        for mode in ('upgrade', 'fresh'):
            db = 'massage_arab_test_' + uuid.uuid4().hex[:12]
            names.append(db)
            sql('mysql', f"CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON {db}.* TO 'massage_user'@'localhost'")
            env = {**os.environ, 'DB_DATABASE': db}
            if mode == 'upgrade':
                old = run(['git', 'show', '1f4f10c3a2497db44b7c7e42db07063d4112925e:database/schema.sql'])
                check(old.returncode == 0, old.stderr)
                check(run(['mysql', db], input=old.stdout).returncode == 0, 'old schema import')
                check(sql(db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='is_arab_customer'") == '0', 'old flag absent')
                check(sql(db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='massage_sessions' AND COLUMN_NAME='arab_commission_percent'") == '0', 'old snapshot absent')
                check(run(['php8.2', 'bin/console', 'seed'], env=env).returncode == 0, 'old seed')
                report = run(['php8.2', 'bin/console', 'backup:check'], env=env)
                check(report.returncode == 2 and 'is_arab_customer' in report.stdout and 'arab_commission_percent' in report.stdout, 'old schema reports missing columns')
                old_server = Server(db); servers.append(old_server); old_server.login()
                for route, params in [('settings', {}), ('customers.edit', {'id':1}), ('sessions.create', {}), ('salaries', {})]:
                    check(old_server.req(route, **params)[0] == 200, 'old schema GET '+route)
                _, old_page, _ = old_server.req('customers.edit', id=1)
                code, body, _ = old_server.req('customers.edit', {'_csrf':old_server.csrf(old_page), 'first_name':'تست', 'last_name':'قبل از مهاجرت', 'mobile':'09120000001', 'is_arab_customer':'1'}, id=1)
                check(code == 200 and 'migrate' in body, 'old schema write explains migration')
                check('تست' not in sql(db, 'SELECT first_name FROM customers WHERE id=1'), 'old customer unchanged')
                old_server.close(); servers.remove(old_server)
            else:
                result = run(['php8.2', 'bin/console', 'install'], env=env)
                check(result.returncode == 0, result.stderr)
            for _ in range(2):
                result = run(['php8.2', 'bin/console', 'migrate'], env=env)
                check(result.returncode == 0, result.stderr)
            check(run(['php8.2', 'bin/console', 'backup:check'], env=env).returncode == 0, 'restored schema check')
            server = Server(db); servers.append(server); server.login()
            _, page, _ = server.req('settings')
            token = server.csrf(page)
            code, _, _ = server.req('settings', {'_csrf':token, 'settings[arab_customer_commission_percent]':'۱۲٫۵'})
            check(code == 302, 'percentage setting saved')
            check(sql(db, "SELECT value FROM settings WHERE `key`='arab_customer_commission_percent'") == '12.50', 'persisted rate')
            _, page, _ = server.req('customers.edit', id=1)
            check('name="is_arab_customer"' in page, 'Arab customer checkbox')
            code, _, _ = server.req('customers.edit', {'_csrf':server.csrf(page), 'first_name':'مشتری', 'last_name':'عرب', 'mobile':'09120000001', 'is_arab_customer':'1'}, id=1)
            check(code == 302 and sql(db, 'SELECT is_arab_customer FROM customers WHERE id=1') == '1', 'customer persisted Arab')
            sql(db, "UPDATE therapists SET salary_model='base_plus_percentage', base_salary=100, commission_percentage=30 WHERE id=1")
            def create(customer_id, amount, day):
                _, page, _ = server.req('sessions.create')
                data = {'_csrf':server.csrf(page),'customer_id':str(customer_id), 'therapist_id':'1','service_id':'1','massage_date':f'۱۴۰۵/۰۷/{day}', 'price':str(amount), 'final_amount':str(amount), 'status':'completed', 'payment_status':'paid'}
                code, body, headers = server.req('sessions.create', data)
                check(code == 302, f'session created: {code}, {body[:350]}')
                return int(re.search(r'[?&]id=(\d+)', headers['Location'])[1])
            arab = create(1, 2000, '۱۰')
            ordinary = create(2, 1000, '۱۱')
            check(sql(db, f'SELECT arab_commission_percent FROM massage_sessions WHERE id={arab}') == '12.50', 'Arab snapshot')
            check(sql(db, f'SELECT COALESCE(arab_commission_percent, "NULL") FROM massage_sessions WHERE id={ordinary}') == 'NULL', 'ordinary snapshot NULL')
            result = commission(db)
            check(result['ordinary_commission'] == 300 and result['arab_commission'] == 250 and result['commission'] == 550 and result['payable'] == 650, f'commission must replace ordinary on Arab: {result}')
            _, page, _ = server.req('settings')
            check(server.req('settings', {'_csrf':server.csrf(page), 'settings[arab_customer_commission_percent]':'۵۰'})[0] == 302, 'setting change')
            _, page, _ = server.req('customers.edit', id=1)
            check(server.req('customers.edit', {'_csrf':server.csrf(page), 'first_name':'مشتری', 'last_name':'عرب', 'mobile':'09120000001'}, id=1)[0] == 302, 'customer uncheck')
            check(commission(db)['payable'] == 650, 'historical rate unaffected by setting or customer flag')
            _, page, _ = server.req('sessions.edit', id=arab)
            check(server.req('sessions.edit', {'_csrf':server.csrf(page), **{ 'customer_id':'1', 'therapist_id':'1', 'service_id':'1', 'massage_date':'۱۴۰۵/۰۷/۱۰','price':'2000','final_amount':'2000','status':'completed','payment_status':'paid'}}, id=arab)[0] == 302, 'session edit')
            check(sql(db, f'SELECT arab_commission_percent FROM massage_sessions WHERE id={arab}') == '12.50', 'session edit keeps snapshot')
            server.close(); servers.remove(server)
            print(mode, 'HTTP + SQL commission and historical snapshot OK')
    finally:
        for server in servers: server.close()
        for db in names: sql('mysql', f'DROP DATABASE IF EXISTS {db}')

if __name__ == '__main__': main()
