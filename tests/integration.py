"""Exercise real HTTP controllers against an isolated, disposable SQLite database."""
import argparse
import json
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
    env.update(ERINO_DB_DSN='sqlite:' + str(dbfile), ERINO_DB_USER='', ERINO_DB_PASSWORD='', ERINO_NEWS_REVISION_FILE=str(temp / 'news-revision'))
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
        check(code == 200 and 'Важные объявления' in body and 'новости ТСЖ' in body, 'legacy home page remains available')
        check(guest.request('/admin.php')[0] == 403, 'guest cannot open administration')
        check(guest.request('/account.php', {'action': 'register'})[0] == 403, 'CSRF rejects missing token')
        admin.login('admin@example.test')
        owner.login('owner@example.test')
        _, form = owner.request('/account.php')
        owner_token = re.search(r'name="csrf" value="([a-f0-9]+)"', form).group(1)
        check(owner.request('/admin.php', {'csrf': owner_token, 'action': 'member', 'id': '2', 'status': 'approved'})[0] == 403, 'owner cannot approve own application')
        check(owner.request('/admin.php')[0] == 403, 'owner cannot administer site')
        news_data = {'action': 'save', 'id': '0', 'title': '<script>alert(1)</script> Новость', 'body': '<img src=x onerror=alert(1)> Private topic', 'status': 'draft', 'discussion_enabled': '1'}
        news_data['body'] += '\n[alert][b]Important first[/b][/alert]\nBetween blocks\n[comment][i]Editorial note[/i][/comment]\n[alert]Important second[/alert]'
        news_data['body'] += '\n[ul][li]Bullet one[/li][li]Bullet two[/li][/ul]\n[ol][li]Step one[/li][/ol]'
        news_data['signature'] = '<script>Signature</script>'
        code, body = admin.post('/admin.php', news_data)
        check(code == 200 and 'Новость сохранена' in body, 'administrator creates draft')
        news_id = connection.execute('SELECT MAX(id) FROM news').fetchone()[0]
        check(f'name="id" value="{news_id}"' in body and html.escape(news_data['body'], quote=True) in body, 'save keeps the stored draft open with its full text')
        check('посетители его пока не видят' in body and 'role="status"' in body, 'draft save clearly confirms private status')
        invalid = dict(news_data, id=str(news_id), title='', body='Retain this unsaved text', status='published')
        invalid.pop('discussion_enabled')
        _, failed_body = admin.post('/admin.php?edit=' + str(news_id), invalid)
        check('role="alert"' in failed_body and 'Retain this unsaved text' in failed_body and f'name="id" value="{news_id}"' in failed_body, 'validation failure retains text and existing record identity')
        check('value="published" selected' in failed_body and 'name="discussion_enabled" value="1" checked' not in failed_body, 'validation failure retains requested status and discussion choice')
        check(connection.execute('SELECT body FROM news WHERE id=?', (news_id,)).fetchone()[0] == news_data['body'], 'validation failure leaves stored draft unchanged')
        _, edit_body = admin.request('/admin.php?edit=' + str(news_id))
        check(f'name="id" value="{news_id}"' in edit_body and html.escape(news_data['body'], quote=True) in edit_body, 'draft link reloads saved text')
        path = '/news.php?id=' + str(news_id)
        check(guest.request(path)[0] == 404, 'draft is not publicly accessible')
        check(connection.execute('SELECT signature FROM news WHERE id=?', (news_id,)).fetchone()[0] == news_data['signature'], 'signature is stored independently of the body')
        news_data.update(id=str(news_id), status='published')
        admin.post('/admin.php?edit=' + str(news_id), news_data)
        code, body = guest.request(path)
        check(code == 200 and '&lt;img' in body and '<script>alert(1)</script>' not in body, 'published news escapes HTML')
        check(body.count('class="inf_alert"') == 2 and 'class="inf_comment"' in body, 'publication renders repeated editorial blocks in their positions')
        check('class="inf_sign"' in body and '&lt;script&gt;Signature&lt;/script&gt;' in body, 'publication renders an escaped signature below its text')
        check('<strong>Important first</strong>' in body and '<em>Editorial note</em>' in body and '<ul class="news-list">' in body and '<ol class="news-list">' in body, 'published news supports emphasis and both list types')
        _, homepage = guest.request('/index.php')
        check('<strong>Important first</strong>' in homepage and '<em>Editorial note</em>' in homepage and '<ul class="news-list">' in homepage and '<ol class="news-list">' in homepage, 'homepage uses the same emphasis and list formatting')
        check('Important second' in homepage and 'Editorial note' in homepage and '&lt;script&gt;Signature&lt;/script&gt;' in homepage, 'homepage renders complete blocks and signature without truncation')
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
        # Imported records are tested against this disposable database only.
        original = connection.execute('SELECT id,body FROM news WHERE id=?', (news_id,)).fetchone()
        manifest = json.loads((root / 'database/legacy/news.json').read_text(encoding='utf-8'))
        before_count = connection.execute('SELECT COUNT(*) FROM news').fetchone()[0]
        dry = subprocess.run(php + [str(root / 'tools/import_legacy.php')], env=env, capture_output=True, text=True)
        check(dry.returncode == 0 and connection.execute('SELECT COUNT(*) FROM news').fetchone()[0] == before_count, 'import dry run does not mutate data')
        backup_dir = str(temp / 'private-backups')
        command = php + [str(root / 'tools/import_legacy.php'), '--apply', '--backup-dir=' + backup_dir]
        imported = subprocess.run(command, env=env, capture_output=True, text=True)
        check(imported.returncode == 0, 'legacy import completes: ' + (imported.stderr if imported.returncode else '27 records'))
        check(connection.execute('SELECT COUNT(*) FROM news WHERE legacy_key IS NOT NULL').fetchone()[0] == 27, 'all 27 legacy news records imported')
        again = subprocess.run(command, env=env, capture_output=True, text=True)
        check(again.returncode == 0 and connection.execute('SELECT COUNT(*) FROM news').fetchone()[0] == before_count + 27, 'repeated import does not duplicate or update entries')
        check(connection.execute('SELECT id,body FROM news WHERE id=?', (news_id,)).fetchone() == original, 'import preserves existing record ID and full text')
        check(len(list(Path(backup_dir).glob('*.json'))) == 1, 'one private backup is saved before the actual import')
        check(connection.execute("SELECT COUNT(*) FROM news WHERE legacy_key IS NOT NULL AND status='archived'").fetchone()[0] == 23, 'original archive remains archived')
        _, home = guest.request('/index.php')
        check(home.count('График отключения горячей воды в 2026 г.') == 1 and 'ОЧНО-ЗАОЧНОЕ ОТЧЁТНОЕ СОБРАНИЕ (март-апрель 2025г.)' not in home, 'homepage uses database without duplicates or archived records')
        _, archive = guest.request('/archive.php')
        check('С 1 июля 2025 года' in archive and 'archive.php?page=2' in archive, 'archive is public and paginated')
        _, last_archive = guest.request('/archive.php?page=3')
        check('Собрание 29.03.2015' in last_archive and '<table class="inf_table">' in last_archive, 'oldest archive records and tables remain accessible')
        _, home_html = guest.request('/index.php')
        check('<a href="news.php">Новости</a>' not in home_html, 'redundant horizontal news menu link removed')
        # Move an existing post into the archive without changing its URL or private history.
        connection.execute('INSERT INTO comments(news_id,user_id,body,created_at) VALUES(?,?,?,?)', (news_id, owner_id, 'historical-private-comment', '2026-10-21 12:00:00'))
        connection.commit()
        before_reindex = connection.execute('SELECT id,body,signature,status FROM news ORDER BY id').fetchall()
        before_comments = connection.execute('SELECT id,news_id,body FROM comments ORDER BY id').fetchall()
        plan = subprocess.run(php + [str(root / 'tools/reindex_news.php')], env=env, capture_output=True, text=True)
        check(plan.returncode == 0 and json.loads(plan.stdout)['changed'] == 28 and connection.execute('SELECT id,body,signature,status FROM news ORDER BY id').fetchall() == before_reindex, 'reindex preview calculates chronological IDs without writing')
        _, pre_reindex_form = admin.request('/admin.php')
        pre_reindex_csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', pre_reindex_form).group(1)
        reindex = subprocess.run(php + [str(root / 'tools/reindex_news.php'), '--apply', '--backup-dir=' + backup_dir], env=env, capture_output=True, text=True)
        check(reindex.returncode == 0, 'chronological reindex succeeds with foreign keys enabled: ' + reindex.stderr)
        report = json.loads(reindex.stdout)
        mapping = report['mapping']
        check(connection.execute('SELECT id FROM news ORDER BY id').fetchall() == [(i,) for i in range(1,29)], 'news IDs form the consecutive range 1 through 28')
        check(connection.execute('SELECT legacy_order FROM news WHERE id=1').fetchone()[0] == 1 and mapping[str(news_id)] == 28, 'oldest news has ID 1 and newest news has ID 28')
        check(all(connection.execute('SELECT body,signature,status FROM news WHERE id=?', (mapping[str(row[0])],)).fetchone() == row[1:] for row in before_reindex), 'reindex preserves every body, signature and status')
        check(connection.execute('SELECT id,news_id,body FROM comments ORDER BY id').fetchall() == [(row[0],mapping[str(row[1])],row[2]) for row in before_comments], 'comments retain their IDs, text and associated news')
        check(Path(report['backup']).is_file() and json.loads(Path(report['backup']).read_text(encoding='utf-8'))['mapping'] == mapping, 'private reindex backup records original rows and ID mapping')
        again = subprocess.run(php + [str(root / 'tools/reindex_news.php'), '--apply', '--backup-dir=' + backup_dir], env=env, capture_output=True, text=True)
        check(again.returncode == 0 and json.loads(again.stdout)['changed'] == 0, 'repeat reindex does not move correctly numbered records')
        connection.execute("INSERT INTO news(title,body,status,author_id,created_at,updated_at) VALUES('New fixture','Body','draft',1,'2026-10-22 10:00:00','2026-10-22 10:00:00')")
        check(connection.execute('SELECT last_insert_rowid()').fetchone()[0] == 29, 'next new record receives ID 29')
        connection.rollback()
        (temp / 'news-revision').write_text('fixture-reindexed', encoding='ascii')
        stale_code, stale_body = admin.request('/admin.php', dict(news_data, id=str(mapping[str(news_id)]), csrf=pre_reindex_csrf))
        check(stale_code == 403, 'forms opened before renumbering cannot modify records under new IDs')
        news_id = mapping[str(news_id)]
        news_data['id'] = str(news_id)
        path = '/news.php?id=' + str(news_id)
        news_data['status'] = 'archived'
        code, archived_editor = admin.post('/admin.php?edit=' + str(news_id), news_data)
        check(code == 200 and 'Новость сохранена в архиве' in archived_editor, 'administrator can archive a publication')
        _, new_home = guest.request('/index.php')
        check('Important first' not in new_home and guest.request(path)[0] == 200, 'archiving hides news from homepage while preserving direct URL')
        _, owner_archive = owner.request(path)
        check('historical-private-comment' in owner_archive and 'name="body"' not in owner_archive, 'archive keeps owner-only comment history read-only')
        check('historical-private-comment' not in guest.request(path)[1], 'archival does not expose private comment history to guests')
        _, owner_account = owner.request('/account.php')
        token = re.search(r'name="csrf" value="([a-f0-9]+)"', owner_account).group(1)
        check(owner.request(path, {'csrf': token, 'body': 'archive comment'})[0] == 403, 'archived publications reject new comments')
        _, archived_view = admin.request('/admin.php?edit=' + str(news_id))
        check('name="body"' not in archived_view and 'name="title"' not in archived_view and 'data-news-format' not in archived_view, 'archived editor is read-only without formatting controls')
        check('value="restore_draft"' in archived_view and 'value="published"' not in archived_view, 'archive provides only a separate draft transition')
        saved_archive = connection.execute('SELECT title,body,signature,discussion_enabled,published_at FROM news WHERE id=?', (news_id,)).fetchone()
        forged = dict(news_data, title='Changed title', body='Changed body', signature='Changed signature', status='published')
        code, denied = admin.post('/admin.php?edit=' + str(news_id), forged)
        check(code == 409 and connection.execute('SELECT status FROM news WHERE id=?', (news_id,)).fetchone()[0] == 'archived', 'server rejects direct archive-to-publication save')
        forged['status'] = 'draft'
        code, denied = admin.post('/admin.php?edit=' + str(news_id), forged)
        check(code == 409 and connection.execute('SELECT title,body,signature,discussion_enabled,published_at FROM news WHERE id=?', (news_id,)).fetchone() == saved_archive, 'archive cannot be edited by posting a draft save or stale editor')
        code, denied = admin.post('/admin.php?edit=' + str(news_id), {'action': 'restore_draft', 'id': news_id, 'status': 'published'})
        check(code == 409, 'draft transition cannot be forged into publication')
        code, restored = admin.post('/admin.php?edit=' + str(news_id), {'action': 'restore_draft', 'id': news_id, 'status': 'draft', 'title': 'Injected title', 'body': 'Injected body'})
        check(code == 200 and 'name="body"' in restored and connection.execute('SELECT status FROM news WHERE id=?', (news_id,)).fetchone()[0] == 'draft', 'separate draft transition unlocks editing')
        check(connection.execute('SELECT title,body,signature,discussion_enabled,published_at FROM news WHERE id=?', (news_id,)).fetchone() == saved_archive and guest.request(path)[0] == 404, 'draft restoration preserves content and does not publish')
        news_data['status'] = 'published'
        admin.post('/admin.php?edit=' + str(news_id), news_data)
        check('Important first' in guest.request('/index.php')[1], 'draft restored from archive can subsequently be published')
        _, listing = admin.request('/admin.php')
        def news_table(source):
            return re.search(r'<table class="news-admin-table".*?</table>', source, re.S).group(0)
        def news_ids(source):
            return re.findall(r'<tr><td>(\d+)</td>', news_table(source))
        table = news_table(listing)
        check('<th scope="col">ID</th>' in table and '<th scope="col">Тема</th>' in table and '<th scope="col">Статус</th>' in table, 'news management renders the requested three-column table')
        check(f'<a href="news.php?id={news_id}">Опубликована</a>' in table and '>Открыть</a>' not in table and '>В архиве</a>' not in table, 'only published status links to the public news page')
        check(len(news_ids(listing)) == 20 and 'news_rows=20' in listing, 'default news page contains 20 rows')
        _, second = admin.request('/admin.php?news_page=2&news_rows=20')
        check(len(news_ids(second)) == 8 and not set(news_ids(second)).intersection(news_ids(listing)), 'second page contains remaining rows without duplication')
        for size in (50,100):
            _, large = admin.request('/admin.php?news_rows=' + str(size))
            check(len(news_ids(large)) == 28 and f'value="{size}" selected' in large and 'class="news-pagination"' not in large, f'{size}-row selection displays all entries without redundant pagination')
        _, invalid_size = admin.request('/admin.php?news_rows=7&news_page=999')
        check(len(news_ids(invalid_size)) == 8 and 'value="20" selected' in invalid_size, 'pagination validates page size and clamps page number')
        _, contextual = admin.post(f'/admin.php?edit={news_id}&news_page=2&news_rows=20', news_data)
        check('aria-current="page">2</span>' in contextual and len(news_ids(contextual)) == 8, 'saving preserves news table page and row selection')
        _, editor = admin.request('/admin.php?edit=' + str(news_id))
        toolbar = re.search(r'<div class="news-toolbar".*?</div>', editor, re.S).group(0)
        check(toolbar.count('<svg ') == 6 and toolbar.count('aria-label=') == 7 and toolbar.count('title=') == 6, 'compact toolbar icons have accessible labels and hover hints')
        _, home = guest.request('/index.php')
        check('class="news-more"' in home and 'Перейти -&gt;</a>' in home and '>Открыть новость</a>' not in home, 'news cards use the quieter transition link')
        # Important is independent of status; each public record belongs to one feed.
        news_data['is_important'] = '1'
        code, important_editor = admin.post(f'/admin.php?edit={news_id}', news_data)
        check(code == 200 and 'name="is_important" value="1" checked' in important_editor, 'important flag is persisted and reloaded in the editor')
        def sections(home):
            return home.split('<div id="important-notices">', 1)
        regular, important = sections(guest.request('/index.php')[1])
        check('Important first' not in regular and 'Important first' in important and guest.request(path)[0] == 200, 'published important news moves out of the normal feed into important notices')
        check('Important first' in sections(guest.request('/index.php?page=99')[1])[1], 'important notices are independent of normal news pagination')
        invalid = dict(news_data, title='', body='Retain important choice')
        code, invalid_form = admin.post(f'/admin.php?edit={news_id}', invalid)
        check('name="is_important" value="1" checked' in invalid_form and 'Retain important choice' in invalid_form, 'validation failure retains important flag and unsaved text')
        check(guest.request('/admin.php', {'action':'delete_news','id':news_id})[0] == 403, 'guest cannot soft-delete news')
        owner.login('other@example.test')
        check(owner.request('/admin.php', {'action':'delete_news','id':news_id})[0] == 403, 'approved owner cannot soft-delete news')
        _, form = admin.request(f'/admin.php?edit={news_id}')
        check(admin.request(f'/admin.php?edit={news_id}', {'action':'delete_news','id':news_id})[0] == 403, 'soft deletion requires CSRF protection')
        preserved = connection.execute('SELECT title,body,signature,is_important,discussion_enabled,published_at FROM news WHERE id=?', (news_id,)).fetchone()
        comments_before = connection.execute('SELECT * FROM comments WHERE news_id=?', (news_id,)).fetchall()
        code, deleted = admin.post(f'/admin.php?edit={news_id}', {'action':'delete_news','id':news_id})
        check(code == 200 and 'Удалена' in deleted and 'name="body"' not in deleted and 'value="restore_draft"' in deleted, 'deleted record is visible to administrators in read-only mode with restoration')
        check(connection.execute('SELECT title,body,signature,is_important,discussion_enabled,published_at FROM news WHERE id=?', (news_id,)).fetchone() == preserved and connection.execute('SELECT * FROM comments WHERE news_id=?', (news_id,)).fetchall() == comments_before, 'soft deletion preserves identity, full content, settings and associated comments')
        _, owner_account = owner.request('/account.php')
        owner_csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', owner_account).group(1)
        check(guest.request(path)[0] == 404 and owner.request(path)[0] == 404 and owner.request(path, {'body':'Forbidden comment', 'csrf':owner_csrf})[0] == 404, 'deleted news cannot be opened or commented on through a direct URL')
        check('Important first' not in guest.request('/index.php')[1] and 'Important first' not in guest.request('/archive.php')[1], 'deleted important news is absent from both homepage sections and archive')
        table = news_table(admin.request('/admin.php')[1])
        check(f'<tr class="news-deleted"><td>{news_id}</td>' in table and '<span>Удалена</span>' in table and f'news.php?id={news_id}' not in table, 'deleted table row uses muted strikethrough styling and has no public link')
        code, denied = admin.post(f'/admin.php?edit={news_id}', dict(news_data, status='published', body='Forbidden overwrite'))
        check(code == 409 and connection.execute('SELECT status FROM news WHERE id=?', (news_id,)).fetchone()[0] == 'deleted', 'stale editor cannot overwrite or immediately republish deleted news')
        code, denied = admin.post(f'/admin.php?edit={news_id}', {'action':'restore_draft','id':news_id,'status':'published'})
        check(code == 409, 'deleted news cannot be restored directly into publication')
        code, restored = admin.post(f'/admin.php?edit={news_id}', {'action':'restore_draft','id':news_id,'status':'draft'})
        check(code == 200 and guest.request(path)[0] == 404 and 'name="body"' in restored and 'name="is_important" value="1" checked' in restored, 'restoration produces a private editable draft and preserves important flag')
        news_data.pop('is_important')
        admin.post(f'/admin.php?edit={news_id}', news_data)
        regular, important = sections(guest.request('/index.php')[1])
        check('Important first' in regular and 'Important first' not in important, 'clearing important flag returns a published record to normal news')
        news_data['status'] = 'deleted'
        code, deleted = admin.post(f'/admin.php?edit={news_id}', news_data)
        check(code == 200 and guest.request(path)[0] == 404 and 'Удалена' in deleted, 'Delete status in the editor performs soft deletion')
        admin.post(f'/admin.php?edit={news_id}', {'action':'restore_draft','id':news_id,'status':'draft'})
        news_data.update(status='archived', is_important='1')
        admin.post(f'/admin.php?edit={news_id}', news_data)
        check('Important first' not in guest.request('/index.php')[1] and guest.request(path)[0] == 200, 'archived important notice is hidden from the homepage and remains readable in archive')
        code, deleted = admin.post(f'/admin.php?edit={news_id}', {'action':'delete_news','id':news_id, 'title':'Do not apply', 'body':'Do not apply'})
        check(code == 200 and connection.execute('SELECT title,body,signature,is_important,discussion_enabled,published_at FROM news WHERE id=?', (news_id,)).fetchone() == preserved, 'archived news can be deleted without unlocking or modifying its content')
        # Import the four static notices using the same protected, idempotent importer.
        existing_rows = connection.execute('SELECT * FROM news ORDER BY id').fetchall()
        import_cmd = php + [str(root / 'tools/import_legacy.php'), '--important', '--backup-dir=' + str(temp / 'important-backups')]
        preview = subprocess.run(import_cmd, env=env, capture_output=True, text=True)
        check(preview.returncode == 0 and 'pending: 4' in preview.stdout and connection.execute('SELECT COUNT(*) FROM news').fetchone()[0] == 28, 'important notice import previews four entries without changing the database')
        imported = subprocess.run(import_cmd + ['--apply'], env=env, capture_output=True, text=True)
        check(imported.returncode == 0 and connection.execute("SELECT COUNT(*) FROM news WHERE is_important=1 AND status='published'").fetchone()[0] == 4, 'all four static important notices are imported as published flagged records')
        check(connection.execute('SELECT * FROM news WHERE id <= 28 ORDER BY id').fetchall() == existing_rows, 'important notice import preserves all existing IDs and contents')
        repeat = subprocess.run(import_cmd + ['--apply'], env=env, capture_output=True, text=True)
        check(repeat.returncode == 0 and 'Nothing to import' in repeat.stdout and connection.execute('SELECT COUNT(*) FROM news').fetchone()[0] == 32, 'repeated important notice import does not create duplicates')
        backups = list((temp / 'important-backups').glob('*.json'))
        check(len(backups) == 1 and len(json.loads(backups[0].read_text(encoding='utf-8'))['news']) == 28, 'important notice import saves one complete private backup before writing')
        regular, important = sections(guest.request('/index.php')[1])
        titles = ['График работы ТСЖ', 'Поэтажный график уборки подъездов', 'Квитанции на оплату коммунальных услуг', 'Перепланировка квартир']
        check(all(t in important and t not in regular for t in titles) and [important.index(t) for t in titles] == sorted(important.index(t) for t in titles), 'imported notices appear once in their original order exclusively in the important section')
        print(f'{checks} integration checks passed')
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()
        connection.close()