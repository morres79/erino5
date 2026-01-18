<!doctype html>
<html lang="ru">

<?php include_once 'head.php'; ?>

<body>
    <?php include_once 'header.php'; ?>

    <h1><?= $titlePage ?></h1>
        <?php if($_SERVER['REDIRECT_URL']){ ?>
            <div class="alert alert-dark " role="alert">
            <?= $_SERVER['REDIRECT_URL'] ?>
            </div>
        <?php } ?>

    <?php include_once 'footer.php'; ?>
</body>
</html>
