<?php

require __DIR__ . '/vendor/autoload.php';
session_start();

/* print_r(get_included_files()); */

$app = new \App\App;
$app();

