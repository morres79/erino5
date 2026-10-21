<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
$pdo = null; $committed = false;
try {
    $args = getopt('', ['apply','management-stdin','backup-dir:']);
    if (array_key_exists('management-stdin', $args)) {
        $credentials = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $config = settings();
        $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>10];
        if (!empty($config['ssl']) || !empty($config['ssl_ca'])) {
            $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'HIGH:!aNULL:!eNULL';
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $config['ssl_verify'] ?? true;
            if (!empty($config['ssl_ca'])) $options[PDO::MYSQL_ATTR_SSL_CA] = $config['ssl_ca'];
        }
        $pdo = new PDO($config['dsn'], $credentials['user'], $credentials['password'], $options);
        unset($credentials);
        if ((!empty($config['ssl']) || !empty($config['ssl_ca'])) && !$pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetchColumn(1)) throw new RuntimeException('TLS is required');
    } else {
        $pdo = db();
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver,['mysql','sqlite'],true)) throw new RuntimeException('Unsupported database driver');
    $apply = array_key_exists('apply', $args);
    if ($apply && $driver === 'mysql') {
        $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
        if (!preg_match('/\b(ALTER|ALL PRIVILEGES)\b/i', implode("\n",$grants))) throw new RuntimeException('ALTER privilege required to reset AUTO_INCREMENT; nothing changed');
        $refs = $pdo->query("SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'news' AND REFERENCED_COLUMN_NAME = 'id'")->fetchAll();
        foreach ($refs as $ref) if ($ref['TABLE_NAME'] !== 'comments' || $ref['COLUMN_NAME'] !== 'news_id' || $ref['TABLE_SCHEMA'] !== $pdo->query('SELECT DATABASE()')->fetchColumn()) throw new RuntimeException('Unexpected foreign key reference; nothing changed');
        $indexes = $pdo->query('SHOW INDEX FROM news WHERE Non_unique = 0')->fetchAll();
        foreach ($indexes as $index) if (!in_array($index['Column_name'],['id','legacy_key'],true)) throw new RuntimeException('Unexpected unique index; nothing changed');
    }
    $pdo->beginTransaction();
    $lock = $apply && $driver === 'mysql' ? ' FOR UPDATE' : '';
    $news = $pdo->query('SELECT * FROM news ORDER BY CASE WHEN legacy_order IS NULL THEN 1 ELSE 0 END ASC, legacy_order ASC, created_at ASC, id ASC'.$lock)->fetchAll();
    $comments = $pdo->query('SELECT * FROM comments ORDER BY id'.$lock)->fetchAll();
    $mapping = []; $changed = 0;
    foreach ($news as $i=>$entry) {
        $old = (int)$entry['id']; $new = $i+1;
        $mapping[$old] = $new;
        if ($old !== $new) $changed++;
    }
    foreach ($comments as $comment) if (!isset($mapping[(int)$comment['news_id']])) throw new RuntimeException('Existing orphaned comment; nothing changed');
    $report = ['count'=>count($news),'changed'=>$changed,'next_id'=>count($news)+1,'mapping'=>$mapping,'backup'=>null];
    if (!$apply) { $pdo->rollBack(); $report['mode']='dry-run'; echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL; exit; }
    if ($changed > 0) {
        $directory = $args['backup-dir'] ?? dirname(__DIR__,2).'/.private/backups';
        if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Cannot create backup directory');
        $resolved = str_replace('\\','/',realpath($directory));
        $public = str_replace('\\','/',realpath(dirname(__DIR__)));
        if (str_starts_with(strtolower($resolved).'/',strtolower($public).'/')) throw new RuntimeException('Backup must be outside the public directory');
        $backup = $resolved.'/news-before-reindex-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $snapshot = ['saved_at'=>now(),'mapping'=>$mapping,'news'=>$news,'comments'=>$comments];
        $json = json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $handle = fopen($backup,'x');
        if (!$handle) throw new RuntimeException('Cannot write backup');
        if (fwrite($handle,$json) !== strlen($json)) { fclose($handle); throw new RuntimeException('Incomplete backup'); }
        fclose($handle); chmod($backup,0600);
        $report['backup'] = $backup;
        $maxId = max(array_keys($mapping));
        if ($maxId > PHP_INT_MAX - count($news)*2 - 100) throw new RuntimeException('Temporary ID range exceeds integer bounds');
        $base = max($maxId,count($news))+count($news)+100;
        $columns = array_keys($news[0]);
        foreach ($columns as $column) if (!preg_match('/^[a-z_][a-z0-9_]*$/i',$column)) throw new RuntimeException('Unexpected column identifier');
        $insert = $pdo->prepare('INSERT INTO news (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        $moveComments = $pdo->prepare('UPDATE comments SET news_id = ? WHERE news_id = ?');
        $remove = $pdo->prepare('DELETE FROM news WHERE id = ?');
        // Move via complete temporary rows, retaining every foreign key constraint throughout.
        $pdo->exec('UPDATE news SET legacy_key = NULL WHERE legacy_key IS NOT NULL');
        foreach ($news as $i=>$entry) {
            $oldId = (int)$entry['id']; $entry['id'] = $base+$i+1;
            $insert->execute(array_values($entry));
            $moveComments->execute([$entry['id'],$oldId]);
        }
        foreach ($news as $entry) $remove->execute([$entry['id']]);
        $clearKey = $pdo->prepare('UPDATE news SET legacy_key = NULL WHERE id = ?');
        foreach ($news as $i=>$entry) $clearKey->execute([$base+$i+1]);
        foreach ($news as $i=>$entry) {
            $entry['id'] = $i+1;
            $insert->execute(array_values($entry));
            $moveComments->execute([$entry['id'],$base+$i+1]);
            $remove->execute([$base+$i+1]);
        }
        $actual = $pdo->query('SELECT * FROM news ORDER BY id')->fetchAll();
        $expected = $news;
        foreach ($expected as $i=>&$entry) $entry['id']=$i+1;
        unset($entry);
        if ($actual !== $expected) throw new RuntimeException('News content verification failed');
        $expectedComments = $comments;
        foreach ($expectedComments as &$comment) $comment['news_id'] = $mapping[(int)$comment['news_id']];
        unset($comment);
        if ($pdo->query('SELECT * FROM comments ORDER BY id')->fetchAll() !== $expectedComments) throw new RuntimeException('Comment reference verification failed');
    }
    if ($driver === 'sqlite') {
        $sequence = $pdo->prepare("UPDATE sqlite_sequence SET seq = ? WHERE name = 'news'");
        $sequence->execute([count($news)]);
    }
    $pdo->commit(); $committed = true;
    if ($driver === 'mysql') $pdo->exec('ALTER TABLE news AUTO_INCREMENT = '.(count($news)+1));
    $report['mode']='applied';
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,($committed ? 'IDs committed; counter reset requires retry: ' : 'No record changes committed: ').$e->getMessage().PHP_EOL);
    exit(1);
}