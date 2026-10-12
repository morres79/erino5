<?php
require __DIR__.'/app/bootstrap.php';
start_session();
$error = null; $item = null; $items = []; $comments = []; $current = null; $total = 0;
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$page = max(1, (int)($_GET['page'] ?? 1));
try {
    $current = user();
    if ($id > 0) {
        $item = query("SELECT * FROM news WHERE id = ? AND status = 'published'", [$id])->fetch();
        if (!$item) { http_response_code(404); throw new DomainException('Новость не найдена.'); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_csrf();
            if (!is_owner($current)) { http_response_code(403); throw new DomainException('Доступ к обсуждению предоставляется после подтверждения ТСЖ.'); }
            if (!$item['discussion_enabled']) { http_response_code(403); throw new DomainException('Обсуждение этой новости закрыто.'); }
            limit_attempt('comment', (string)$current['id'], 10, 300);
            $body = text_field('body', 12000);
            // Lock the publication while adding a comment, so closing a thread cannot race the insert.
            db()->beginTransaction();
            try {
                $lock = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $live = query('SELECT status, discussion_enabled FROM news WHERE id = ?'.$lock, [$id])->fetch();
                if (!$live || $live['status'] !== 'published' || !$live['discussion_enabled']) throw new DomainException('Обсуждение закрыто.');
                query('INSERT INTO comments (news_id, user_id, body, created_at) VALUES (?, ?, ?, ?)', [$id, $current['id'], $body, now()]);
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }
            redirect('news.php?id='.$id.'#discussion');
        }
        if ($item['discussion_enabled'] && is_owner($current)) {
            $total = (int)query('SELECT COUNT(*) FROM comments WHERE news_id = ?', [$id])->fetchColumn();
            $offset = (min($page, max(1, (int)ceil($total / 50))) - 1) * 50;
            $comments = query('SELECT c.*, u.name FROM comments c JOIN users u ON u.id = c.user_id WHERE c.news_id = ? ORDER BY c.id LIMIT 50 OFFSET '.$offset, [$id])->fetchAll();
        }
    } else {
        $total = (int)query("SELECT COUNT(*) FROM news WHERE status = 'published'")->fetchColumn();
        $offset = (min($page, max(1, (int)ceil($total / 20))) - 1) * 20;
        $items = query("SELECT id, title, published_at FROM news WHERE status = 'published' ORDER BY published_at DESC, id DESC LIMIT 20 OFFSET ".$offset)->fetchAll();
    }
} catch (Throwable $e) { $error = $e; }
page_start($item ? $item['title'] : 'Новости ТСЖ');
if ($error) render_error($error);
if ($item): ?>
<article><p><time><?=h($item['published_at'])?></time></p><div class="news-text"><?=nl2br(h($item['body']))?></div></article>
<?php if ($item['discussion_enabled']): ?><section id="discussion"><h2>Обсуждение собственников</h2>
<?php if (!is_owner($current)): ?><p>Для просмотра и участия <a href="account.php">войдите в подтверждённый аккаунт собственника</a>.</p>
<?php else: foreach ($comments as $comment): ?>
<article class="comment"><strong><?=h($comment['name'])?></strong> <time><?=h($comment['created_at'])?></time><p><?=nl2br(h($comment['body']))?></p></article>
<?php endforeach; if ($page * 50 < $total): ?><a href="news.php?id=<?=$id?>&amp;page=<?=$page+1?>#discussion">Следующие комментарии</a><?php endif ?>
<?php if ($page > 1): ?><a href="news.php?id=<?=$id?>&amp;page=<?=$page-1?>#discussion">Предыдущие комментарии</a><?php endif ?>
<form method="post"><?=csrf_field()?><label>Ваш комментарий<textarea name="body" rows="5" maxlength="4000" required></textarea></label><button>Отправить</button></form>
<?php endif ?></section><?php endif ?>
<?php else: foreach ($items as $entry): ?><article class="comment"><time><?=h($entry['published_at'])?></time><h2><a href="news.php?id=<?=(int)$entry['id']?>"><?=h($entry['title'])?></a></h2></article><?php endforeach ?>
<?php if (!$items && !$error): ?><p>Новых публикаций пока нет.</p><?php endif ?>
<?php if ($page * 20 < $total): ?><a href="news.php?page=<?=$page+1?>">Следующие новости</a><?php endif ?>
<?php if ($page > 1): ?><a href="news.php?page=<?=$page-1?>">Предыдущие новости</a><?php endif ?>
<p><a href="index.php">Существующие объявления</a> · <a href="archive.php">Архив</a></p>
<?php endif; page_end();