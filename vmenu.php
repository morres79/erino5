<!--?php include 'top.php'; ?-->

<!-- Вертикальное меню слева -->
<div class="vmenu">
  <li><a class="<?php if ($_SERVER["REQUEST_URI"] == "/index.php" or $_SERVER["REQUEST_URI"] == "/") echo "active";?>" href="index.php">Главная</a></li>
  <li><a class="<?php if ($_SERVER["REQUEST_URI"] == "/about.php") echo "active";?>" href="about.php">Информация о ТСЖ</a></li>
  <li><a class="<?php if ($_SERVER["REQUEST_URI"] == "/about_house.php") echo "active";?>" href="about_house.php">Информация о доме</a></li>
  <li><a class="<?php if ($_SERVER["REQUEST_URI"] == "/docs.php") echo "active";?>" href="docs.php">Документы</a></li>
  <li class = "sub hidden">Нормативно-правовая база</li>
  <li class = "sub hidden">Правила проживания...</li>
  <li class = "sub hidden">Заключенные Договоры</li>
  <li class = "sub hidden">Тарифы, квитанции</li>
  <li class = "sub hidden">Прочие документы</li>
  <li><a class="<?php if ($_SERVER["REQUEST_URI"] == "/tv.php") echo "active";?>" href="tv.php">Телевидение</a></li>
  <li><a class="<?php if ($_SERVER["REQUEST_URI"] == "/contacts.php") echo "active";?>" href="contacts.php">Контакты</a></li>
  <li class = "sub hidden">Люди обслуживающие дом</li>
  <li class = "sub hidden">Интернет-провайдеры</li>
  <li class = "sub hidden">Коммунальные организации</li>
  <li class = "sub hidden">Скорая помощь</li>
  <li class = "sub hidden">Детская поликлиника</li>
  <li><a class="<?php if ($_SERVER["REQUEST_URI"] == "/register.php") echo "active";?>" href="http://erino5.flybb.ru/forum2.html" target="_blank">Форум</a></li>
  <li class = "sub"><a class="<?php if ($_SERVER["REQUEST_URI"] == "/register.php") echo "active";?>" href="register.php">Как регистрироваться</a></li>
</div>

<!--?php include 'bottom.php'; ?-->
