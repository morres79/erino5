"""Prepare an isolated development database. Run only with explicit server-write approval."""
import argparse
import getpass
import json
import os
from pathlib import Path
import secrets
import subprocess

p = argparse.ArgumentParser()
p.add_argument('--mysql', required=True)
p.add_argument('--php', required=True)
p.add_argument('--extension-dir', required=True)
p.add_argument('--host', required=True)
p.add_argument('--user', required=True)
p.add_argument('--admin-email', required=True)
p.add_argument('--private-dir', required=True)
a = p.parse_args()
root = Path(__file__).resolve().parents[1]
private = Path(a.private_dir).resolve()
if private.is_relative_to(root):
    raise SystemExit('Private settings must be outside the public website directory.')
private.mkdir(parents=True, exist_ok=True)
configfile = private / 'database.php'
adminfile = private / 'initial-admin.txt'
if configfile.exists() or adminfile.exists():
    raise SystemExit('Private files already exist. Refusing to overwrite credentials.')
password = getpass.getpass('Database management password (hidden): ')
app_password = secrets.token_urlsafe(32)
client = [a.mysql, '--no-defaults', '--protocol=TCP', '--host=' + a.host, '--port=3306', '--user=' + a.user, '--ssl-mode=REQUIRED', '--connect-timeout=10', '--batch', '--raw', '--skip-column-names', '--default-character-set=utf8mb4']
env = os.environ.copy()
env['MYSQL_PWD'] = password

def sql(statement):
    result = subprocess.run(client, input=statement.encode('utf-8'), env=env, capture_output=True, timeout=30)
    if result.returncode:
        raise RuntimeError(result.stderr.decode(errors='replace').replace(password, '[redacted]').replace(app_password, '[redacted]'))
    return result.stdout.decode('utf-8')

try:
    if sql("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = 'erino5';").strip() != '0':
        raise RuntimeError('Development database exists. Refusing to change existing data.')
    if sql("SELECT COUNT(*) FROM mysql.user WHERE User = 'erino5_dev_app';").strip() != '0':
        raise RuntimeError('Application account exists. Refusing to replace credentials.')
    sql('CREATE DATABASE erino5 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    sql('USE erino5;\n' + (root / 'database/schema.sql').read_text(encoding='utf-8'))
    sql("CREATE USER 'erino5_dev_app'@'%' IDENTIFIED BY '" + app_password + "' REQUIRE SSL; GRANT SELECT, INSERT, UPDATE, DELETE ON erino5.* TO 'erino5_dev_app'@'%';")
    config = {'dsn': f'mysql:host={a.host};port=3306;dbname=erino5;charset=utf8mb4', 'user': 'erino5_dev_app', 'password': app_password, 'ssl': True, 'ssl_verify': False}
    # Development uses SSL_REQUIRED. Configure trusted CA verification before production.
    data = "<?php\n// PRIVATE development settings: TLS encrypted, CA verification pending.\nreturn json_decode(<<<'CONFIG'\n" + json.dumps(config, ensure_ascii=False, indent=2) + "\nCONFIG, true, 512, JSON_THROW_ON_ERROR);\n"
    fd = os.open(configfile, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as handle:
        handle.write(data)
    appenv = os.environ.copy()
    appenv['ERINO_CONFIG'] = str(configfile)
    php = [a.php, '-d', 'extension_dir=' + a.extension_dir, '-d', 'extension=pdo_mysql', '-d', 'extension=openssl']
    result = subprocess.run(php + [str(root / 'tools/manage.php'), 'admin', a.admin_email], env=appenv, capture_output=True, text=True, timeout=30)
    if result.returncode:
        raise RuntimeError(result.stderr.replace(app_password, '[redacted]'))
    fd = os.open(adminfile, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as handle:
        handle.write('Login: ' + a.admin_email + '\n' + result.stdout + '\nChange the password after first login. Remove this file after saving it securely.\n')
    print('Database installed: erino5')
    print('Restricted application account created: erino5_dev_app (DML only, TLS required)')
    print('Administrator created: ' + a.admin_email)
    print('Private settings: ' + str(configfile))
    print('Initial admin credentials: ' + str(adminfile))
    print('TLS encrypted; trusted-CA verification must be configured before production.')
except Exception as error:
    raise SystemExit(str(error).replace(password, '[redacted]').replace(app_password, '[redacted]'))