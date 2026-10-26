<?php
declare(strict_types=1);

/* Public tracking endpoint: opens (?o=), clicks (?c=&l=&s=), unsubscribe (?u=). */
require __DIR__ . '/app/bootstrap.php';

$db = db();
$ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isBot = (bool)preg_match('/(bot|crawler|spider|curl|wget|python|java\/|go-http|barracuda|mimecast|proofpoint|symantec|trendmicro|forcepoint|cisco|ironport|fortinet|sophos|safelinks|urldefense|headless|preview)/i', $ua);

function rx_event(array $m, string $type, ?string $url = null): void
{
    global $db, $ip, $ua;
    $lp = local_parts();
    $db->insert('events', [
        'message_id' => $m['id'], 'campaign_id' => $m['campaign_id'], 'account_id' => $m['account_id'], 'company_id' => $m['company_id'],
        'user_id' => $m['user_id'], 'type' => $type, 'url' => $url, 'ip' => $ip, 'user_agent' => $ua,
        'created_at' => now(), 'day' => $lp['day'], 'hour' => $lp['hour'], 'dow' => $lp['dow'],
    ]);
}

function rx_msg(string $token): ?array
{
    global $db;
    if (!preg_match('/^[a-f0-9]{16,40}$/', $token)) return null;
    return $db->row('SELECT * FROM messages WHERE token = ?', [$token]);
}

// open pixel
if (isset($_GET['o'])) {
    $m = rx_msg((string)$_GET['o']);
    // ignore hits in the first few seconds after sending (security scanners / prefetch)
    if ($m && !$isBot && strtotime($m['sent_at'] . ' UTC') < time() - 4) {
        $db->exec('UPDATE messages SET open_count = open_count + 1, opened_at = COALESCE(opened_at, ?) WHERE id = ?', [now(), $m['id']]);
        rx_event($m, 'open');
    }
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

// click redirect
if (isset($_GET['c'])) {
    $token = (string)$_GET['c'];
    $enc = (string)($_GET['l'] ?? '');
    $url = (string)base64_decode(strtr($enc, '-_', '+/') . str_repeat('=', (4 - strlen($enc) % 4) % 4), true);
    if (!preg_match('#^https?://#i', $url) || !hash_equals(Crypto::sign($token . '|' . $url), (string)($_GET['s'] ?? ''))) {
        http_response_code(400);
        exit('Invalid link');
    }
    $m = rx_msg($token);
    if ($m && !$isBot) {
        $db->exec('UPDATE messages SET click_count = click_count + 1, clicked_at = COALESCE(clicked_at, ?), opened_at = COALESCE(opened_at, ?) WHERE id = ?', [now(), now(), $m['id']]);
        rx_event($m, 'click', mb_substr($url, 0, 2000));
    }
    header('Location: ' . $url, true, 302);
    exit;
}

// unsubscribe
if (isset($_GET['u'])) {
    $m = rx_msg((string)$_GET['u']);
    $done = false;
    $unsub = function () use ($m, $db) {
        if (!$m) return;
        suppress($m['to_email'], (int)$m['company_id'], 'unsubscribe');
        if ($m['contact_id']) {
            $db->exec("UPDATE contacts SET status = 'unsubscribed' WHERE id = ?", [$m['contact_id']]);
            $db->exec("UPDATE campaign_leads SET status = 'unsubscribed', next_send_at = NULL WHERE contact_id = ? AND status IN ('pending','active')", [$m['contact_id']]);
        }
        if (!$m['unsubscribed_at']) {
            $db->exec('UPDATE messages SET unsubscribed_at = ? WHERE id = ?', [now(), $m['id']]);
            rx_event($m, 'unsubscribe');
        }
    };
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $unsub(); // RFC 8058 one-click (List-Unsubscribe-Post) and the confirm button
        $done = true;
    }
    $company = $m ? $db->row('SELECT name FROM companies WHERE id = ?', [$m['company_id']]) : null;
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribe</title>
<style>body{font-family:system-ui,Arial,sans-serif;background:#f4f4f5;color:#222;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px}.box{background:#fff;padding:32px;border-radius:12px;max-width:420px;width:100%;box-shadow:0 4px 20px rgba(0,0,0,.06);text-align:center}button{background:#d71920;color:#fff;border:0;padding:12px 22px;border-radius:8px;font-size:15px;cursor:pointer}</style></head>
<body><div class="box">
<?php if (!$m): ?><h2>Link not valid</h2><p>This unsubscribe link has expired or is invalid.</p>
<?php elseif ($done || $m['unsubscribed_at']): ?><h2>You're unsubscribed</h2><p><?= e($m['to_email']) ?> will no longer receive emails from <?= e($company['name'] ?? 'us') ?>.</p>
<?php else: ?><h2>Unsubscribe?</h2><p>Stop receiving emails at <b><?= e($m['to_email']) ?></b> from <?= e($company['name'] ?? 'us') ?>.</p>
<form method="post"><button type="submit">Unsubscribe</button></form>
<?php endif; ?>
</div></body></html><?php
    exit;
}

http_response_code(404);
