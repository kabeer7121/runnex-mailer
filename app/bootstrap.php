<?php
declare(strict_types=1);

define('RX_ROOT', dirname(__DIR__));
define('RX_APP', __DIR__);
define('RX_VERSION', '1.0.0');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

$cfgFile = RX_ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit("config.php is missing. See README.md for setup.\n");
}
$GLOBALS['RX_CONFIG'] = require $cfgFile;
if (!empty($GLOBALS['RX_CONFIG']['debug'])) ini_set('display_errors', '1');

foreach (['helpers', 'DB', 'Auth', 'SmtpClient', 'ImapClient', 'Template', 'Mailer', 'SpamChecker', 'Dns', 'Importer', 'Health', 'Warmup', 'Mailbox', 'Engine', 'Stats', 'Sequences', 'Migrations', 'Verifier'] as $f) {
    require_once RX_APP . '/lib/' . $f . '.php';
}
try {
    Migrations::run();
} catch (Throwable $e) {
    app_log('Migration: ' . $e->getMessage());
}

set_exception_handler(function (Throwable $e) {
    app_log('Uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo '<div style="font-family:sans-serif;padding:40px"><h2>Something went wrong</h2><p>' . (cfg('debug') ? e($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) : 'The error was logged in storage/logs.') . '</p><p><a href="' . (function_exists('url') ? url('dashboard') : 'index.php') . '">Back to dashboard</a></p></div>';
});

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('runnex_sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
