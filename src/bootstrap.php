<?php
declare(strict_types=1);

$GLOBALS['cfg'] = require __DIR__ . '/../config.php';
date_default_timezone_set('Europe/London');
ini_set('display_errors', '0');

set_exception_handler(function (Throwable $e): void {
    error_log((string) $e);
    if (!headers_sent()) { http_response_code(500); }
    echo 'Something went wrong. Please try again in a moment.';
});

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('pmc');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

require __DIR__ . '/db.php';
require __DIR__ . '/view.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/diary.php';
require __DIR__ . '/migrate.php';

auto_migrate();
