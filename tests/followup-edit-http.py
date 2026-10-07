#!/usr/bin/env python3
"""Edit a completed followup over real HTTP/MySQL in a disposable database."""
import importlib.util
import os
import re
import sys
import uuid
from pathlib import Path

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('deposit_http', Path(__file__).with_name('deposit-http.py'))
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def main():
    db = 'massage_followup_test_' + uuid.uuid4().hex[:12]
    server = None
    try:
        mod.sql('mysql', f"CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON {db}.* TO 'massage_user'@'localhost'")
        result = mod.run(['php8.2', 'bin/console', 'install'], env={**os.environ, 'DB_DATABASE': db})
        assert result.returncode == 0, result.stderr
        server = mod.Server(db)
        server.login()
        status, page, _ = server.req('followups', filter='active')
        assert status == 200
        token = server.csrf(page)
        status, _, _ = server.req('followups', {'_csrf': token, 'id': '1', 'status': 'booked', 'result': 'رزرو اولیه'}, filter='active')
        assert status == 302
        assert mod.sql(db, 'SELECT status,result FROM followups WHERE id=1') == 'booked\tرزرو اولیه'
        status, page, _ = server.req('followups', filter='done')
        assert status == 200 and 'ویرایش' in page and 'value="رزرو اولیه"' in page
        token = server.csrf(page)
        count_before = mod.sql(db, 'SELECT COUNT(*) FROM followups')
        status, _, _ = server.req('followups', {'_csrf': token, 'id': '1', 'edit_result': '1', 'status': 'refused', 'result': 'تماس اصلاح شد', 'refollow_days': '7'}, filter='done')
        assert status == 302
        assert mod.sql(db, 'SELECT status,result FROM followups WHERE id=1') == 'refused\tتماس اصلاح شد'
        assert mod.sql(db, 'SELECT COUNT(*) FROM followups') == count_before
        assert mod.sql(db, "SELECT COUNT(*) FROM customer_timeline WHERE entity='followups' AND entity_id=1 AND title='اصلاح نتیجه پیگیری'") == '1'
        status, page, _ = server.req('followups', filter='done')
        assert status == 200 and 'تماس اصلاح شد' in page and 'value="تماس اصلاح شد"' in page
        status, profile, _ = server.req('customers.show', id=1)
        assert status == 200 and 'نتیجه قبلی؛ بعداً اصلاح شد' in profile and 'وضعیت: رد کرد' in profile
        token = server.csrf(page)
        status, _, _ = server.req('followups', {'_csrf': token, 'id': '1', 'edit_result': '1', 'status': 'requested_later', 'result': 'تماس بعدی'}, filter='done')
        assert status == 302
        status, page, _ = server.req('followups', filter='done')
        assert status == 200 and 'value="تماس بعدی"' in page and 'name="edit_result" value="1"' in page
        status, active, _ = server.req('followups', filter='active')
        assert status == 200 and 'تماس بعدی' not in active
        token = server.csrf(page)
        status, _, _ = server.req('followups', {'_csrf': token, 'id': '1', 'edit_result': '1', 'status': 'pending', 'result': ''}, filter='done')
        assert status == 302
        assert mod.sql(db, 'SELECT status,contacted_at IS NULL FROM followups WHERE id=1') == 'pending\t1'
        status, page, _ = server.req('followups', filter='active')
        assert status == 200 and 'پیگیری بازگشت مشتری VIP' in page
        mod.sql(db, 'UPDATE followups SET deleted_at=NOW() WHERE id=1')
        before = mod.sql(db, "SELECT COUNT(*) FROM customer_timeline WHERE entity='followups' AND entity_id=1")
        status, _, _ = server.req('followups', {'_csrf': token, 'id': '1', 'edit_result': '1', 'status': 'booked', 'result': 'نباید ثبت شود'}, filter='done')
        assert status == 302
        assert mod.sql(db, 'SELECT status FROM followups WHERE id=1') == 'pending'
        assert mod.sql(db, "SELECT COUNT(*) FROM customer_timeline WHERE entity='followups' AND entity_id=1") == before
        print('followup-edit-http.py: completed edit, correction, timeline, reactivation, deleted guard OK')
    finally:
        if server: server.close()
        mod.sql('mysql', f'DROP DATABASE IF EXISTS {db}')


if __name__ == '__main__': main()
