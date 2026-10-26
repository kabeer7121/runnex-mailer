<?php
declare(strict_types=1);

/*
 * Runnex background worker. Run every minute.
 *   Hostinger hPanel → Advanced → Cron Jobs → "Every minute":
 *     /usr/bin/php /home/USER/public_html/cron.php
 *   or as a URL (external cron service):
 *     https://yourdomain.com/cron.php?key=YOUR_CRON_KEY
 */
require __DIR__ . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals((string)cfg('cron_key'), $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    ignore_user_abort(true);
    header('Content-Type: text/plain');
}

$lock = fopen(RX_ROOT . '/storage/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Another worker is running.\n");
}

$started = time();
@set_time_limit(70);
$result = Engine::tick($started + 55);
flock($lock, LOCK_UN);
fclose($lock);

echo gmdate('Y-m-d H:i:s') . ' ' . json_encode($result) . ' in ' . (time() - $started) . "s\n";
