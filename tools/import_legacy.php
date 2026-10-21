<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/app/bootstrap.php';
try {
    $options = getopt('', ['apply','backup-dir:','important']);
    $important = array_key_exists('important', $options);
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/database/legacy/'.($important ? 'important' : 'news').'.json'), true, 512, JSON_THROW_ON_ERROR);
    $records = $manifest['records'];
    if (count($records) !== ($important ? 4 : 27)) throw new RuntimeException('Unexpected legacy record count');
    $author = query("SELECT id FROM users WHERE role = 'admin' AND status = 'approved' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$author) throw new RuntimeException('Approved administrator required');
    $known = array_fill_keys(query('SELECT legacy_key FROM news WHERE legacy_key IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN), true);
    $pending = [];
    foreach ($records as $record) {
        if (!preg_match('/^[a-f0-9]{64}$/', $record['legacy_key']) || !in_array($record['status'], ['published','archived'], true) || iconv_strlen($record['title'], 'UTF-8') > 255 || iconv_strlen($record['signature'], 'UTF-8') > 255 || $record['body'] === '') throw new RuntimeException('Invalid legacy record');
        if (!isset($known[$record['legacy_key']])) $pending[] = $record;
    }
    echo 'Legacy records: '.count($records).'; pending: '.count($pending).'; already imported: '.(count($records)-count($pending)).PHP_EOL;
    if (!array_key_exists('apply', $options)) { echo 'Dry run only. Use --apply to import.'.PHP_EOL; exit; }
    if (!$pending) { echo 'Nothing to import. Existing entries preserved.'.PHP_EOL; exit; }
    $directory = $options['backup-dir'] ?? dirname(__DIR__, 2).'/.private/backups';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create private backup directory');
    $resolved = str_replace('\\', '/', realpath($directory));
    $public = str_replace('\\', '/', realpath(dirname(__DIR__)));
    if (str_starts_with(strtolower($resolved).'/', strtolower($public).'/')) throw new RuntimeException('Backup must be outside public website directory');
    db()->beginTransaction();
    try {
        $lock = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $snapshot = ['saved_at'=>now(), 'news'=>query('SELECT * FROM news'.$lock)->fetchAll(), 'comments'=>query('SELECT * FROM comments')->fetchAll()];
        $backup = $resolved.'/news-before-legacy-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $handle = fopen($backup, 'x');
        if (!$handle) throw new RuntimeException('Cannot create backup file');
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (fwrite($handle, $json) !== strlen($json)) { fclose($handle); throw new RuntimeException('Incomplete backup'); }
        fclose($handle); chmod($backup, 0600);
        foreach ($pending as $record) {
            query('INSERT INTO news (title, body, signature, status, discussion_enabled, author_id, created_at, published_at, updated_at, legacy_key, legacy_order, is_important) VALUES (?, ?, ?, ?, 0, ?, ?, NULL, ?, ?, ?, ?)', [
                $record['title'], $record['body'], $record['signature'], $record['status'], $author, now(), now(), $record['legacy_key'], $record['legacy_order'], $important ? 1 : 0,
            ]);
        }
        db()->commit();
        echo 'Imported '.count($pending).' records. Existing IDs and comments preserved.'.PHP_EOL.'Backup: '.$backup.PHP_EOL;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }