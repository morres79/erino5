<?php
function page_title($uri) {
    $path = parse_url($uri, PHP_URL_PATH);
    return match ($path) {
        '/', '/index.php' => 'Главная',
        '/archive.php' => 'Архив',
        '/about.php' => 'О ТСЖ',
        '/about_house.php' => 'О доме',
        '/docs.php', '/manager_rules.php', '/org_rules.php', '/live_rules.php' => 'Документы',
        '/tv.php' => 'Телевидение',
        '/contacts.php' => 'Контакты',
        '/register.php' => 'Как регистрироваться',
        '/account.php' => 'Личный кабинет',
        '/news.php' => 'Новости',
        '/admin.php' => 'Управление',
        '/siteinfo.php' => 'О сайте',
        default => 'ТСЖ',
    };
}