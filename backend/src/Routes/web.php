<?php

declare(strict_types=1);

return [
    'GET /login' => ['AuthController', 'showLogin'],
    'POST /login' => ['AuthController', 'login'],
    'GET /register' => ['AuthController', 'showRegister'],
    'POST /register' => ['AuthController', 'register'],
    'GET /dashboard' => ['HomeController', 'dashboard'],
    'GET /proposal-center' => ['ProjectController', 'proposalCenter'],
    'GET /milestone-board' => ['ProjectController', 'milestoneBoard'],
    'GET /committee-assignment' => ['ProjectController', 'committeeAssignment'],
    'GET /tenant-admin' => ['AdminController', 'tenantAdmin'],
    'GET /admin-ops' => ['AdminController', 'opsCenter'],
    'GET /logout' => ['AuthController', 'logout'],
];
