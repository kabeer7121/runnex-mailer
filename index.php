<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
start_session();
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// clean URLs arrive as ?p=campaign-view (see .htaccess) – internal page names use underscores
$page = preg_replace('/[^a-z_]/', '', str_replace('-', '_', strtolower((string)($_GET['p'] ?? 'dashboard')))) ?: 'dashboard';

// old-style links (index.php?p=...) → permanent redirect to the clean URL, so bookmarks keep working
if (pretty_urls() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $page !== 'api'
    && preg_match('#/index\.php(\?|$)#', (string)($_SERVER['REQUEST_URI'] ?? ''))) {
    $params = $_GET;
    unset($params['p']);
    header('Location: ' . url($page, $params), true, 301);
    exit;
}

if ($page !== 'login') {
    Auth::requireLogin();
}
$file = RX_APP . '/pages/' . $page . '.php';
if (!is_file($file)) {
    http_response_code(404);
    $file = RX_APP . '/pages/notfound.php';
}
require RX_APP . '/views/layout.php';
require $file;
