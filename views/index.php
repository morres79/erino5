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

                <?php if($message){ ?>
                        <div class="alert alert-success" role="alert">
                        <?= $message ?>
                        </div>
                <?php } ?>

                <div class = "inf_wraper">
                    <div class = "inf_header">ОСТОРОЖНО МОШЕННИКИ!</div>
                    <div class = "inf_body">
                        <b>Уважаемые жильцы!</b><br>
                        Администрация ТСЖ «Еринские Высоты» <strong>не создаёт домовые чаты</strong>! Будьте бдительны, Вам пишут мошенники!<br>
                        <br>
                        В случае каких-либо плановых/внеплановых работ информация публикуется на <a href="/">официальном сайте ТСЖ</a> и на досках объявлений.<br>
                        <br>
                        Если Вам звонят с незнакомого номера и предлагают <strong>плановую замену домофона</strong>, то это мошенники! <br>
                        <strong>Ни в коем случае не сообщайте каких-либо персональных данных или кодов, которые Вам приходят в телефон!</strong>
                        <div class="inf_sign">Администрация ТСЖ</div>
                    </div>
                </div>

                <div class = "inf_wraper">
                    <div class = "inf_header">С 1 января 2026 г. устанавливаются следующие тарифы</div>
                    <div class = "inf_body">
                        <div class="inf_comment">
                            Согласно постановлениям правительства г. Москвы <b>№2902-ПП, №2905-ПП от 26.11.2025</b>.
                        </div>
                        <b>ТО и содержание жилого фонда</b> - 50,91 руб./м<sup>2</sup><br>
                        <b>Взнос на капремонт</b> - 32,63 руб./м<sup>2</sup><br>
                        <b>Горячая вода</b> – 237,55 руб./м<sup>3</sup><br> 
                        <b>Холодная вода</b> – 63,83 руб./м<sup>3</sup><br>
                        <b>Водоотведение</b> – 52,48 руб./м<sup>3</sup><br>
                        <b>Отопление</b> – 3621,84 руб кВтч<br>
                        <div class="inf_sign">Администрация ТСЖ</div>
                    </div>
                </div>
            
                <div class="inf_link"><a href="/archive">Перейти в Архив новостей</a></div>

                <div class="inf_main_header">Важные объявления</div>

                <div class = "inf_wraper">
                    <div class = "inf_header">График работы ТСЖ</div>
                    <div class =  "inf_body">
                      ТСЖ "Еринские Высоты" работает<br>
                      <br>
                      <span class="inf_impotent">с понедельника по пятницу с 08:00 часов до 17:00 часов.</span><br>
                      <br>
                      Суббота, воскресение - выходные дни.<br>
                      <br>
                      В случае экстренной необходимости (при прорывах трубопроводов, отключений электроэнергии, 
                      <div class="inf_alert">жгучем желании погасить долг по коммуналке</div>
                      и т.п.) можно <a href="contacts.php">звонить</a> в любое время. Телефоны доступны в разделе <a href="contacts.php">Контакты.</a>
                      <div class="inf_sign">Администрация ТСЖ</div>
                    </div>
                </div>  

                <div class = "inf_wraper">
                    <div class = "inf_header">Поэтажный график уборки подъездов</div>
                    <div class = "inf_body">
                        <ul>
                            <li>ПОНЕДЕЛЬНИК 	- <strong>2,3,4 этажи</strong></li>
                            <li>ВТОРНИК 		- <strong>5,6,7 этажи</strong></li>
                            <li>СРЕДА 		- <strong>8,9,10 этажи</strong></li>
                            <li>ЧЕТВЕРГ 		- <strong>11,12,13 этажи</strong></li>
                            <li>ПЯТНИЦА		- <strong>14,15,16 этажи</strong></li>
                            <li>СУББОТА 		- <strong>17 этажи</strong></li>
                        </ul>
                        <div class="inf_sign">Администрация ТСЖ</div>
                    </div>
                </div>

                <div class = "inf_wraper">
                    <div class = "inf_header">Квитанции на оплату коммунальных услуг </div>
                    <div class = "inf_body">
                        Уважаемые владельцы (собственники) квартир. <br>
                        <br>
                        Квитанции на оплату коммунальных услуг своевременно вынимайте из своих почтовых ящиков! <br>
                        В индивидуальных случаях, при длительном отсутствии на территории Московской области, <strong>квитанция может быть выслана 
                        Вам факсом или по электронной почте</strong> (обращаться к управляющей 
                        Борисовой Л.Н. по тел. 8-964-768-14-18). <br>
                        <div class="inf_sign">Администрация ТСЖ</div>
                    </div>
                </div>

                <div class = "inf_wraper">
                    <div class = "inf_header"> Перепланировка квартир </div>
                    <div class = "inf_body"> 
                        Уважаемые собственники квартир! <br>
                        <br>
                        Предостерегаем вас от незаконных перепланировок квартир. <br>
                        Ставим всех в известность о том, что <strong>при обнаружении незаконных перепланировок</strong>, особенно связанных с изменением несущих 
                        конструкций,<strong> Администрация ТСЖ немедленно выдаст ПРЕДПИСАНИЕ об устранении указанных нарушений</strong>. В случае, если в течение 
                        14 дней перепланировка не будет узаконена, ТСЖ незамедлительно обратится в СУД с исковым заявлением. <br>
                        <div class="inf_sign">Администрация ТСЖ</div>
                    </div>
                </div>

            </div> <!-- inf_frame -->
        </div> <!-- conteiner -->

    </section>
  <!--Подвал-->
  <?php include_once 'footer.php'; ?>

</body>
</html>
