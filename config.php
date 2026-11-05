<?php
// Settings come from private environment variables set on the host, never from the code.
return [
    'db_dsn'  => getenv('PMC_DB_DSN')  ?: 'mysql:host=localhost;dbname=pmc;charset=utf8mb4',
    'db_user' => getenv('PMC_DB_USER') ?: '',
    'db_pass' => getenv('PMC_DB_PASS') ?: '',
    'app_url' => getenv('PMC_APP_URL') ?: 'http://localhost:8080',
];
