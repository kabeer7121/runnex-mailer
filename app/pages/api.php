<?php
declare(strict_types=1);

if (!is_post()) json_out(['error' => 'POST only'], 405);
csrf_check();
$a = get('a');

if ($a === 'spamcheck') {
    json_out(SpamChecker::analyze((string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? '')));
}

if ($a === 'preview') {
    $db = db();
    [$cs, $cp] = Auth::scope('company_id', null);
    $contact = $db->row("SELECT * FROM contacts WHERE status = 'active' $cs ORDER BY id DESC LIMIT 1", $cp) ?: [
        'first_name' => 'Mike', 'last_name' => 'Johnson', 'email' => 'mike@example.com', 'company_name' => 'Johnson Trucking LLC',
        'mc_number' => '123456', 'dot_number' => '3456789', 'city' => 'Dallas', 'state' => 'TX', 'equipment_type' => 'Dry Van', 'truck_count' => '5',
    ];
    [$as, $ap] = Auth::scope('company_id', 'user_id');
    $acc = $db->row("SELECT * FROM email_accounts WHERE 1=1 $as LIMIT 1", $ap) ?: ['from_name' => Auth::user()['name'], 'email' => Auth::user()['email'], 'signature_html' => ''];
    $company = $db->row('SELECT * FROM companies WHERE id = ?', [$contact['company_id'] ?? (Auth::scopeIds()[0] ?? 0)]) ?: [];
    $vars = Template::vars($contact, $acc, $company);
    json_out([
        'to' => $contact['email'],
        'subject' => Template::render((string)($_POST['subject'] ?? ''), $vars),
        'body' => Template::render((string)($_POST['body'] ?? ''), $vars),
    ]);
}

if ($a === 'test_account') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!valid_email($email)) json_out(['ok' => false, 'message' => 'Enter a valid email address first.']);
    $pass = str_replace(' ', '', (string)($_POST['password'] ?? ''));
    $existing = null;
    if ((int)($_POST['id'] ?? 0)) {
        $existing = db()->row('SELECT * FROM email_accounts WHERE id = ?', [(int)$_POST['id']]);
        if (!$existing || !Auth::canRow($existing)) json_out(['ok' => false, 'message' => 'Account not found.']);
    }
    if ($pass === '' && !$existing) json_out(['ok' => false, 'message' => 'Paste the App Password first.']);
    $provider = ($_POST['provider'] ?? 'auto') === 'auto' ? detect_provider($email) : (string)$_POST['provider'];
    $presets = provider_presets();
    $p = $presets[$provider] ?? $presets['gmail'];
    $useForm = ($_POST['provider'] ?? 'auto') !== 'auto';
    $acc = [
        'email' => $email,
        'username' => trim((string)($_POST['username'] ?? '')) ?: $email,
        'smtp_host' => $useForm && !empty($_POST['smtp_host']) ? (string)$_POST['smtp_host'] : $p['smtp_host'],
        'smtp_port' => $useForm && !empty($_POST['smtp_port']) ? (int)$_POST['smtp_port'] : $p['smtp_port'],
        'smtp_secure' => $useForm && in_array($_POST['smtp_secure'] ?? '', ['tls', 'ssl'], true) ? $_POST['smtp_secure'] : $p['smtp_secure'],
        'imap_host' => $useForm && !empty($_POST['imap_host']) ? (string)$_POST['imap_host'] : $p['imap_host'],
        'imap_port' => $useForm && !empty($_POST['imap_port']) ? (int)$_POST['imap_port'] : $p['imap_port'],
        'password_enc' => $pass !== '' ? Crypto::encrypt($pass) : $existing['password_enc'],
    ];
    if (!$acc['smtp_host'] || !$acc['imap_host']) json_out(['ok' => false, 'message' => 'Enter the SMTP and IMAP server under "Server settings".']);
    $r = Mailbox::testConnection($acc);
    json_out([
        'ok' => $r['ok'],
        'provider' => $p['label'],
        'message' => $r['ok'] ? 'Connected to ' . $p['label'] . ' – sending (SMTP) and inbox (IMAP) both work.' : trim(($r['smtp'] !== 'OK' ? 'Sending (SMTP): ' . $r['smtp'] . ' ' : '') . ($r['imap'] !== 'OK' ? 'Inbox (IMAP): ' . $r['imap'] : '')),
    ]);
}

json_out(['error' => 'Unknown action'], 400);
