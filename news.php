<?php
require __DIR__.'/app/bootstrap.php';
start_session();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($id <= 0) redirect('index.php'.(isset($_GET['page']) ? '?page='.max(1, (int)$_GET['page']) : ''));
$error = null; $item = null; $comments = []; $current = null; $total = 0;
$page = max(1, min(1000000, (int)($_GET['page'] ?? 1)));
try {
    $current = user();
    $item = query("SELECT * FROM news WHERE id = ? AND status IN ('published','archived')", [$id])->fetch();
    if (!$item) { http_response_code(404); throw new DomainException('Новость не найдена.'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if (!is_owner($current)) { http_response_code(403); throw new DomainException('Доступ к обсуждению предоставляется после подтверждения ТСЖ.'); }
        if (!$item['discussion_enabled'] || $item['status'] !== 'published') { http_response_code(403); throw new DomainException('Обсуждение этой новости закрыто.'); }
        limit_attempt('comment', (string)$current['id'], 10, 300);
        $body = text_field('body', 12000);
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
        $page = min($page, max(1, (int)ceil($total / 50)));
        $comments = query('SELECT c.*, u.name FROM comments c JOIN users u ON u.id = c.user_id WHERE c.news_id = ? ORDER BY c.id LIMIT 50 OFFSET '.(($page - 1) * 50), [$id])->fetchAll();
    }
} catch (Throwable $e) { $error = $e; }
page_start($item && $item['status'] === 'archived' ? 'Архив новостей' : 'Новости ТСЖ');
if ($error) render_error($error);
if ($item): ?>
<?php if ($item['status'] === 'archived'): ?><p>Эта новость находится в <a href="archive.php">архиве</a>. Добавление комментариев закрыто.</p><?php endif ?>
<article class="inf_wraper"><div class="inf_header"><?=h($item['title'])?></div><div class="inf_body">
<?php if ($item['published_at']): ?><p><time><?=h($item['published_at'])?></time></p><?php endif ?>
<?=render_news_body($item['body'])?><?=render_news_signature($item['signature'] ?? '')?></div></article>
<?php if ($item['discussion_enabled']): ?><section id="discussion"><h2>Обсуждение собственников</h2>
<?php if (!is_owner($current)): ?><p>Для просмотра обсуждения <a href="account.php">войдите в подтверждённый аккаунт собственника</a>.</p>
<?php else: foreach ($comments as $comment): ?>
<article class="comment"><strong><?=h($comment['name'])?></strong> <time><?=h($comment['created_at'])?></time><p><?=nl2br(h($comment['body']))?></p></article>
<?php endforeach; if ($page * 50 < $total): ?><a href="news.php?id=<?=$id?>&amp;page=<?=$page+1?>#discussion">Следующие комментарии</a><?php endif ?>
<?php if ($page > 1): ?><a href="news.php?id=<?=$id?>&amp;page=<?=$page-1?>#discussion">Предыдущие комментарии</a><?php endif ?>
<?php if ($item['status'] === 'published'): ?><form method="post"><?=csrf_field()?><label>Ваш комментарий<textarea name="body" rows="5" maxlength="4000" required></textarea></label><button>Отправить</button></form>
<?php else: ?><p>Архивное обсуждение доступно только для чтения.</p><?php endif ?>
<?php endif ?></section><?php endif ?>
<?php endif; page_end();