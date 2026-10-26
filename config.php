<?php
// Real settings live in config.local.php (gitignored) or environment variables.
// Keep 'secret' stable: it encrypts saved mailbox passwords.
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    return require $local;
}
$env = static fn(string $k, $d = null) => ($v = getenv($k)) === false ? $d : $v;
return [
    'db' => [
        'driver' => 'mysql',
        'host' => $env('DB_HOST', 'localhost'),
        'port' => (int)$env('DB_PORT', 3306),
        'name' => $env('DB_NAME', ''),
        'user' => $env('DB_USER', ''),
        'pass' => $env('DB_PASS', ''),
    ],
    'app_url' => $env('APP_URL', 'http://localhost'),
    'timezone' => $env('APP_TIMEZONE', 'UTC'),
    'secret' => $env('APP_SECRET', ''),
    'cron_key' => $env('CRON_KEY', ''),
    'debug' => filter_var($env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
];
