<?php
require_once __DIR__.'/bootstrap.php';
if (settings()['dsn'] !== '') {
    try {
        $recent = query("SELECT id, title, body, published_at FROM news WHERE status = 'published' ORDER BY published_at DESC, id DESC LIMIT 10")->fetchAll();
        foreach ($recent as $entry) {
            echo '<div class="inf_wraper"><div class="inf_header"><a href="news.php?id='.(int)$entry['id'].'">'.h($entry['title']).'</a></div><div class="inf_body">';
            echo '<p><time>'.h($entry['published_at']).'</time></p><p>'.nl2br(h(iconv_substr($entry['body'], 0, 400, 'UTF-8'))).'</p><a href="news.php?id='.(int)$entry['id'].'">Читать новость</a></div></div>';
        }
        if ($recent) echo '<div class="inf_link"><a href="news.php">Все новые публикации</a></div>';
    } catch (Throwable $e) { error_log((string)$e); echo '<p>Новые публикации временно недоступны.</p>'; }
}