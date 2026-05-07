<?php

declare(strict_types=1);

require_once __DIR__ . '/Config/app.php';
require_once __DIR__ . '/Config/database.php';
require_once __DIR__ . '/Core/Database.php';
require_once __DIR__ . '/Core/View.php';
require_once __DIR__ . '/Core/Auth.php';

// Route loading point (for future router integration)
$routes = require __DIR__ . '/Routes/web.php';
