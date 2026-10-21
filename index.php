<!doctype html>
<html lang="ru">

<?php include_once 'php/head.php'; ?>

<body>

    <?php include_once 'header.php'; ?>

    <section>
        <div class="conteiner">
            <?php include 'vmenu.php'; ?>
            <div class="inf_frame">
                <div id="current-news">
                <div class="inf_main_header">новости ТСЖ</div>
                <?php $newsFeedStatus = 'published'; include __DIR__.'/app/feed.php'; ?>
                <div class="inf_link"><a href="archive.php">Перейти в Архив новостей</a></div>
                </div>
                <div id="important-notices">
                <div class="inf_main_header">Важные объявления</div>
                <?php include __DIR__.'/app/important_feed.php'; ?>
                </div>

            </div> <!-- inf_frame -->
        </div> <!-- conteiner -->

    </section>
  <!--Подвал-->
  <?php include_once 'footer.php'; ?>

</body>
</html>
