"""Renumber the configured database while the local Docker web service is stopped."""
import argparse
import getpass
import json
import os
from pathlib import Path
import secrets
import subprocess

p = argparse.ArgumentParser()
p.add_argument('--php', required=True)
p.add_argument('--extension-dir', required=True)
p.add_argument('--user', default='guru')
p.add_argument('--apply', action='store_true')
p.add_argument('--hex-input', action='store_true')
a = p.parse_args()
root = Path(__file__).resolve().parents[1]
password = getpass.getpass('Management credential (hidden): ')
if a.hex_input:
    password = bytes.fromhex(password).decode('utf-8')
php = [a.php, '-d', 'extension_dir=' + a.extension_dir, '-d', 'extension=pdo_mysql', '-d', 'extension=openssl', str(root / 'tools/reindex_news.php'), '--management-stdin']
if a.apply:
    php.append('--apply')
was_running = False
result = None
try:
    if a.apply:
        state = subprocess.run(['docker', 'compose', 'ps', '--status', 'running', '-q', 'web'], cwd=root, capture_output=True, text=True, check=True)
        was_running = bool(state.stdout.strip())
        if was_running:
            subprocess.run(['docker', 'compose', 'stop', '-t', '10', 'web'], cwd=root, check=True, capture_output=True)
    result = subprocess.run(php, input=json.dumps({'user': a.user, 'password': password}), capture_output=True, text=True, cwd=root, timeout=90)
    if result.returncode:
        raise RuntimeError(result.stderr.replace(password, '[redacted]'))
    report = json.loads(result.stdout)
    if a.apply and report['changed'] > 0:
        # Existing sessions keep their login, but old forms may not target newly assigned IDs.
        (root / 'app/.news-id-revision').write_text(secrets.token_hex(16) + '\n', encoding='ascii')
    print(json.dumps(report, ensure_ascii=False, indent=2))
except Exception as error:
    raise SystemExit(str(error).replace(password, '[redacted]'))
finally:
    if was_running:
        restarted = subprocess.run(['docker', 'compose', 'start', 'web'], cwd=root, capture_output=True, text=True)
        if restarted.returncode:
            raise SystemExit('Database operation finished, but local web service restart failed: ' + restarted.stderr)