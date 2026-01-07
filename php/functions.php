<?php 
    function page_title($uri){
        switch ($uri){
            case ($uri == "/" || $uri == "/index.php"):
                return "Главная";
            case "/archive.php":
                return "Архив";
            case "/about.php":
                return "О ТСЖ";
            case "/about_house.php":
                return "О доме";
            case ($uri == "/docs.php" || $uri == "/manager_rules.php" || $uri == "/org_rules.php" || $uri == "/live_rules.php"):
                return "Документы";
            case "/tv.php":
                return "Телевидение";
            case "/contacts.php":
                return "Контакты";
            case "/register.php":
                return "Как регистрироваться";
            default:
                return "NoName";
        }
    }
?>