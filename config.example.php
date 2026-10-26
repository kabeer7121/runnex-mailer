<?php
// Copy to config.local.php and fill in. Generate secrets with:
//   php -r "echo bin2hex(random_bytes(32));"
return [
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'your_database',
        'user' => 'your_db_user',
        'pass' => 'change-me',
    ],
    'app_url' => 'https://mail.example.com',
    'timezone' => 'America/Chicago',
    'secret' => 'change-me-64-hex-chars',
    'cron_key' => 'change-me-32-hex-chars',
    'debug' => false,
];
