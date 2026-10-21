"""Validate the real MySQL schema and PDO queries in a disposable local instance."""
import argparse
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time

p = argparse.ArgumentParser()
p.add_argument('--php', required=True)
p.add_argument('--extension-dir', required=True)
p.add_argument('--mysql-bin', required=True)
a = p.parse_args()
root = Path(__file__).resolve().parents[1]
bins = Path(a.mysql_bin)
suffix = '.exe' if os.name == 'nt' else ''
php = [a.php, '-d', 'extension_dir=' + a.extension_dir, '-d', 'extension=pdo_mysql']
with tempfile.TemporaryDirectory(prefix='erino-mysql-') as folder:
    temp = Path(folder)
    data = temp / 'data'
    data.mkdir()
    subprocess.run([str(bins / ('mysqld' + suffix)), '--no-defaults', '--initialize-insecure', '--datadir=' + str(data)], check=True, capture_output=True, timeout=60)
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        port = s.getsockname()[1]
    log = open(temp / 'server.log', 'wb')
    server = subprocess.Popen([str(bins / ('mysqld' + suffix)), '--no-defaults', '--datadir=' + str(data), '--port=' + str(port), '--bind-address=127.0.0.1', '--mysqlx=0', '--log-error=' + str(temp / 'mysql.log')], stdout=log, stderr=log)
    client = [str(bins / ('mysql' + suffix)), '--no-defaults', '--protocol=TCP', '--host=127.0.0.1', '--port=' + str(port), '--user=root', '--skip-password']
    admin = [str(bins / ('mysqladmin' + suffix)), '--no-defaults', '--protocol=TCP', '--host=127.0.0.1', '--port=' + str(port), '--user=root', '--skip-password']
    try:
        for _ in range(100):
            ready = subprocess.run(admin + ['ping'], capture_output=True)
            if ready.returncode == 0:
                break
            if server.poll() is not None:
                raise RuntimeError((temp / 'mysql.log').read_text(errors='replace'))
            time.sleep(.2)
        else:
            raise RuntimeError('MySQL startup timed out')
        subprocess.run(client, input=b'CREATE DATABASE erino_test CHARACTER SET utf8mb4;', capture_output=True, check=True)
        env = os.environ.copy()
        env.pop('ERINO_CONFIG', None)
        env.update(ERINO_DB_DSN=f'mysql:host=127.0.0.1;port={port};dbname=erino_test;charset=utf8mb4', ERINO_DB_USER='root', ERINO_DB_PASSWORD='')
        # Run installation twice: the schema must preserve existing data and be repeatable.
        for _ in range(2):
            subprocess.run(php + [str(root / 'tools/manage.php'), 'install'], env=env, check=True, capture_output=True)
        # Simulate the pre-signature schema in this disposable server, then migrate twice.
        subprocess.run(client + ['erino_test'], input=b'ALTER TABLE news DROP COLUMN signature, DROP COLUMN is_important;', capture_output=True, check=True)
        for _ in range(2):
            migrated = subprocess.run(php + [str(root / 'tools/manage.php'), 'migrate'], env=env, capture_output=True, text=True)
            if migrated.returncode:
                raise RuntimeError(migrated.stderr)
        created = subprocess.run(php + [str(root / 'tools/manage.php'), 'admin', 'test@example.test'], env=env, check=True, capture_output=True)
        assert b'Temporary password' in created.stdout
        refused = subprocess.run(php + [str(root / 'tools/manage.php'), 'admin', 'test@example.test'], env=env, capture_output=True)
        assert refused.returncode == 1
        probe = temp / 'probe.php'
        probe.write_text('''<?php
require $argv[1].'/app/bootstrap.php';
$u = query('SELECT * FROM users WHERE email = ?', ['test@example.test'])->fetch();
if (!is_admin($u)) throw new RuntimeException('Admin role failed');
limit_attempt('test', 'fixture', 1, 900);
try { limit_attempt('test', 'fixture', 1, 900); throw new RuntimeException('Limiter failed'); }
catch (DomainException $e) { }
query('INSERT INTO news (title, body, status, discussion_enabled, author_id, created_at, published_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', ['Новость', 'Проверка utf8mb4 🏡', 'published', 1, $u['id'], now(), now(), now()]);
$id = db()->lastInsertId();
db()->beginTransaction();
$n = query('SELECT * FROM news WHERE id = ? FOR UPDATE', [$id])->fetch();
if ($n['body'] !== 'Проверка utf8mb4 🏡') throw new RuntimeException('Unicode round trip failed');
query('INSERT INTO comments (news_id, user_id, body, created_at) VALUES (?, ?, ?, ?)', [$id, $u['id'], 'Комментарий', now()]);
db()->commit();
if (query('SELECT COUNT(*) FROM comments WHERE news_id = ?', [$id])->fetchColumn() != 1) throw new RuntimeException('Comment insert failed');
echo "PASS MySQL schema, repeatable install, admin creation, role, rate limiter, utf8mb4, row lock and comment transaction\\n";
''', encoding='utf-8')
        result = subprocess.run(php + [str(probe), str(root)], env=env, check=True, capture_output=True, text=True)
        print(result.stdout.strip())
        imported = subprocess.run(php + [str(root / 'tools/import_legacy.php'), '--apply', '--backup-dir=' + str(temp / 'backups')], env=env, capture_output=True, text=True)
        assert imported.returncode == 0, imported.stderr
        applied = subprocess.run(php + [str(root / 'tools/reindex_news.php'), '--apply', '--backup-dir=' + str(temp / 'backups')], env=env, capture_output=True, text=True)
        assert applied.returncode == 0, applied.stderr
        report = json.loads(applied.stdout)
        assert report['count'] == 28 and report['mapping']['1'] == 28 and report['next_id'] == 29
        verification = subprocess.run(client + ['--batch','--skip-column-names','erino_test'], input=b"SELECT news_id FROM comments; SELECT COUNT(*) FROM news; SELECT id FROM news ORDER BY id LIMIT 1; SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema='erino_test' AND table_name='news';", check=True, capture_output=True)
        assert verification.stdout.decode().split() == ['28','28','1','29'], verification.stdout.decode()
        repeated = subprocess.run(php + [str(root / 'tools/reindex_news.php'), '--apply', '--backup-dir=' + str(temp / 'backups')], env=env, capture_output=True, text=True)
        assert repeated.returncode == 0 and json.loads(repeated.stdout)['changed'] == 0, repeated.stderr
        print('PASS MySQL chronological IDs, preserved comments, reset AUTO_INCREMENT and repeatability')
        for _ in range(2):
            imported = subprocess.run(php + [str(root / 'tools/import_legacy.php'), '--important', '--apply', '--backup-dir=' + str(temp / 'backups')], env=env, capture_output=True, text=True)
            assert imported.returncode == 0, imported.stderr
        verification = subprocess.run(client + ['--batch','--skip-column-names','erino_test'], input=b"SELECT COUNT(*) FROM news; SELECT COUNT(*) FROM news WHERE is_important=1 AND status='published'; SELECT news_id FROM comments;", check=True, capture_output=True)
        assert verification.stdout.decode().split() == ['32','4','28'], verification.stdout.decode()
        print('PASS MySQL repeatable important flag migration and idempotent notice import with stable IDs')
    finally:
        subprocess.run(admin + ['shutdown'], capture_output=True, timeout=15)
        try:
            server.wait(timeout=15)
        except subprocess.TimeoutExpired:
            server.terminate()
            server.wait(timeout=10)
        log.close()