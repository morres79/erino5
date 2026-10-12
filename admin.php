<?php
require __DIR__.'/app/bootstrap.php';
start_session();
$error = null; $allowed = false; $edit = null; $entries = []; $requests = []; $members = []; $comments = [];
try {
    $current = require_admin(); $allowed = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $action = post('action'); $id = (int)post('id');
        if ($action === 'save') {
            $title = text_field('title', 255); $body = text_field('body', 200000);
            $status = post('status');
            if (!in_array($status, ['draft', 'published'], true)) throw new DomainException('Некорректный статус.');
            $discussion = isset($_POST['discussion_enabled']) ? 1 : 0;
            if ($id > 0) {
                $old = query('SELECT * FROM news WHERE id = ?', [$id])->fetch();
                if (!$old) throw new DomainException('Новость не найдена.');
                $published = $old['published_at'] ?: ($status === 'published' ? now() : null);
                query('UPDATE news SET title = ?, body = ?, status = ?, discussion_enabled = ?, published_at = ?, updated_at = ? WHERE id = ?', [$title, $body, $status, $discussion, $published, now(), $id]);
            } else {
                query('INSERT INTO news (title, body, status, discussion_enabled, author_id, created_at, published_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$title, $body, $status, $discussion, $current['id'], now(), $status === 'published' ? now() : null, now()]);
            }
            notice('Новость сохранена.'); redirect('admin.php');
        } elseif ($action === 'member') {
            $status = post('status');
            if (!in_array($status, ['approved', 'blocked', 'pending'], true)) throw new DomainException('Некорректный статус.');
            query("UPDATE users SET status = ? WHERE id = ? AND role = 'owner'", [$status, $id]);
            notice('Доступ собственника изменён.'); redirect('admin.php');
        } elseif ($action === 'remove_comment') {
            query('DELETE FROM comments WHERE id = ?', [$id]);
            notice('Комментарий удалён.'); redirect('admin.php');
        }
    }
    $editId = max(0, (int)($_GET['edit'] ?? 0));
    if ($editId) $edit = query('SELECT * FROM news WHERE id = ?', [$editId])->fetch();
    $newsPage = max(1, (int)($_GET['news_page'] ?? 1));
    $entries = query('SELECT id, title, status, discussion_enabled FROM news ORDER BY id DESC LIMIT 50 OFFSET '.(($newsPage - 1) * 50))->fetchAll();
    $memberPage = max(1, (int)($_GET['members_page'] ?? 1));
    $members = query("SELECT id, name, email, apartment, status FROM users WHERE role = 'owner' ORDER BY CASE WHEN status = 'pending' THEN 0 ELSE 1 END, id DESC LIMIT 50 OFFSET ".(($memberPage - 1) * 50))->fetchAll();
    $commentPage = max(1, (int)($_GET['comments_page'] ?? 1));
    $comments = query('SELECT c.id, c.body, c.news_id, u.name FROM comments c JOIN users u ON u.id = c.user_id ORDER BY c.id DESC LIMIT 50 OFFSET '.(($commentPage - 1) * 50))->fetchAll();
} catch (Throwable $e) { $error = $e; }
page_start('Управление сайтом');
if ($error) render_error($error);
if ($allowed): ?>
<h2><?=$edit ? 'Редактировать новость' : 'Добавить новость'?></h2>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h($edit['id'] ?? 0)?>">
<label>Заголовок<input name="title" maxlength="255" value="<?=h($_POST['title'] ?? $edit['title'] ?? '')?>" required></label>
<label>Текст новости<textarea name="body" rows="12" required><?=h($_POST['body'] ?? $edit['body'] ?? '')?></textarea></label><p>Текст публикуется без HTML. Абзацы и переносы строк сохраняются.</p>
<label>Статус<select name="status"><option value="draft">Черновик</option><option value="published" <?=($edit['status'] ?? '') === 'published' ? 'selected' : ''?>>Опубликована</option></select></label>
<label class="check"><input type="checkbox" name="discussion_enabled" value="1" <?=!empty($edit['discussion_enabled']) ? 'checked' : ''?>> Разрешить обсуждение собственникам</label><button>Сохранить</button> <a href="admin.php">Новая запись</a></form>
<h2>Новости</h2>
<?php foreach ($entries as $entry): ?><p><a href="admin.php?edit=<?=(int)$entry['id']?>"><?=h($entry['title'])?></a> — <?=h(status_label($entry['status']))?> <?php if ($entry['status'] === 'published'): ?><a href="news.php?id=<?=(int)$entry['id']?>">Открыть</a><?php endif ?></p><?php endforeach ?>
<p><?php if ($newsPage > 1): ?><a href="admin.php?news_page=<?=$newsPage-1?>">Предыдущие новости</a> · <?php endif ?><a href="admin.php?news_page=<?=$newsPage+1?>">Следующие новости</a></p><h2>Собственники и заявки</h2><p>Перед подтверждением проверьте право собственности по данным ТСЖ вне сайта. Блокировка немедленно закрывает доступ к обсуждениям.</p>
<?php foreach ($members as $member): ?><article class="comment"><p><?=h($member['name'])?> · квартира <?=h($member['apartment'])?> · <?=h($member['email'])?> · <?=h(status_label($member['status']))?></p>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="member"><input type="hidden" name="id" value="<?=(int)$member['id']?>"><label>Доступ<select name="status"><option value="pending" <?=$member['status'] === 'pending' ? 'selected' : ''?>>На проверке</option><option value="approved" <?=$member['status'] === 'approved' ? 'selected' : ''?>>Подтверждён</option><option value="blocked" <?=$member['status'] === 'blocked' ? 'selected' : ''?>>Заблокирован</option></select></label><button>Изменить доступ</button></form></article><?php endforeach ?>
<p><?php if ($memberPage > 1): ?><a href="admin.php?members_page=<?=$memberPage-1?>">Предыдущие собственники</a> · <?php endif ?><a href="admin.php?members_page=<?=$memberPage+1?>">Следующие собственники</a></p>
<h2>Модерация комментариев</h2><?php foreach ($comments as $comment): ?><article class="comment"><a href="news.php?id=<?=(int)$comment['news_id']?>">Новость</a> · <?=h($comment['name'])?><p><?=nl2br(h($comment['body']))?></p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="remove_comment"><input type="hidden" name="id" value="<?=(int)$comment['id']?>"><button>Удалить комментарий</button></form></article><?php endforeach ?>
<p><?php if ($commentPage > 1): ?><a href="admin.php?comments_page=<?=$commentPage-1?>">Предыдущие комментарии</a> · <?php endif ?><a href="admin.php?comments_page=<?=$commentPage+1?>">Следующие комментарии</a></p>
<?php endif; page_end();