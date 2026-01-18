<?php
namespace App\Controllers;

use App\View\View;
use App\Controllers\BaseController;

class IndexController extends BaseController {

    public function index() {   
        $titlePage = 'ГЛАВНАЯ';
      
        // получаю flash-сообщение
        $message = '';
        if( array_key_exists('flash', $_SESSION)){
            $message = $_SESSION['flash'];
            unset($_SESSION['flash']);
        }

        return $this->render('index', [
            'titlePage' => $titlePage,
            'message' => $message,
        ]);
    }

    public function archive() {   
        $titlePage = 'АРХИВ';

        return $this->render('archive', [
            'titlePage' => $titlePage,
        ]);
    }

    public function about() {   
        $titlePage = 'О ТСЖ';

        return $this->render('about', [
            'titlePage' => $titlePage,
        ]);
    }

    public function about_house() {   
        $titlePage = 'О ДОМЕ';

        return $this->render('about_house', [
            'titlePage' => $titlePage,
        ]);
    }

    public function docs() {   
        $titlePage = 'ДОКУМЕНТЫ';

        return $this->render('docs', [
            'titlePage' => $titlePage,
        ]);
    }

    public function tv() {   
        $titlePage = 'ТЕЛЕВИДЕНИЕ';

        return $this->render('tv', [
            'titlePage' => $titlePage,
        ]);
    }

    public function contacts() {   
        $titlePage = 'КОНТАКТЫ';

        return $this->render('contacts', [
            'titlePage' => $titlePage,
        ]);
    }

    public function register() {   
        $titlePage = 'РЕГИСТРАЦИЯ';

        return $this->render('register', [
            'titlePage' => $titlePage,
        ]);
    }

    public function siteinfo() {   
        $titlePage = 'О САЙТЕ';

        return $this->render('siteinfo', [
            'titlePage' => $titlePage,
        ]);
    }
}
