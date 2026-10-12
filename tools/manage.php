<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
try {
    $action = $argv[1] ?? '';
    if ($action === 'install') {
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('Installer requires MySQL/MariaDB.');
        $sql = file_get_contents(dirname(__DIR__).'/database/schema.sql');
        foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') db()->exec($statement);
        echo "Schema installed. Existing records preserved.\n";
    } elseif ($action === 'admin' || $action === 'reset-password') {
        $email = strtolower($argv[2] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new RuntimeException('Pass a valid email as the third argument.');
        // Generate credentials rather than reading a password from shell arguments/history.
        $password = bin2hex(random_bytes(12));
        if ($action === 'admin') {
            $u = query('SELECT id FROM users WHERE email = ?', [$email])->fetch();
            if ($u) throw new RuntimeException('Account exists. Refusing implicit role escalation.');
            query('INSERT INTO users (email, name, apartment, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$email, 'Администратор ТСЖ', '—', password_hash($password, PASSWORD_DEFAULT), 'admin', 'approved', now()]);
        } else {
            $u = query('SELECT id FROM users WHERE email = ?', [$email])->fetch();
            if (!$u) throw new RuntimeException('Account does not exist.');
            query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        echo "Temporary password (store securely and change after login): ".$password."\n";
    } else {
        echo "Usage:\n php tools/manage.php install\n php tools/manage.php admin EMAIL\n php tools/manage.php reset-password EMAIL\n";
    }
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }