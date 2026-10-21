"""Explicit opt-in verification of the configured remote database and local HTTP login."""
import argparse
import http.cookiejar
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

p = argparse.ArgumentParser()
p.add_argument('--php', required=True)
p.add_argument('--extension-dir', required=True)
p.add_argument('--private-dir', required=True)
a = p.parse_args()
root = Path(__file__).resolve().parents[1]
private = Path(a.private_dir)
php = [a.php, '-d', 'extension_dir=' + a.extension_dir, '-d', 'extension=pdo_mysql', '-d', 'extension=openssl']
env = os.environ.copy()
env['ERINO_CONFIG'] = str(private / 'database.php')
credentials = (private / 'initial-admin.txt').read_text(encoding='utf-8')
email = re.search(r'Login: (\S+)', credentials).group(1)
password = re.search(r'Temporary password \(store securely and change after login\): (\S+)', credentials).group(1)
with tempfile.TemporaryDirectory(prefix='erino-remote-check-') as folder:
    folder = Path(folder)
    probe = folder / 'probe.php'
    probe.write_text('''<?php
require $argv[1].'/app/bootstrap.php';
$pdo = db();
$cipher = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetchColumn(1);
if (!$cipher) throw new RuntimeException('TLS not active');
$grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
foreach ($grants as $grant) {
    if (str_contains($grant, 'ALL PRIVILEGES') || str_contains($grant, 'GRANT OPTION')) throw new RuntimeException('Application privileges too broad');
}
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
if ($tables !== ['comments', 'news', 'rate_limits', 'users']) throw new RuntimeException('Unexpected schema');
echo 'PASS remote schema, restricted application account, TLS '.$cipher.PHP_EOL;
''', encoding='utf-8')
    result = subprocess.run(php + [str(probe), str(root)], env=env, capture_output=True, text=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Database verification failed: ' + result.stderr)
    print(result.stdout.strip())
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    log = open(folder / 'http.log', 'wb')
    server = subprocess.Popen(php + ['-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', str(root)], env=env, stdout=log, stderr=log)
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path, data=None):
        try:
            response = opener.open(f'http://127.0.0.1:{port}' + path, urllib.parse.urlencode(data).encode() if data is not None else None, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read().decode('utf-8')
    try:
        for _ in range(50):
            try:
                code, body = request('/account.php')
                break
            except (OSError, urllib.error.URLError):
                time.sleep(.1)
        else:
            raise RuntimeError('Local HTTP test server did not start')
        assert code == 200 and 'name="csrf"' in body, 'Account form unavailable'
        assert request('/admin.php')[0] == 403, 'Guest admin access must be denied'
        code, body = request('/account.php')
        token = re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)
        code, body = request('/account.php', {'csrf': token, 'action': 'login', 'email': email, 'password': password})
        assert code == 200 and 'Здравствуйте' in body, 'Administrator login failed'
        code, body = request('/admin.php')
        assert code == 200 and 'Добавить новость' in body, 'Administrator form unavailable'
        assert request('/news.php')[0] == 200, 'News feed failed'
        code, body = request('/index.php')
        assert code == 200 and 'График работы ТСЖ' in body, 'Legacy homepage failed'
        print('PASS HTTP guest restrictions, administrator login, news editor, feed and legacy homepage')
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()