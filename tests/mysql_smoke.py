"""Validate the real MySQL schema and PDO queries in a disposable local instance."""
import argparse
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
    finally:
        subprocess.run(admin + ['shutdown'], capture_output=True, timeout=15)
        try:
            server.wait(timeout=15)
        except subprocess.TimeoutExpired:
            server.terminate()
            server.wait(timeout=10)
        log.close()