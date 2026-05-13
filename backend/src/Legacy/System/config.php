<?php

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', (string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com'));
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', (string)(getenv('SMTP_ENCRYPTION') ?: 'tls'));
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', (string)(getenv('SMTP_USER') ?: ''));
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', (string)(getenv('SMTP_PASS') ?: ''));
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', (string)(getenv('SMTP_FROM_NAME') ?: 'RMUTP Support Team'));
}
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', rtrim((string)(getenv('APP_BASE_URL') ?: ''), '/'));
}
if (!defined('TENANT_MODE')) {
    define('TENANT_MODE', strtolower((string)(getenv('TENANT_MODE') ?: 'single')));
}
if (!defined('CORE_DB_NAME')) {
    define('CORE_DB_NAME', (string)(getenv('CORE_DB_NAME') ?: 'rmutp_core'));
}
if (!defined('DEFAULT_TENANT_CODE')) {
    define('DEFAULT_TENANT_CODE', strtolower((string)(getenv('DEFAULT_TENANT_CODE') ?: '')));
}
