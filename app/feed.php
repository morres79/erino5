<?php
require_once __DIR__.'/bootstrap.php';
$feedStatus = ($newsFeedStatus ?? 'published') === 'archived' ? 'archived' : 'published';
$feedPath = $feedStatus === 'archived' ? 'archive.php' : 'index.php';
try {
    $filter = 'status = ?'.($feedStatus === 'published' ? ' AND is_important = 0' : '');
    $count = (int)query('SELECT COUNT(*) FROM news WHERE '.$filter, [$feedStatus])->fetchColumn();
    $lastPage = max(1, (int)ceil($count / 10));
    $feedPage = max(1, min($lastPage, (int)($_GET['page'] ?? 1)));
    $rows = query('SELECT * FROM news WHERE '.$filter.' ORDER BY '.news_order_sql().' LIMIT 10 OFFSET '.(($feedPage - 1) * 10), [$feedStatus])->fetchAll();
    foreach ($rows as $row) echo render_news_entry($row);
    if (!$rows) echo '<p>'.($feedStatus === 'archived' ? 'Архив пока пуст.' : 'Опубликованных новостей пока нет.').'</p>';
    if ($lastPage > 1) {
        echo '<nav class="inf_link" aria-label="Страницы новостей">';
        if ($feedPage > 1) echo '<a href="'.$feedPath.'?page='.($feedPage-1).'">Предыдущая страница</a> · ';
        echo 'Страница '.$feedPage.' из '.$lastPage;
        if ($feedPage < $lastPage) echo ' · <a href="'.$feedPath.'?page='.($feedPage+1).'">Следующая страница</a>';
        echo '</nav>';
    }
} catch (Throwable $e) { error_log((string)$e); echo '<p>Новости временно недоступны. Повторите попытку позднее.</p>'; }