<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Документ без названия</title>
</head>
<?php
/*  Прием SMS из GOIP
    whoim, whoim@mail.ru
*/

$goip_addr = "http://gw1.erino5.ru/"; #Внешний (если работа ведется через интернет) адрес goip. Возможно, придется пробросить порт на роутере, например, 7454 => local_goip_addr:80
$goip_user = "admin";
$goip_password = "d86d8754";

#Это нужно для авторизации на goip-е
$context = stream_context_create(array(
    'http' => array(
        'header'  => "Authorization: Basic " . base64_encode("$goip_user:$goip_password")
    )
));
#получаем сырые данные - html
$data = file_get_contents($goip_addr."/default/en_US/tools.html?type=sms_inbox", false, $context);
#у меня встречались такие косяки, правим
$data = str_replace('\"', '"', $data); //fix
#выдираем содержимое sms из js-скрипта в html, ключ = каналу sim
preg_match_all("|sms= \[(.*?)\]|is", $data, $sms_dump_arr);


$sms = array();
#первый цикл - по "каналам sim", которые содержат 5 смс-ок, разделенных запятой и в кавычках. То есть в csv.
#Во втором цикле мы с помощью str_getcsv добываем валидно данные уже по каждой смс раздельно.
foreach($sms_dump_arr[1] as $sim_key => $sim_val) 
  foreach(str_getcsv($sim_val) as $sms_key => $sms_val) {
      $sms[$sim_key][$sms_key] = explode(',', $sms_val, 3); #ключи 0,1,2 содержат датувремя, номер, текст смс.
      $sms[$sim_key][$sms_key][] = md5($sms_val); #ключ 3 содержит хеш первых трех, для упрощения идентификации sms-ки в дальнейшем
      }

#смотрим результат
print_r($sms);
?>
<body>
</body>
</html>