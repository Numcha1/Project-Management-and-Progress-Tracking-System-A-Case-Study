<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_NAME') ?: 'rmutp',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => (getenv('DB_PASS') === false ? '' : getenv('DB_PASS')),
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
];
