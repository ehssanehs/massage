#!/usr/bin/env python3
"""Backup round-trip: the dump file `backup:create` writes must restore cleanly.

Regression guard for the corrupted backups that made `mysql < backup.sql` fail
with "ERROR 1064 ... near 'mysqldump: [Warning] Using a password on the command
line interface can be insecure.'": client warnings were redirected into the dump,
so line 1 was not SQL.

Creates two scratch databases (source and restore target), dumps the source,
restores the dump into the target through the real mysql client and requires the
restore to finish without any error, then compares the row counts. Never touches
.env or the production database.
"""
import os, re, shutil, subprocess, tempfile, uuid
from pathlib import Path

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


def main():
    suffix = uuid.uuid4().hex[:10]
    source_db = f'massage_backup_src_{suffix}'
    target_db = f'massage_backup_dst_{suffix}'
    backups = Path(tempfile.mkdtemp(prefix='massage-backups-'))
    try:
        env_file = ROOT / '.env'
        user, password = 'root', ''
        if env_file.is_file():
            text = env_file.read_text()
            found = re.search(r'^DB_USERNAME=(.*)$', text, re.M)
            if found and found.group(1).strip():
                user = found.group(1).strip()
            found = re.search(r'^DB_PASSWORD=(.*)$', text, re.M)
            if found:
                password = found.group(1).strip()
        check(password != '', 'the .env has a password, which is what produced the warning')

        run(['mysql', '-e', f"CREATE DATABASE {source_db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
                            f"GRANT ALL ON {source_db}.* TO '{user}'@'localhost'"], check=True)
        run(['mysql', '-e', f"CREATE DATABASE {target_db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
                            f"GRANT ALL ON {target_db}.* TO '{user}'@'localhost'"], check=True)

        # Source database: the real schema, seeded, plus one recognisable row.
        env = {**os.environ, 'DB_DATABASE': source_db, 'BACKUP_PATH': str(backups)}
        installed = run([PHP, 'bin/console', 'install'], env=env)
        check(installed.returncode == 0, f'install: {installed.stderr}')
        sql(source_db, "INSERT INTO customers (customer_code,first_name,last_name,mobile,registration_date,status) "
                       "VALUES ('C-BKP-1','بانو','آزمایشی','09120000001','2026-03-01','active')")
        before = sql(source_db, 'SELECT COUNT(*) FROM customers')

        created = run([PHP, 'bin/console', 'backup:create'], env=env)
        check(created.returncode == 0, f'backup:create exits 0: {created.stderr}')
        files = sorted(p for p in backups.glob('*.sql'))
        check(len(files) == 1, f'exactly one dump was written (got {[p.name for p in files]})')
        dump = files[0]
        check(dump.stat().st_size > 0, 'the dump is not empty')

        # The file must be SQL from the very first byte.
        text = dump.read_text(encoding='utf-8', errors='replace')
        first_line = next((line for line in text.splitlines() if line.strip()), '')
        check(first_line.startswith('--') or first_line.startswith('/*'),
              f'the dump starts with SQL, not client output (got {first_line[:80]!r})')
        check('mysqldump:' not in text, 'no mysqldump warning leaked into the dump')
        check('Using a password on the command line' not in text, 'the password warning is gone')
        check('CREATE TABLE' in text and 'INSERT INTO' in text, 'the dump carries schema and data')

        # Restore exactly the way a user would.
        restore = subprocess.run(['mysql', '--default-character-set=utf8mb4', '-u', user, f'--password={password}', target_db],
                                 cwd=ROOT, text=True, capture_output=True, input=text)
        check(restore.returncode == 0, f'the restore exits 0: {restore.stderr}')
        check('ERROR' not in restore.stderr, f'the restore prints no error: {restore.stderr}')
        check('1064' not in restore.stderr, 'no syntax error at line 1')

        after = sql(target_db, 'SELECT COUNT(*) FROM customers')
        check(after == before, f'customer rows survive the round-trip ({after} vs {before})')
        check(sql(target_db, "SELECT first_name FROM customers WHERE customer_code='C-BKP-1'") == 'بانو',
              'the restored data is readable with Persian text intact')
        check(sql(target_db, 'SELECT COUNT(*) FROM information_schema.TABLES '
                             'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN '
                             "('customers','followups','massage_sessions','settings')") == '4',
              'the restored schema is complete')

        # The shipped install must not expose dumps through the web root.
        guard = ROOT / 'public' / 'storage' / '.htaccess'
        check(guard.is_file(), 'public/storage/.htaccess ships with the project')
        check('Require all denied' in guard.read_text(), 'it denies access to the backup directory')
        check('storage/backups' in (ROOT / '.env.example').read_text(),
              'the example config keeps new backups outside the web root')

        print(f'OK: {assertions} backup round-trip assertions ({dump.name})')
    finally:
        for db in (source_db, target_db):
            run(['mysql', '-e', f'DROP DATABASE IF EXISTS {db}'])
        shutil.rmtree(backups, ignore_errors=True)


if __name__ == '__main__':
    main()
