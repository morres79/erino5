<?php
namespace App\Controllers;
use App\View\View;

class BaseController
{
  public $view;
  
  public function __construct() //когда создаётся экземпляр BaseController
  {
    // тогда же создаётся и экземпляр класса View, который помещается, как свойство view 
    // созданного BaseController-а
    $this->view = new View;
  }

  public function render($template, $params) //когда вызывается метод render (отрисовка) BaseController-а, 
  {
    // тогда вызывается render у экземпляра класса View, куда передаются шаблон страницы и параметры книги
    return $this->view->render($template, $params);
  }
}