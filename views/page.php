<!doctype html>
<html lang="ru">

<?php include_once 'head.php'; ?>

<body>

    <?php include_once 'header.php'; ?>
 
    <section>
        <div class="conteiner">
            <?php include 'vmenu.php'; ?>
            <div class="inf_frame">
                <div class="inf_main_header"><?= $titlePage ?></div>

                    <?php  /*
                        $infoblock[first] 
                        $infoblock[second] 
                        $infoblock[etc] 
                        ... */
                    ?>

            </div> <!-- inf_frame -->
            <?php include_once 'footer.php'; ?>
        </div> <!-- conteiner -->

    </section>

    <!--Подвал-->
    <?php include_once 'footer.php'; ?>
</body>
</html>
