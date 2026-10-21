<?php
require __DIR__.'/app/bootstrap.php';
start_session();
function admin_news_url(int $page, int $rows, ?int $edit = null, string $anchor = 'news-table'): string {
    $params = ['news_page'=>$page, 'news_rows'=>$rows];
    if ($edit !== null && $edit > 0) $params['edit'] = $edit;
    return 'admin.php?'.http_build_query($params).($anchor !== '' ? '#'.$anchor : '');
}
$error = null; $allowed = false; $edit = null; $entries = []; $members = []; $comments = []; $saveTransaction = false;
$newsRows = (int)($_GET['news_rows'] ?? 20);
if (!in_array($newsRows, [20,50,100], true)) $newsRows = 20;
$newsPage = max(1, (int)($_GET['news_page'] ?? 1));
$newsTotal = 0; $newsLastPage = 1;
$memberPage = max(1,(int)($_GET['members_page'] ?? 1));
$commentPage = max(1,(int)($_GET['comments_page'] ?? 1));
try {
    $current = require_admin(); $allowed = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $action = post('action'); $id = (int)post('id');
        if ($action === 'save') {
            if ($id > 0) {
                db()->beginTransaction(); $saveTransaction = true;
                $lock = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $edit = query('SELECT * FROM news WHERE id = ?'.$lock, [$id])->fetch();
                if (!$edit) { http_response_code(404); throw new DomainException('Новость не найдена.'); }
                if (in_array($edit['status'], ['archived','deleted'], true)) {
                    http_response_code(409);
                    throw new DomainException('Новость доступна только для чтения. Сначала переведите её в черновик отдельной кнопкой.');
                }
            }
            $title = text_field('title', 255); $body = text_field('body', 200000);
            $signature = post('signature');
            if (preg_match('//u', $signature) !== 1 || iconv_strlen($signature, 'UTF-8') > 255) throw new DomainException('Подпись должна быть не длиннее 255 символов.');
            $status = post('status');
            if (!in_array($status, ['draft','published','archived','deleted'], true)) throw new DomainException('Некорректный статус.');
            $discussion = isset($_POST['discussion_enabled']) ? 1 : 0;
            $important = isset($_POST['is_important']) ? 1 : 0;
            if ($id > 0) {
                $published = $edit['published_at'];
                if ($published === null && in_array($status, ['published','archived'], true) && empty($edit['legacy_key'])) $published = now();
                query('UPDATE news SET title = ?, body = ?, signature = ?, status = ?, discussion_enabled = ?, is_important = ?, published_at = ?, updated_at = ? WHERE id = ?', [$title,$body,$signature,$status,$discussion,$important,$published,now(),$id]);
                db()->commit(); $saveTransaction = false;
            } else {
                query('INSERT INTO news (title, body, signature, status, discussion_enabled, is_important, author_id, created_at, published_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$title,$body,$signature,$status,$discussion,$important,$current['id'],now(),in_array($status, ['published','archived'], true) ? now() : null,now()]);
                $id = (int)db()->lastInsertId();
            }
            notice($status === 'deleted' ? 'Новость удалена с публичных страниц. Запись и комментарии сохранены; восстановление возможно через черновик.' : ($status === 'draft'
                ? 'Новость сохранена как черновик. Текст сохранён, посетители его пока не видят.'
                : ($status === 'archived' ? 'Новость сохранена в архиве. Она доступна в разделе «Архив новостей» и убрана с главной.' : 'Новость сохранена и опубликована. Теперь она доступна посетителям сайта.')));
            redirect(admin_news_url($newsPage,$newsRows,$id,'news-editor'));
        } elseif ($action === 'restore_draft') {
            $edit = query('SELECT * FROM news WHERE id = ?', [$id])->fetch();
            if (!$edit) { http_response_code(404); throw new DomainException('Новость не найдена.'); }
            if (post('status') !== 'draft' || !in_array($edit['status'], ['archived','deleted'], true)) {
                http_response_code(409); throw new DomainException('Из архива или удалённых новость можно перевести только в черновик.');
            }
            $changed = query("UPDATE news SET status = 'draft', updated_at = ? WHERE id = ? AND status = ?", [now(),$id,$edit['status']]);
            if ($changed->rowCount() !== 1) { http_response_code(409); throw new DomainException('Статус уже изменён. Обновите страницу.'); }
            notice('Новость переведена в черновик. Теперь её можно отредактировать и затем опубликовать.');
            redirect(admin_news_url($newsPage,$newsRows,$id,'news-editor'));
        } elseif ($action === 'delete_news') {
            $changed = query("UPDATE news SET status = 'deleted', updated_at = ? WHERE id = ? AND status <> 'deleted'", [now(),$id]);
            if ($changed->rowCount() !== 1) { http_response_code(409); throw new DomainException('Запись не найдена или уже удалена. Обновите страницу.'); }
            notice('Новость удалена с публичных страниц. Запись и комментарии сохранены; восстановление возможно через черновик.');
            redirect(admin_news_url($newsPage,$newsRows,$id,'news-editor'));
        } elseif ($action === 'member') {
            $status = post('status');
            if (!in_array($status, ['approved','blocked','pending'], true)) throw new DomainException('Некорректный статус.');
            query("UPDATE users SET status = ? WHERE id = ? AND role = 'owner'", [$status,$id]);
            notice('Доступ собственника изменён.'); redirect(admin_news_url($newsPage,$newsRows));
        } elseif ($action === 'remove_comment') {
            query('DELETE FROM comments WHERE id = ?', [$id]);
            notice('Комментарий удалён.'); redirect(admin_news_url($newsPage,$newsRows));
        }
    }
    $editId = max(0, (int)($_GET['edit'] ?? 0));
    if ($editId) {
        $edit = query('SELECT * FROM news WHERE id = ?', [$editId])->fetch();
        if (!$edit) { http_response_code(404); throw new DomainException('Новость не найдена.'); }
    }
    $newsTotal = (int)query('SELECT COUNT(*) FROM news')->fetchColumn();
    $newsLastPage = max(1,(int)ceil($newsTotal/$newsRows));
    $newsPage = min($newsPage,$newsLastPage);
    $entries = query('SELECT id, title, status, is_important FROM news ORDER BY '.news_order_sql().' LIMIT '.$newsRows.' OFFSET '.(($newsPage-1)*$newsRows))->fetchAll();
    $memberPage = max(1, (int)($_GET['members_page'] ?? 1));
    $members = query("SELECT id, name, email, apartment, status FROM users WHERE role = 'owner' ORDER BY CASE WHEN status = 'pending' THEN 0 ELSE 1 END, id DESC LIMIT 50 OFFSET ".(($memberPage-1)*50))->fetchAll();
    $commentPage = max(1, (int)($_GET['comments_page'] ?? 1));
    $comments = query('SELECT c.id, c.body, c.news_id, u.name FROM comments c JOIN users u ON u.id = c.user_id ORDER BY c.id DESC LIMIT 50 OFFSET '.(($commentPage-1)*50))->fetchAll();
} catch (Throwable $e) {
    if ($saveTransaction && db()->inTransaction()) db()->rollBack();
    $error = $e;
}
$readOnly = $edit && in_array($edit['status'], ['archived','deleted'], true);
$failedSave = $error && $_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save' && !$readOnly;
$formStatus = $failedSave ? post('status') : ($edit['status'] ?? 'draft');
$formDiscussion = $failedSave ? isset($_POST['discussion_enabled']) : !empty($edit['discussion_enabled']);
$formImportant = $failedSave ? isset($_POST['is_important']) : !empty($edit['is_important']);
page_start('Управление сайтом');
if ($error) render_error($error);
if ($allowed): ?>
<section id="news-editor">
<h2><?=$readOnly ? ($edit['status'] === 'deleted' ? 'Просмотр удалённой новости' : 'Просмотр архивной новости') : ($edit ? 'Редактировать новость' : 'Добавить новость')?></h2>
<?php if ($edit): ?>
<p class="editor-state"><strong>Статус: <?=h(status_label($edit['status']))?>.</strong> Последнее сохранение: <?=h($edit['updated_at'])?>.</p>
<?php if ($edit['status'] === 'published'): ?><p><a href="news.php?id=<?=(int)$edit['id']?>">Посмотреть на сайте</a></p><?php endif ?>
<?php endif ?>
<?php if ($readOnly): ?>
<?php if (!empty($edit['is_important'])): ?><p class="news-important-label">Важное объявление</p><?php endif ?>
<p><?= $edit['status'] === 'deleted' ? 'Удалённая новость скрыта с публичных страниц и доступна здесь только для чтения.' : 'Архивная новость доступна только для чтения.' ?> Для изменения текста сначала переведите её в черновик. После этого её можно отредактировать и опубликовать.</p>
<article class="inf_wraper"><div class="inf_header"><?=h($edit['title'])?></div><div class="inf_body"><?=render_news_body($edit['body'])?><?=render_news_signature($edit['signature'] ?? '')?></div></article>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="restore_draft"><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><input type="hidden" name="status" value="draft"><button>Перевести в черновик</button></form>
<?php else: ?>
<p id="news-status-help"><strong>Черновик</strong> — текст сохранён, но виден только администратору. Для размещения на главной выберите <strong>«Опубликована»</strong> и нажмите <strong>«Сохранить»</strong>. Статус <strong>«В архиве»</strong> убирает новость с главной и закрывает добавление комментариев. Архивные записи доступны только для чтения; перед редактированием и повторной публикацией их нужно перевести в черновик.</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h($edit['id'] ?? 0)?>">
<label>Заголовок<input name="title" maxlength="255" value="<?=h($failedSave ? post('title') : ($edit['title'] ?? ''))?>" required></label>
<?php include __DIR__.'/app/editor_toolbar.php'; ?>
<label>Текст новости<textarea id="news-body" name="body" rows="12" aria-describedby="news-format-help" required><?=h($failedSave ? post('body') : ($edit['body'] ?? ''))?></textarea></label>
<p id="news-format-help">Выделите фрагмент и нажмите кнопку оформления. Для списка выделите строки: каждая непустая строка станет отдельным пунктом. Жирный текст, курсив и списки можно использовать внутри важных сообщений и примечаний.</p>
<details><summary>Как задать оформление вручную</summary><p><code>[b]Жирный текст[/b]</code>, <code>[i]Курсив[/i]</code>; <code>[ul][li]Пункт[/li][/ul]</code> и <code>[ol][li]Пункт[/li][/ol]</code>; <code>[alert]Важное сообщение[/alert]</code>, <code>[comment]Примечание[/comment]</code>. Вложенные списки не поддерживаются.</p></details>
<label>Подпись внизу новости<input name="signature" maxlength="255" value="<?=h($failedSave ? post('signature') : ($edit['signature'] ?? 'Администрация ТСЖ'))?>"></label>
<p>Подпись всегда отображается последним блоком. Чтобы не добавлять подпись, оставьте поле пустым.</p>
<label>Статус<select name="status" aria-describedby="news-status-help"><option value="draft">Черновик</option><option value="published" <?=$formStatus === 'published' ? 'selected' : ''?>>Опубликована</option><option value="archived" <?=$formStatus === 'archived' ? 'selected' : ''?>>В архиве</option><option value="deleted" <?=$formStatus === 'deleted' ? 'selected' : ''?>>Удалить</option></select></label>
<label class="check"><input type="checkbox" name="is_important" value="1" <?=$formImportant ? 'checked' : ''?>> Важное объявление</label>
<p>Опубликованная запись с этой отметкой отображается в разделе «Важные объявления» на главной вместо общей ленты. Статус «Удалить» скрывает запись со всех публичных страниц, сохраняя её в управлении.</p>
<label class="check"><input type="checkbox" name="discussion_enabled" value="1" <?=$formDiscussion ? 'checked' : ''?>> Разрешить обсуждение собственникам</label><button>Сохранить</button> <a href="<?=h(admin_news_url($newsPage,$newsRows,null,'news-editor'))?>">Добавить другую новость</a></form>
<?php if ($edit): ?><details class="news-preview"><summary>Предпросмотр сохранённой новости</summary><article class="inf_wraper"><div class="inf_header"><?=h($edit['title'])?></div><div class="inf_body"><?=render_news_body($edit['body'])?><?=render_news_signature($edit['signature'] ?? '')?></div></article></details><?php endif ?>
<script src="js/news-editor.js" defer></script>
<?php endif ?>
<?php if ($edit && $edit['status'] !== 'deleted'): ?>
<form method="post" class="news-delete-form"><?=csrf_field()?><input type="hidden" name="action" value="delete_news"><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><button>Удалить новость</button><span>Запись будет скрыта с сайта. Её можно будет восстановить в черновик.</span></form>
<?php endif ?>
</section>
<section id="news-table">
<h2 id="news-list-heading">Новости</h2>
<form method="get" action="admin.php#news-table" class="news-list-controls">
<?php if ($edit): ?><input type="hidden" name="edit" value="<?=(int)$edit['id']?>"><?php endif ?>
<input type="hidden" name="news_page" value="1">
<label>Строк на странице <select name="news_rows"><?php foreach ([20,50,100] as $size): ?><option value="<?=$size?>" <?=$newsRows === $size ? 'selected' : ''?>><?=$size?></option><?php endforeach ?></select></label><button>Показать</button><span>Всего: <?=$newsTotal?></span>
</form>
<div class="news-table-scroll"><table class="news-admin-table" aria-labelledby="news-list-heading"><thead><tr><th scope="col">ID</th><th scope="col">Тема</th><th scope="col">Статус</th></tr></thead><tbody>
<?php foreach ($entries as $entry): ?><tr<?=$entry['status'] === 'deleted' ? ' class="news-deleted"' : ''?>><td><?=(int)$entry['id']?></td><td><a href="<?=h(admin_news_url($newsPage,$newsRows,(int)$entry['id'],'news-editor'))?>"><?=h($entry['title'])?></a><?php if (!empty($entry['is_important'])): ?> <span class="news-important-label">Важное объявление</span><?php endif ?></td><td><?php if ($entry['status'] === 'published'): ?><a href="news.php?id=<?=(int)$entry['id']?>">Опубликована</a><?php else: ?><span><?=h(status_label($entry['status']))?></span><?php endif ?></td></tr><?php endforeach ?>
<?php if (!$entries): ?><tr><td colspan="3">Новостей пока нет.</td></tr><?php endif ?>
</tbody></table></div>
<?php if ($newsLastPage > 1): ?><nav class="news-pagination" aria-label="Страницы таблицы новостей">
<?php if ($newsPage > 1): ?><a href="<?=h(admin_news_url($newsPage-1,$newsRows,$edit ? (int)$edit['id'] : null))?>">← Назад</a><?php endif ?>
<?php if ($newsPage > 3): ?><a href="<?=h(admin_news_url(1,$newsRows,$edit ? (int)$edit['id'] : null))?>">1</a><span>…</span><?php endif ?>
<?php for ($p=max(1,$newsPage-2); $p<=min($newsLastPage,$newsPage+2); $p++): ?>
<?php if ($p === $newsPage): ?><span aria-current="page"><?=$p?></span><?php else: ?><a href="<?=h(admin_news_url($p,$newsRows,$edit ? (int)$edit['id'] : null))?>"><?=$p?></a><?php endif ?>
<?php endfor ?>
<?php if ($newsPage < $newsLastPage-2): ?><span>…</span><a href="<?=h(admin_news_url($newsLastPage,$newsRows,$edit ? (int)$edit['id'] : null))?>"><?=$newsLastPage?></a><?php endif ?>
<?php if ($newsPage < $newsLastPage): ?><a href="<?=h(admin_news_url($newsPage+1,$newsRows,$edit ? (int)$edit['id'] : null))?>">Далее →</a><?php endif ?>
<span>Страница <?=$newsPage?> из <?=$newsLastPage?></span></nav><?php endif ?>
</section>
<h2>Собственники и заявки</h2><p>Перед подтверждением проверьте право собственности по данным ТСЖ вне сайта. Блокировка немедленно закрывает доступ к обсуждениям.</p>
<?php foreach ($members as $member): ?><article class="comment"><p><?=h($member['name'])?> · квартира <?=h($member['apartment'])?> · <?=h($member['email'])?> · <?=h(status_label($member['status']))?></p>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="member"><input type="hidden" name="id" value="<?=(int)$member['id']?>"><label>Доступ<select name="status"><option value="pending" <?=$member['status'] === 'pending' ? 'selected' : ''?>>На проверке</option><option value="approved" <?=$member['status'] === 'approved' ? 'selected' : ''?>>Подтверждён</option><option value="blocked" <?=$member['status'] === 'blocked' ? 'selected' : ''?>>Заблокирован</option></select></label><button>Изменить доступ</button></form></article><?php endforeach ?>
<p><?php if ($memberPage > 1): ?><a href="admin.php?members_page=<?=$memberPage-1?>">Предыдущие собственники</a> · <?php endif ?><a href="admin.php?members_page=<?=$memberPage+1?>">Следующие собственники</a></p>
<h2>Модерация комментариев</h2><?php foreach ($comments as $comment): ?><article class="comment"><a href="news.php?id=<?=(int)$comment['news_id']?>">Новость</a> · <?=h($comment['name'])?><p><?=nl2br(h($comment['body']))?></p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="remove_comment"><input type="hidden" name="id" value="<?=(int)$comment['id']?>"><button>Удалить комментарий</button></form></article><?php endforeach ?>
<p><?php if ($commentPage > 1): ?><a href="admin.php?comments_page=<?=$commentPage-1?>">Предыдущие комментарии</a> · <?php endif ?><a href="admin.php?comments_page=<?=$commentPage+1?>">Следующие комментарии</a></p>
<?php endif; page_end();