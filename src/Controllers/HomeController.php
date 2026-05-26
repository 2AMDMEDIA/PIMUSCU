<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Session;

final class HomeController extends BaseController
{
    public function index(): void
    {
        if (Session::isLoggedIn()) {
            $this->redirect('/dashboard');
            return;
        }
        $this->redirect('/login');
    }
}
