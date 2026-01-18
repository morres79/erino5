<?php
namespace App\Controllers;

use App\View\View;
use App\Controllers\BaseController;

class NotFoundController extends BaseController {
    public function index()
    {
        $titlePage = 'Страницы не найдена';

        return $this->render('404', [
            'titlePage' => $titlePage
        ]);
    }
}