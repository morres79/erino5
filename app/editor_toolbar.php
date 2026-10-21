<?php
$tools = [
    ['b','Жирный текст (Ctrl+B)','<path d="M6 4h7a4 4 0 0 1 0 8H6zm0 8h8a4 4 0 0 1 0 8H6z"/>'],
    ['i','Курсив (Ctrl+I)','<path d="M10 4h8M6 20h8M14 4l-4 16"/>'],
    ['ul','Маркированный список','<circle cx="4" cy="6" r="1" fill="currentColor"/><circle cx="4" cy="12" r="1" fill="currentColor"/><circle cx="4" cy="18" r="1" fill="currentColor"/><path d="M9 6h12M9 12h12M9 18h12"/>'],
    ['ol','Нумерованный список','<g fill="currentColor" stroke="none" font-size="7" font-family="Arial"><text x="1" y="8">1</text><text x="1" y="14">2</text><text x="1" y="20">3</text></g><path d="M9 6h12M9 12h12M9 18h12"/>'],
    ['alert','Важное сообщение','<path d="M12 3 2 21h20zM12 9v5"/><circle cx="12" cy="17.5" r=".7" fill="currentColor"/>'],
    ['comment','Примечание','<path d="M4 4h16v12H9l-5 4zM8 8h8M8 12h5"/>'],
];
?>
<div class="news-toolbar" role="group" aria-label="Оформление текста новости">
<?php foreach ($tools as [$format,$label,$icon]): ?><button type="button" data-news-format="<?=h($format)?>" title="<?=h($label)?>" aria-label="<?=h($label)?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?=$icon?></svg></button><?php endforeach ?>
</div>