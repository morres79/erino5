<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');
function settings(): array {
    static $config;
    if ($config === null) {
        $config = [
            'dsn' => getenv('ERINO_DB_DSN') ?: '',
            'user' => getenv('ERINO_DB_USER') ?: '',
            'password' => getenv('ERINO_DB_PASSWORD') ?: '',
        ];
        $local = dirname(__DIR__, 2).'/.private/database.php';
        $file = getenv('ERINO_CONFIG') ?: (!$config['dsn'] && is_file($local) ? $local : null);
        if ($file) $config = array_replace($config, require $file);
    }
    return $config;
}
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $c = settings();
        if (!$c['dsn']) throw new RuntimeException('Database is not configured');
        $options = [
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (!empty($c['ssl']) || !empty($c['ssl_ca'])) {
            $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'HIGH:!aNULL:!eNULL';
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $c['ssl_verify'] ?? true;
            if (!empty($c['ssl_ca'])) $options[PDO::MYSQL_ATTR_SSL_CA] = $c['ssl_ca'];
        }
        if (str_starts_with($c['dsn'], 'mysql:')) ini_set('mysqlnd.net_read_timeout', '10');
        $pdo = new PDO($c['dsn'], $c['user'], $c['password'], $options);
        if ((!empty($c['ssl']) || !empty($c['ssl_ca'])) && $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetchColumn(1) === '') {
            $pdo = null;
            throw new RuntimeException('Database connection requires TLS');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}
function query(string $sql, array $params = []): PDOStatement {
    $s = db()->prepare($sql);
    $s->execute($params);
    return $s;
}
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function status_label(string $status): string {
    return ['pending' => 'На проверке', 'approved' => 'Подтверждён', 'blocked' => 'Заблокирован', 'draft' => 'Черновик', 'published' => 'Опубликована', 'archived' => 'В архиве', 'deleted' => 'Удалена'][$status] ?? $status;
}
function now(): string { return date('Y-m-d H:i:s'); }
function start_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        session_name('erino_session');
        session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
        session_start();
    }
    $revisionPath = getenv('ERINO_NEWS_REVISION_FILE') ?: __DIR__.'/.news-id-revision';
    $revision = is_file($revisionPath) ? trim(file_get_contents($revisionPath)) : '';
    if ($revision !== '' && ($_SESSION['news_id_revision'] ?? '') !== $revision) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['news_id_revision'] = $revision;
    }
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
}
function user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $u = query('SELECT * FROM users WHERE id = ?', [$_SESSION['user_id']])->fetch();
    if (!$u || $u['status'] === 'blocked') { unset($_SESSION['user_id']); return null; }
    return $u;
}
function is_admin(?array $u): bool { return $u !== null && $u['role'] === 'admin' && $u['status'] === 'approved'; }
function is_owner(?array $u): bool { return $u !== null && $u['status'] === 'approved'; }
function require_admin(): array {
    $u = user();
    if (!is_admin($u)) { http_response_code(403); throw new DomainException('Доступ разрешён только администратору.'); }
    return $u;
}
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h(csrf()).'">'; }
function check_csrf(): void {
    if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(csrf(), $_POST['csrf'])) {
        http_response_code(403); throw new DomainException('Форма устарела. Обновите страницу и повторите действие.');
    }
}
function post(string $key): string { return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : ''; }
function text_field(string $key, int $max): string {
    $v = post($key);
    if ($v === '' || preg_match('//u', $v) !== 1 || iconv_strlen($v, 'UTF-8') > $max) throw new DomainException('Проверьте заполнение поля: '.$key.'.');
    return $v;
}
function redirect(string $url): never {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Location: '.$url, true, 303); exit;
}
function notice(string $message): void { $_SESSION['notice'] = $message; }
function page_start(string $title): void {
    csrf();
    $message = $_SESSION['notice'] ?? null;
    unset($_SESSION['notice']);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $pageTitle = $title; $portalStyles = true;
    echo '<!doctype html><html lang="ru">';
    include dirname(__DIR__).'/php/head.php';
    echo '<body>';
    include dirname(__DIR__).'/header.php';
    echo '<main class="portal"><h1>'.h($title).'</h1><nav class="portal-nav"><a href="index.php">Главная</a> <a href="archive.php">Архив новостей</a> <a href="account.php">Личный кабинет</a> <a href="admin.php">Управление</a></nav>';
    if ($message !== null) echo '<p role="status">'.h($message).'</p>';
}
function page_end(): void { echo '</main>'; include dirname(__DIR__).'/footer.php'; echo '</body></html>'; }
function render_error(Throwable $e): void {
    if ($e instanceof DomainException) echo '<p role="alert">'.h($e->getMessage()).'</p>';
    else { http_response_code(503); error_log((string)$e); echo '<p role="alert">Сервис временно недоступен. Повторите попытку позднее.</p>'; }
}
// Atomic per-key limiter in the database, shared across sessions and PHP workers.
function limit_attempt(string $action, string $identity, int $maximum, int $seconds): void {
    $key = hash('sha256', $action.'|'.$identity);
    query('DELETE FROM rate_limits WHERE expires_at < ?', [now()]);
    $expires = date('Y-m-d H:i:s', time() + $seconds);
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        query('INSERT INTO rate_limits (bucket, attempts, expires_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE attempts = attempts + 1', [$key, $expires]);
    } else {
        query('INSERT INTO rate_limits (bucket, attempts, expires_at) VALUES (?, 1, ?) ON CONFLICT(bucket) DO UPDATE SET attempts = attempts + 1', [$key, $expires]);
    }
    $count = query('SELECT attempts FROM rate_limits WHERE bucket = ?', [$key])->fetchColumn();
    if ($count > $maximum) { http_response_code(429); throw new DomainException('Слишком много попыток. Попробуйте позже.'); }
}
require_once __DIR__.'/news_format.php';
