<?php
namespace App;
use App\Controllers\NotFoundController;

class App
{
    private array $routes;

    public function __construct() // Отрабатывает при создании объекта класса App
    {        
        // Получаем всё содержимое файла routes.json:
        $jsonRoutes = file_get_contents( $_SERVER['DOCUMENT_ROOT'] . '/config/routes.json');
        // Присваиваем свойтву routes массив всех маршрутов из файла:
        $this->routes = json_decode($jsonRoutes, true);
        //print_r($this->routes);
    }
    public function __invoke() { //отрабатывает, если объект класса App вызывается как функция
        // Дефолные значения
        $controller = '\App\Controllers\NotFoundController';
        $action = 'index';
        // $_SERVER['REDIRECT_URL'] (см. ниже) будет работать, если для Apache в корне сайта лежит .htaccess 
        // со следующим содержимым:
        /*  RewriteEngine on
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteRule (.*) index.php/?action=$1 [QSA,L] */
            
        // если $_SERVER['REDIRECT_URL'] не содержит значений, то route получит "/":
        $route = $_SERVER['REDIRECT_URL'] ?? '/'; 
        //                    /
        //                    /about
        if(array_key_exists( $route, $this->routes  )) { //Если в массиве $routes будет найден индекс $route
            // list помещает данные для $controller и $action, которые возвращает explode из массива $routes
            // с индесом $route, разделив значения по двоеточию:
            list($controller, $action) = explode(':', $this->routes[$route]);
            /* Например:
                \\App\\Controllers\\IndexController:index
                в $controller попадёт "\\App\\Controllers\\IndexController"
                в $action попадёт "index"

                \\App\\Controllers\\IndexController:about
                в $controller попадёт "\\App\\Controllers\\IndexController"
                в $action попадёт "about"
            */
        }

        // Если переменная route существует и она не содержит пустого значения:
//        if (isset( $route ) && !empty( $route )) {
            try {
                // то создаём объект класса $controller (в папке Controllers) и сразу выполняем метод $action
                (new $controller)->$action();
            }
            catch(\Error $er) {
                (new NotFoundController())->index();
                die;
            }  
//        }
    }
}