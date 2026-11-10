<?php
// Settings come from private environment variables on the host, or from a private file
// called config.local.php (never stored in the repo) placed next to this one.
$local = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];
$get = static fn(string $env, string $key, string $default = '') =>
    getenv($env) !== false && getenv($env) !== '' ? (string) getenv($env) : (string) ($local[$key] ?? $default);

return [
    'db_dsn'  => $get('PMC_DB_DSN', 'db_dsn', 'mysql:host=localhost;dbname=pmc;charset=utf8mb4'),
    'db_user' => $get('PMC_DB_USER', 'db_user'),
    'db_pass' => $get('PMC_DB_PASS', 'db_pass'),
    'app_url' => $get('PMC_APP_URL', 'app_url', 'http://localhost:8080'),
];
