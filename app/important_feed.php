<?php
require_once __DIR__.'/bootstrap.php';
try {
    $rows = query("SELECT * FROM news WHERE status = 'published' AND is_important = 1 ORDER BY ".news_order_sql())->fetchAll();
    foreach ($rows as $row) echo render_news_entry($row);
    if (!$rows) echo '<p>Важных объявлений пока нет.</p>';
} catch (Throwable $e) { error_log((string)$e); echo '<p>Объявления временно недоступны. Повторите попытку позднее.</p>'; }
