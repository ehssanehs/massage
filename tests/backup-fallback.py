#!/usr/bin/env python3
"""The PHP fallback dumper, tested the way it fails in production.

`mysqldump` is made unreachable by pointing PATH at an empty directory, which is
exactly what a host without the client (or with exec() restricted) looks like.
The backup must still be produced, and it must still restore.

Also proves the dump is not empty, holds the real schema and rows, and that the
result round-trips through `mysql < file`.
"""
import os, shutil, subprocess, tempfile, uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
# Absolute path: the test empties PATH on purpose, so a bare name cannot resolve.
PHP = shutil.which(os.environ.get('PHP_BIN', 'php8.2')) or os.environ.get('PHP_BIN', 'php8.2')
assertions = 0


def check(condition, message):
    global assertions
    assertions += 1
    if not condition:
        raise AssertionError(message)


def run(args, env=None):
    return subprocess.run(args, cwd=ROOT, text=True, capture_output=True, env=env)


def sql(db, statement, database=None):
    command = ['mysql', '-N', '-B']
    if database:
        command.append(database)
    command += ['-e', statement]
    result = run(command)
    if result.returncode:
        raise RuntimeError(f'{db}: {result.stderr}')
    return result.stdout.strip()


def main():
    suffix = uuid.uuid4().hex[:10]
    source = f'massage_bk_src_{suffix}'
    target = f'massage_bk_dst_{suffix}'
    db_user = 'root'
    env_file = ROOT / '.env'
    if env_file.is_file():
        import re
        match = re.search(r'^DB_USERNAME=(.*)$', env_file.read_text(), re.M)
        if match and match.group(1).strip():
            db_user = match.group(1).strip()

    for db in (source, target):
        sql('mysql', f'DROP DATABASE IF EXISTS {db}; CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; '
                     f"GRANT ALL ON {db}.* TO '{db_user}'@'localhost'", database='mysql')

    backup_dir = tempfile.mkdtemp(prefix='massage-backup-test-')
    empty_path = tempfile.mkdtemp(prefix='massage-empty-bin-')

    env = {**os.environ, 'DB_DATABASE': source, 'DB_DATABASE_MEN': '', 'DB_DATABASE_WOMEN': '',
           'BACKUP_PATH': backup_dir, 'PATH': empty_path}

    installed = run([PHP, 'bin/console', 'install'], env=env)
    check(installed.returncode == 0, f'install failed: {installed.stderr[-300:]}')

    sql(source, """INSERT INTO customers (customer_code, first_name, last_name, mobile, status, registration_date)
                   VALUES ('C-1','علی','تست‌پور','09121234567','new',CURDATE()),
                          ('C-2','سارا','نمونه','09129876543','active',CURDATE())""", database=source)
    sql(source, """INSERT INTO services (name, default_price, duration_minutes, status)
                   VALUES ('ماساژ تست', 1500000, 60, 'active')""", database=source)
    expected_customers = sql(source, 'SELECT COUNT(*) FROM customers', database=source)

    result = run([PHP, 'bin/console', 'backup:create'], env=env)
    check(result.returncode == 0, f'backup:create failed without mysqldump: {result.stdout[-300:]} {result.stderr[-300:]}')

    files = sorted(Path(backup_dir).glob('*.sql'))
    check(len(files) == 1, f'exactly one backup file was written (got {len(files)})')
    dump = files[0].read_text(encoding='utf8')

    check(dump.lstrip().startswith('--'), 'the dump starts with a comment, not a warning')
    check('CREATE TABLE `customers`' in dump, 'the schema is present')
    check('INSERT INTO `customers`' in dump, 'the rows are present')
    check('علی' in dump, 'Persian text is written as UTF-8, not escaped bytes')
    check('FOREIGN_KEY_CHECKS=0' in dump, 'foreign keys are suspended for a clean restore')
    check(len(dump) > 1000, 'the dump is not a stub')

    with open(files[0], 'rb') as handle:
        # Imported as root: the scratch database is ours, and the app user is
        # only granted privileges inside the application.
        load = subprocess.run(['mysql', '-uroot', target], stdin=handle, text=False,
                              capture_output=True)
    check(load.returncode == 0, f'restore failed: {load.stderr.decode()[:300]}')

    check(sql(target, 'SELECT COUNT(*) FROM customers', database=target) == expected_customers,
          'the restored row count matches')
    check(sql(target, "SELECT first_name FROM customers WHERE mobile='09121234567'", database=target) == 'علی',
          'the restored data is the same text')

    for db in (source, target):
        sql('mysql', f'DROP DATABASE IF EXISTS {db}', database='mysql')
    for path in (files[0],):
        path.unlink(missing_ok=True)

    print(f'OK: {assertions} backup-fallback assertions')


if __name__ == '__main__':
    main()
