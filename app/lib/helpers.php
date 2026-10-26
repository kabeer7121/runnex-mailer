<?php
declare(strict_types=1);

function cfg(string $key, $default = null)
{
    return $GLOBALS['RX_CONFIG'][$key] ?? $default;
}

function db(): DB
{
    static $db = null;
    if ($db === null) {
        $db = new DB(cfg('db'));
    }
    return $db;
}

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Current UTC time as a DB datetime string. */
function now(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null || $key === '__reset') {
        $cache = [];
        try {
            foreach (db()->all('SELECT skey, svalue FROM settings') as $r) {
                $cache[$r['skey']] = $r['svalue'];
            }
        } catch (Throwable $e) {
        }
        if ($key === '__reset') return null;
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null && $cache[$key] !== '' ? $cache[$key] : $default;
}

function set_setting(string $key, $value): void
{
    $db = db();
    if ($db->val('SELECT COUNT(*) FROM settings WHERE skey = ?', [$key])) {
        $db->exec('UPDATE settings SET svalue = ? WHERE skey = ?', [(string)$value, $key]);
    } else {
        $db->insert('settings', ['skey' => $key, 'svalue' => (string)$value]);
    }
    setting('__reset');
}

function app_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        try {
            $tz = new DateTimeZone((string)setting('timezone', cfg('timezone', 'America/Chicago')));
        } catch (Throwable $e) {
            $tz = new DateTimeZone('UTC');
        }
    }
    return $tz;
}

/** Convert a UTC DB datetime to a formatted local string. */
function local_dt(?string $utc, string $fmt = 'M j, Y g:i A'): string
{
    if (!$utc) return '—';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(app_tz());
    return $d->format($fmt);
}

/** Local datetime-local input value -> UTC DB string. */
function local_to_utc(string $local, ?DateTimeZone $tz = null): string
{
    $d = new DateTime($local, $tz ?? app_tz());
    $d->setTimezone(new DateTimeZone('UTC'));
    return $d->format('Y-m-d H:i:s');
}

function local_today(): string
{
    return (new DateTime('now', app_tz()))->format('Y-m-d');
}

function local_day_offset(int $days): string
{
    return (new DateTime('now', app_tz()))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function local_parts(?int $ts = null): array
{
    $d = new DateTime('@' . ($ts ?? time()));
    $d->setTimezone(app_tz());
    return ['day' => $d->format('Y-m-d'), 'hour' => (int)$d->format('G'), 'dow' => (int)$d->format('N')];
}

function time_ago(?string $utc): string
{
    if (!$utc) return 'never';
    $diff = time() - strtotime($utc . ' UTC');
    if ($diff < 0) {
        $diff = -$diff;
        $suffix = 'from now';
    } else {
        $suffix = 'ago';
    }
    if ($diff < 60) return $diff . 's ' . $suffix;
    if ($diff < 3600) return floor($diff / 60) . 'm ' . $suffix;
    if ($diff < 86400) return floor($diff / 3600) . 'h ' . $suffix;
    return floor($diff / 86400) . 'd ' . $suffix;
}

function base_url(): string
{
    return rtrim((string)setting('app_url', cfg('app_url', '')), '/');
}

/** Clean URLs (/campaign-view?id=5) – needs the rewrite rules in .htaccess. Set 'pretty_urls' => false in config.php to switch off. */
function pretty_urls(): bool
{
    return cfg('pretty_urls', true) !== false;
}

/** URL path of the app folder ('' when installed in the web root, '/mail' in a subfolder). */
function base_path(): string
{
    static $p = null;
    if ($p === null) {
        $p = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/.');
    }
    return $p;
}

function url(string $page, array $params = []): string
{
    if (!pretty_urls()) {
        return 'index.php?' . http_build_query(array_merge(['p' => $page], $params));
    }
    $path = base_path() . '/' . ($page === 'dashboard' ? '' : str_replace('_', '-', $page));
    $qs = http_build_query($params);
    return $path . ($qs !== '' ? '?' . $qs : '');
}

/** Current query string without the internal "p" routing parameter (for pagers / export links). */
function query_without_p(array $extra = []): string
{
    $q = array_merge($_GET, $extra);
    if (pretty_urls()) unset($q['p']);
    return http_build_query($q);
}

/** Public tracking links used inside emails. */
function tracking_url(string $type, string $token): string
{
    return pretty_urls() ? base_url() . '/' . $type . '/' . $token : base_url() . '/t.php?' . $type . '=' . $token;
}

function redirect(string $page, array $params = []): void
{
    header('Location: ' . url($page, $params));
    exit;
}

function back(string $fallbackPage = 'dashboard'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) {
        header('Location: ' . $ref);
        exit;
    }
    redirect($fallbackPage);
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('Session expired. Please go back, refresh the page and try again.');
    }
}

function post(string $k, $default = '')
{
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function get(string $k, $default = '')
{
    $v = $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function pct($part, $whole, int $dec = 1): float
{
    return $whole > 0 ? round($part / $whole * 100, $dec) : 0.0;
}

function num($n): string
{
    return number_format((float)$n);
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function random_token(int $len = 24): string
{
    return substr(bin2hex(random_bytes((int)ceil($len / 2))), 0, $len);
}

function log_activity(string $action, string $details = ''): void
{
    try {
        db()->insert('activity_log', [
            'user_id' => $_SESSION['uid'] ?? null,
            'action' => $action,
            'details' => mb_substr($details, 0, 1000),
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
    }
}

function app_log(string $msg): void
{
    $file = RX_ROOT . '/storage/logs/app-' . gmdate('Y-m-d') . '.log';
    @file_put_contents($file, '[' . gmdate('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function email_domain(string $email): string
{
    return strtolower(substr(strrchr($email, '@') ?: '', 1));
}

function is_free_gmail(string $email): bool
{
    return in_array(email_domain($email), ['gmail.com', 'googlemail.com'], true);
}

function provider_presets(): array
{
    return [
        'gmail' => ['label' => 'Gmail / Google Workspace', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'imap_host' => 'imap.gmail.com', 'imap_port' => 993],
        'outlook' => ['label' => 'Outlook / Microsoft 365', 'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'imap_host' => 'outlook.office365.com', 'imap_port' => 993],
        'hostinger' => ['label' => 'Hostinger Email (your domain)', 'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 465, 'smtp_secure' => 'ssl', 'imap_host' => 'imap.hostinger.com', 'imap_port' => 993],
        'titan' => ['label' => 'Titan Email (Hostinger)', 'smtp_host' => 'smtp.titan.email', 'smtp_port' => 465, 'smtp_secure' => 'ssl', 'imap_host' => 'imap.titan.email', 'imap_port' => 993],
        'zoho' => ['label' => 'Zoho Mail', 'smtp_host' => 'smtp.zoho.com', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'imap_host' => 'imap.zoho.com', 'imap_port' => 993],
        'custom' => ['label' => 'Other (custom SMTP/IMAP)', 'smtp_host' => '', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'imap_host' => '', 'imap_port' => 993],
    ];
}

/** Guess the mail provider from the address (and the domain's MX records for custom domains). */
function detect_provider(string $email): string
{
    $d = email_domain($email);
    if (in_array($d, ['gmail.com', 'googlemail.com'], true)) return 'gmail';
    if (in_array($d, ['outlook.com', 'hotmail.com', 'live.com', 'msn.com'], true)) return 'outlook';
    if (in_array($d, ['zoho.com', 'zohomail.com'], true)) return 'zoho';
    $mx = strtolower(implode(' ', array_map(fn($r) => $r['target'] ?? '', @dns_get_record($d, DNS_MX) ?: [])));
    if (str_contains($mx, 'google') || str_contains($mx, 'gmail')) return 'gmail';
    if (str_contains($mx, 'outlook.com') || str_contains($mx, 'microsoft')) return 'outlook';
    if (str_contains($mx, 'zoho')) return 'zoho';
    if (str_contains($mx, 'titan.email')) return 'titan';
    if (str_contains($mx, 'hostinger')) return 'hostinger';
    return 'gmail';
}

function valid_email(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 191;
}

/** Simple pagination helper: returns [offset, limit, page]. */
function paging(int $perPage = 50): array
{
    $page = max(1, (int)get('page', 1));
    return [($page - 1) * $perPage, $perPage, $page];
}

function pager_html(int $total, int $perPage, int $page): string
{
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) return '';
    $q = $_GET;
    if (pretty_urls()) unset($q['p']);
    $out = '<div class="pager"><span class="muted">' . num($total) . ' total</span>';
    $start = max(1, $page - 3);
    $end = min($pages, $page + 3);
    if ($page > 1) {
        $q['page'] = $page - 1;
        $out .= '<a href="?' . e(http_build_query($q)) . '">&lsaquo; Prev</a>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $q['page'] = $i;
        $out .= $i === $page ? '<span class="cur">' . $i . '</span>' : '<a href="?' . e(http_build_query($q)) . '">' . $i . '</a>';
    }
    if ($page < $pages) {
        $q['page'] = $page + 1;
        $out .= '<a href="?' . e(http_build_query($q)) . '">Next &rsaquo;</a>';
    }
    return $out . '</div>';
}

/** Increment a per-account per-day counter row. */
function bump_account_daily(int $accountId, string $field, int $n = 1, ?string $day = null): void
{
    $allowed = ['sent', 'warmup_sent', 'warmup_inbox', 'warmup_spam', 'bounces', 'replies'];
    if (!in_array($field, $allowed, true)) return;
    $day = $day ?? local_today();
    $db = db();
    $db->insertIgnore('account_daily', ['account_id' => $accountId, 'day' => $day]);
    $db->exec("UPDATE account_daily SET $field = $field + ? WHERE account_id = ? AND day = ?", [$n, $accountId, $day]);
}

function is_suppressed(string $email, int $companyId): bool
{
    return (bool)db()->val('SELECT COUNT(*) FROM suppression WHERE email = ? AND (company_id = 0 OR company_id = ?)', [strtolower($email), $companyId]);
}

function suppress(string $email, int $companyId, string $reason): void
{
    db()->insertIgnore('suppression', ['company_id' => $companyId, 'email' => strtolower($email), 'reason' => $reason, 'created_at' => now()]);
}

function health_badge(string $status, ?int $score = null): string
{
    $map = [
        'excellent' => ['ok', 'Excellent'],
        'good' => ['good', 'Good'],
        'at_risk' => ['warn', 'At risk'],
        'critical' => ['bad', 'Going to spam'],
        'new' => ['muted', 'Warming up'],
        'error' => ['bad', 'Connection error'],
    ];
    [$cls, $label] = $map[$status] ?? ['muted', ucfirst($status)];
    return '<span class="badge b-' . $cls . '">' . e($label) . ($score !== null && $status !== 'error' ? ' · ' . $score : '') . '</span>';
}

function status_badge(string $status): string
{
    $map = [
        'active' => 'ok', 'running' => 'ok', 'completed' => 'good', 'sent' => 'good', 'replied' => 'ok',
        'draft' => 'muted', 'pending' => 'muted', 'scheduled' => 'info', 'paused' => 'warn', 'in_progress' => 'info',
        'error' => 'bad', 'failed' => 'bad', 'bounced' => 'bad', 'unsubscribed' => 'warn', 'disabled' => 'muted',
        'stopped' => 'muted', 'skipped' => 'muted', 'inbox' => 'ok', 'spam' => 'bad',
    ];
    return '<span class="badge b-' . ($map[$status] ?? 'muted') . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function lead_labels(): array
{
    return [
        'interested' => 'Interested',
        'signed_up' => 'Signed up',
        'call_back' => 'Call back',
        'not_interested' => 'Not interested',
        'wrong_contact' => 'Wrong contact',
    ];
}

function contact_fields(): array
{
    return [
        'email' => 'Email',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'company_name' => 'Carrier / Company name',
        'mc_number' => 'MC number',
        'dot_number' => 'DOT number',
        'phone' => 'Phone',
        'city' => 'City',
        'state' => 'State',
        'equipment_type' => 'Equipment / Truck type',
        'truck_count' => 'Number of trucks',
        'notes' => 'Notes',
    ];
}

function timezones_common(): array
{
    return ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu', 'America/Toronto', 'Europe/London', 'Asia/Karachi', 'Asia/Kolkata', 'Asia/Dubai', 'UTC'];
}

function icon(string $name): string
{
    $p = [
        'home' => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'send' => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/>',
        'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6"/><path d="M16 4a4 4 0 0 1 0 8M22 21c0-3-2-5-4-5.5"/>',
        'contact' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
        'upload' => '<path d="M12 16V4M6 10l6-6 6 6"/><path d="M4 20h16"/>',
        'file' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 6-7"/>',
        'inbox' => '<path d="M3 13h5l2 3h4l2-3h5"/><path d="M5 5h14l2 8v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6z"/>',
        'flame' => '<path d="M12 22c4 0 7-3 7-7 0-5-5-7-5-12-3 2-4 5-4 7-1-1-2-2-2-4-2 2-3 5-3 9 0 4 3 7 7 7z"/>',
        'shield' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'ban' => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
        'check' => '<path d="m5 12 5 5L20 7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($p[$name] ?? '') . '</svg>';
}
