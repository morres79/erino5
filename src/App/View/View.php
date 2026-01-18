<?php
namespace App\View;

class View {
    // у класса View (отображение) только один метод render (отрисовка)
    public function render($template, $params) { // $template - web-страница, $params - данные, для размещения на странице
        foreach($params as $key => $value) { // перебираем все параметры
          $$key = $value; //$$key - переменная переменных (см. ниже)
        }
        ob_start();
        include $_SERVER['DOCUMENT_ROOT'] . '/views/'.$template.'.php';
        ob_end_flush();
    }
}

//You can even add more Dollar Signs
/* 
  $Bar = "a";
  $Foo = "Bar";
  $World = "Foo";
  $Hello = "World";
  $a = "Hello";

  $a; //Returns Hello
  $$a; //Returns World
  $$$a; //Returns Foo
  $$$$a; //Returns Bar
  $$$$$a; //Returns a

  $$$$$$a; //Returns Hello
  $$$$$$$a; //Returns World

 */
  //... and so on ...//