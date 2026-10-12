"""Exercise real HTTP controllers against an isolated, disposable SQLite database."""
import argparse
import html
import http.cookiejar
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

args = argparse.ArgumentParser()
args.add_argument('--php', required=True)
args.add_argument('--extension-dir')
opts = args.parse_args()
root = Path(__file__).resolve().parents[1]
php = [opts.php]
if opts.extension_dir:
    php += ['-d', 'extension_dir=' + opts.extension_dir, '-d', 'extension=pdo_sqlite']

class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(self, path, data=None):
        payload = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            response = self.opener.open(self.base + path, payload, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read().decode('utf-8')
    def post(self, path, data):
        _, body = self.request(path)
        token = re.search(r'name="csrf" value="([a-f0-9]+)"', body)
        assert token, (path, body[:1000])
        return self.request(path, dict(data, csrf=token.group(1)))
    def login(self, email, password='OwnerPassword123!'):
        return self.post('/account.php', {'action': 'login', 'email': email, 'password': password})

checks = 0
def check(condition, message):
    global checks
    assert condition, message
    checks += 1
    print('PASS', message)

with tempfile.TemporaryDirectory(prefix='erino-tests-') as temp:
    temp = Path(temp)
    dbfile = temp / 'test.sqlite'
    connection = sqlite3.connect(dbfile)
    schema = (root / 'database/schema.sql').read_text(encoding='utf-8')
    schema = schema.replace('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT')
    schema = schema.replace('BIGINT UNSIGNED', 'INTEGER')
    schema = re.sub(r',\s*INDEX \w+ \([^\n]+\)', '', schema)
    schema = re.sub(r' ENGINE=InnoDB[^;]+;', ';', schema)
    connection.executescript(schema)
    # Generate a fixture hash with the same PHP implementation as production.
    hasher = temp / 'hash.php'
    hasher.write_text('<?php echo password_hash(trim(stream_get_contents(STDIN)), PASSWORD_DEFAULT);', encoding='utf-8')
    password_hash = subprocess.check_output(php + [str(hasher)], input=b'OwnerPassword123!').decode()
    for email, role, status in [('admin@example.test', 'admin', 'approved'), ('owner@example.test', 'owner', 'pending'), ('other@example.test', 'owner', 'approved')]:
        connection.execute('INSERT INTO users(email,name,apartment,password_hash,role,status,created_at) VALUES(?,?,?,?,?,?,?)', (email, email.split('@')[0], '12', password_hash, role, status, '2026-10-12 12:00:00'))
    connection.commit()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    env = os.environ.copy()
    env.pop('ERINO_CONFIG', None)
    env.update(ERINO_DB_DSN='sqlite:' + str(dbfile), ERINO_DB_USER='', ERINO_DB_PASSWORD='')
    log = open(temp / 'server.log', 'w', encoding='utf-8')
    server = subprocess.Popen(php + ['-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', str(root)], env=env, stdout=log, stderr=log)
    try:
        base = f'http://127.0.0.1:{port}'
        guest, admin, owner = [Client(base) for _ in range(3)]
        for _ in range(50):
            try:
                guest.request('/account.php')
                break
            except (OSError, urllib.error.URLError):
                time.sleep(.1)
        else:
            raise AssertionError('PHP server did not start')
        code, body = guest.request('/index.php')
        check(code == 200 and 'График отключения' in body, 'legacy home page remains available')
        check(guest.request('/admin.php')[0] == 403, 'guest cannot open administration')
        check(guest.request('/account.php', {'action': 'register'})[0] == 403, 'CSRF rejects missing token')
        admin.login('admin@example.test')
        owner.login('owner@example.test')
        _, form = owner.request('/account.php')
        owner_token = re.search(r'name="csrf" value="([a-f0-9]+)"', form).group(1)
        check(owner.request('/admin.php', {'csrf': owner_token, 'action': 'member', 'id': '2', 'status': 'approved'})[0] == 403, 'owner cannot approve own application')
        check(owner.request('/admin.php')[0] == 403, 'owner cannot administer site')
        news_data = {'action': 'save', 'id': '0', 'title': '<script>alert(1)</script> Новость', 'body': '<img src=x onerror=alert(1)> Private topic', 'status': 'draft', 'discussion_enabled': '1'}
        code, body = admin.post('/admin.php', news_data)
        check(code == 200 and 'Новость сохранена' in body, 'administrator creates draft')
        news_id = connection.execute('SELECT MAX(id) FROM news').fetchone()[0]
        path = '/news.php?id=' + str(news_id)
        check(guest.request(path)[0] == 404, 'draft is not publicly accessible')
        news_data.update(id=str(news_id), status='published')
        admin.post('/admin.php?edit=' + str(news_id), news_data)
        code, body = guest.request(path)
        check(code == 200 and '&lt;img' in body and '<script>alert(1)</script>' not in body, 'published news escapes HTML')
        _, body = owner.request(path)
        check('name="body"' not in body, 'pending owner cannot read or post discussion')
        _, form = owner.request('/account.php')
        csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', form).group(1)
        check(owner.request(path, {'csrf': csrf, 'body': 'unauthorized'})[0] == 403, 'direct comment POST rejects pending owner')
        owner_id = connection.execute("SELECT id FROM users WHERE email='owner@example.test'").fetchone()[0]
        admin.post('/admin.php', {'action': 'member', 'id': owner_id, 'status': 'approved'})
        _, body = owner.post(path, {'body': '<script>comment-secret</script>'})
        check('&lt;script&gt;comment-secret' in body, 'approved owner can comment; comment HTML is escaped')
        check('comment-secret' not in guest.request(path)[1], 'guest cannot read private comments')
        forged = guest.request(path, {'csrf': csrf, 'body': 'guest'})[0]
        check(forged == 403, 'CSRF token cannot be reused by another session')
        admin.post('/admin.php', {'action': 'member', 'id': owner_id, 'status': 'blocked'})
        check('comment-secret' not in owner.request(path)[1], 'blocking revokes existing session discussion access')
        admin.post('/admin.php', {'action': 'member', 'id': owner_id, 'status': 'approved'})
        owner.login('owner@example.test')
        news_data.pop('discussion_enabled')
        admin.post('/admin.php?edit=' + str(news_id), news_data)
        _, form = owner.request('/account.php')
        csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', form).group(1)
        code, body = owner.request(path, {'csrf': csrf, 'body': 'closed'})
        check(code == 403 and 'comment-secret' not in body, 'closed discussion rejects comments and hides history')
        news_data['discussion_enabled'] = '1'
        admin.post('/admin.php?edit=' + str(news_id), news_data)
        comment_id = connection.execute('SELECT id FROM comments').fetchone()[0]
        admin.post('/admin.php', {'action': 'remove_comment', 'id': comment_id})
        check(connection.execute('SELECT COUNT(*) FROM comments').fetchone()[0] == 0, 'administrator can moderate comment')
        registration = {'action': 'register', 'email': 'new@example.test', 'name': 'Новый собственник', 'apartment': '7', 'password': 'NewOwnerPassword123!', 'role': 'admin', 'status': 'approved'}
        guest.post('/account.php', registration)
        row = connection.execute("SELECT role,status FROM users WHERE email='new@example.test'").fetchone()
        check(row == ('owner', 'pending'), 'registration cannot forge role or approval')
        code, body = guest.post('/account.php', registration)
        check(code == 200 and 'заявка принята' in body, 'duplicate registration does not expose database error')
        check(guest.request('/tools/manage.php')[0] == 404, 'web cannot run CLI installer')
        owner.post('/account.php', {'action': 'password', 'old_password': 'OwnerPassword123!', 'password': 'ChangedPassword123!'})
        owner.post('/account.php', {'action': 'logout'})
        check('Неверный email' in owner.login('owner@example.test')[1], 'old password is rejected after password change')
        check('Здравствуйте' in owner.login('owner@example.test', 'ChangedPassword123!')[1], 'new password works')
        for _ in range(11):
            code, body = guest.login('missing@example.test', 'incorrect')
        check(code == 429, 'login attempts are rate limited across sessions')
        check('PHP Warning' not in body and 'Fatal error' not in body, 'errors do not expose internals')
        print(f'{checks} integration checks passed')
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()
        connection.close()