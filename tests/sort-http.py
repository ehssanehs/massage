#!/usr/bin/env python3
"""Real paginated sorting integration test on a disposable MySQL database."""
import importlib.util
import os
import re
import uuid
import sys
from pathlib import Path

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('deposit_http', Path(__file__).with_name('deposit-http.py'))
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def main():
    db = 'massage_sort_test_' + uuid.uuid4().hex[:12]
    server = None
    legacy_db = 'massage_sort_test_' + uuid.uuid4().hex[:12]
    legacy_server = None
    try:
        mod.sql('mysql', f"CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON {db}.* TO 'massage_user'@'localhost'")
        result = mod.run(['php8.2', 'bin/console', 'install'], env={**os.environ, 'DB_DATABASE': db})
        assert result.returncode == 0, result.stderr
        values = ','.join(f"('SC{i:02}', 'SortCheck', '{i:02}', '0912000{i:04}', '2026-03-21', '2026-03-21')" for i in range(25))
        mod.sql(db, "INSERT INTO customers (customer_code,first_name,last_name,mobile,registration_date,birth_date) VALUES " + values)
        server = mod.Server(db)
        server.login()
        for direction, expected_first, expected_second in [('asc','SC00','SC20'),('desc','SC24','SC04')]:
            for page, expected in [(1, expected_first), (2, expected_second)]:
                status, body, _ = server.req('customers', q='SortCheck', sort='full_name', dir=direction, page=page)
                assert status == 200, (direction,page,status)
                codes = re.findall(r'<tr><td>(SC\d\d)</td>', body)
                assert len(codes) == (20 if page == 1 else 5), (direction,page,codes)
                assert codes[0] == expected, (direction,page,codes)
                assert f'sort=full_name&amp;dir={direction}' in body, 'pagination must preserve server sort'
                assert 'q=SortCheck' in body, 'pagination must preserve search'
        mod.sql(db, "UPDATE customers SET birth_date=DATE_ADD('2026-03-21', INTERVAL CAST(RIGHT(customer_code,2) AS UNSIGNED) DAY) WHERE customer_code LIKE 'SC%'")
        for direction, expected in [('asc','SC00'),('desc','SC24')]:
            status, body, _ = server.req('customers', q='SortCheck', sort='birth_date', dir=direction)
            assert status == 200 and re.findall(r'<tr><td>(SC\d\d)</td>',body)[0] == expected, ('date', direction)
        # Every advertised sortable header must execute on real MySQL, including aliases.
        import json
        modules = json.loads(mod.run(['php8.2','-r', 'echo json_encode(array_map(fn($d)=>array_keys($d["columns"]), require "config/modules.php"));']).stdout)
        for module, columns in modules.items():
            for column in columns:
                status, body, _ = server.req(module, sort=column, dir='asc')
                assert status == 200 and 'aria-sort="ascending"' in body, (module,column,status,body[-500:])
        status, body, _ = server.req('customers', sort='id;DROP TABLE customers', dir='asc')
        assert status == 200 and 'sort=id%3BDROP' not in body
        assert mod.sql(db,'SELECT COUNT(*) FROM customers WHERE customer_code LIKE "SC%"') == '25'
        mod.sql('mysql', f"CREATE DATABASE {legacy_db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON {legacy_db}.* TO 'massage_user'@'localhost'")
        legacy_schema = mod.run(['git','show','8ffd06b:database/schema.sql'], check=True).stdout
        mod.run(['mysql',legacy_db],input=legacy_schema,check=True)
        legacy_server = mod.Server(legacy_db)
        legacy_server.login()
        status, _, _ = legacy_server.req('customers',sort='credit_balance',dir='asc')
        assert status == 200, 'old backup without credit column must remain sortable without a 500'
        print('sort-http.py: ascending/descending SQL paging, dates, all columns, legacy schema OK')
    finally:
        if server: server.close()
        if legacy_server: legacy_server.close()
        mod.sql('mysql', f'DROP DATABASE IF EXISTS {db}')
        mod.sql('mysql', f'DROP DATABASE IF EXISTS {legacy_db}')

if __name__ == '__main__': main()
