<?php

declare(strict_types=1);

return [
    'GET /login' => ['AuthController', 'showLogin'],
    'POST /login' => ['AuthController', 'login'],
    'GET /register' => ['AuthController', 'showRegister'],
    'POST /register' => ['AuthController', 'register'],
    'GET /dashboard' => ['HomeController', 'dashboard'],
    'GET /logout' => ['AuthController', 'logout'],
];
