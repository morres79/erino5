<!doctype html>
<html lang="ru">
<?php include_once 'php/head.php'; ?>
<body>
<?php include_once 'header.php'; ?>
<section>
    <div class="conteiner">
        <?php include 'vmenu.php'; ?>
        <div class="inf_frame">
            <div class="inf_main_header">Архив новостей</div>
            <?php $newsFeedStatus = 'archived'; include __DIR__.'/app/feed.php'; ?>
            <div class="inf_link"><a href="index.php">Вернуться на главную</a></div>
        </div>
    </div>
</section>
<?php include_once 'footer.php'; ?>
</body>
</html>