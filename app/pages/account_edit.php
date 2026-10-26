<?php
declare(strict_types=1);

$db = db();
$presets = provider_presets();
$id = (int)get('id');
$acc = $id ? $db->row('SELECT * FROM email_accounts WHERE id = ?', [$id]) : null;
if ($id && (!$acc || !Auth::canRow($acc))) {
    flash('error', 'Account not found.');
    redirect('accounts');
}
$bulk = get('bulk') === '1';

$owners = function (int $companyId) use ($db): array {
    return $db->all("SELECT u.id, u.name FROM users u WHERE u.status = 'active' AND (u.role = 'admin' OR u.id IN (SELECT user_id FROM company_users WHERE company_id = ?)) ORDER BY u.name", [$companyId]);
};

function rx_account_defaults(): array
{
    return [
        'daily_limit' => (int)setting('default_daily_limit', 30),
        'warmup_start' => (int)setting('default_warmup_start', 3),
        'warmup_increase' => (int)setting('default_warmup_increase', 2),
        'warmup_max' => (int)setting('default_warmup_max', 30),
        'warmup_reply_rate' => (int)setting('default_warmup_reply_rate', 35),
    ];
}

function rx_conn(string $provider, array $in): array
{
    $presets = provider_presets();
    $p = $presets[$provider] ?? $presets['gmail'];
    return [
        'provider' => isset($presets[$provider]) ? $provider : 'gmail',
        'smtp_host' => ($in['smtp_host'] ?? '') ?: $p['smtp_host'], 'smtp_port' => (int)(($in['smtp_port'] ?? '') ?: $p['smtp_port']),
        'smtp_secure' => in_array($in['smtp_secure'] ?? '', ['tls', 'ssl'], true) ? $in['smtp_secure'] : $p['smtp_secure'],
        'imap_host' => ($in['imap_host'] ?? '') ?: $p['imap_host'], 'imap_port' => (int)(($in['imap_port'] ?? '') ?: $p['imap_port']),
    ];
}

$bulkResults = null;

if (is_post()) {
    csrf_check();
    $companyId = (int)post('company_id');
    if (!Auth::canCompany($companyId)) {
        flash('error', 'Choose a company.');
        back('accounts');
    }
    $ownerId = Auth::isAdmin() ? ((int)post('user_id') ?: Auth::id()) : Auth::id();
    $skipTest = post('skip_test') === '1';

    // bulk
    if (post('mode') === 'bulk') {
        @set_time_limit(0);
        $text = (string)($_POST['lines'] ?? '');
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $text .= "\n" . file_get_contents($_FILES['file']['tmp_name']);
        }
        $bulkResults = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
            if ($line === '' || stripos($line, 'email') === 0) continue; // skip blanks + header row
            $parts = array_map('trim', str_getcsv($line, str_contains($line, "\t") ? "\t" : (str_contains($line, ';') && !str_contains($line, ',') ? ';' : ','), '"', '\\'));
            $email = strtolower($parts[0] ?? '');
            $pass = str_replace(' ', '', $parts[1] ?? '');
            $name = $parts[2] ?? '';
            if (!valid_email($email) || $pass === '') {
                $bulkResults[] = [$email ?: $line, false, 'Needs: email, app password, name'];
                continue;
            }
            if ($db->val('SELECT COUNT(*) FROM email_accounts WHERE email = ?', [$email])) {
                $bulkResults[] = [$email, false, 'Already connected'];
                continue;
            }
            $provider = post('provider') === 'auto' ? detect_provider($email) : post('provider');
            $row = rx_conn($provider, []) + rx_account_defaults() + [
                'company_id' => $companyId, 'user_id' => $ownerId, 'email' => $email,
                'from_name' => $name ?: ucwords(str_replace(['.', '_', '-'], ' ', preg_replace('/\d+/', '', strstr($email, '@', true)))),
                'username' => $email, 'password_enc' => Crypto::encrypt($pass),
                'status' => 'active', 'warmup_enabled' => 1, 'warmup_started_at' => now(), 'auto_limit' => 1, 'created_at' => now(),
            ];
            if (!$skipTest) {
                $t = Mailbox::testConnection($row);
                if (!$t['ok']) {
                    $bulkResults[] = [$email, false, ($t['smtp'] !== 'OK' ? 'SMTP: ' . $t['smtp'] : '') . ($t['imap'] !== 'OK' ? ' IMAP: ' . $t['imap'] : '')];
                    continue;
                }
            }
            $newId = $db->insert('email_accounts', $row);
            Health::compute($db->row('SELECT * FROM email_accounts WHERE id = ?', [$newId]));
            $bulkResults[] = [$email, true, 'Connected · ' . $presets[$row['provider']]['label'] . ' · warmup started'];
        }
        $okCount = count(array_filter($bulkResults, fn($r) => $r[1]));
        log_activity('account.bulk_add', "$okCount added");
        if (!$bulkResults) {
            flash('warn', 'Nothing to add – paste at least one line.');
            redirect('account_edit', ['bulk' => 1]);
        }
        // fall through to render the results table
    } else {
        // single
        $email = strtolower(post('email'));
        if (!valid_email($email)) {
            flash('error', 'Enter a valid email address.');
            back('accounts');
        }
        if ($db->val('SELECT id FROM email_accounts WHERE email = ? AND id <> ?', [$email, $id])) {
            flash('error', 'This email is already connected.');
            back('accounts');
        }
        $provider = post('provider', 'auto') === 'auto' ? detect_provider($email) : post('provider');
        $connIn = post('provider') === 'auto' ? [] : $_POST;
        $data = rx_conn($provider, $connIn) + [
            'company_id' => $companyId, 'user_id' => $ownerId, 'email' => $email,
            'from_name' => post('from_name') ?: ucwords(str_replace(['.', '_', '-'], ' ', strstr($email, '@', true))),
            'username' => post('username') ?: $email,
            'updated_at' => now(),
        ];
        // optional sections (present on the form only when expanded / editing)
        if (isset($_POST['daily_limit'])) {
            $data += [
                'signature_html' => (string)($_POST['signature_html'] ?? ''),
                'daily_limit' => max(1, min(2000, (int)post('daily_limit', 30))),
                'auto_limit' => post('auto_limit') === '1' ? 1 : 0,
                'warmup_enabled' => post('warmup_enabled') === '1' ? 1 : 0,
                'warmup_start' => max(1, (int)post('warmup_start', 3)),
                'warmup_increase' => max(1, (int)post('warmup_increase', 2)),
                'warmup_max' => max(2, min(100, (int)post('warmup_max', 30))),
                'warmup_reply_rate' => max(0, min(100, (int)post('warmup_reply_rate', 35))),
            ];
        }
        $pass = str_replace(' ', '', (string)($_POST['password'] ?? ''));
        if ($pass !== '') $data['password_enc'] = Crypto::encrypt($pass);
        elseif (!$acc) {
            flash('error', 'Enter the App Password.');
            back('accounts');
        }
        if (!$skipTest && ($pass !== '' || !$acc || $acc['status'] === 'error')) {
            $t = Mailbox::testConnection($data + ($acc ?: []) + ['password_enc' => $data['password_enc'] ?? ($acc['password_enc'] ?? '')]);
            if (!$t['ok']) {
                $_SESSION['acc_form'] = $_POST;
                flash('error', 'Connection failed – SMTP: ' . $t['smtp'] . ' | IMAP: ' . $t['imap']);
                back('accounts');
            }
            $data['status'] = ($acc && $acc['status'] === 'paused') ? 'paused' : 'active';
            $data['last_error'] = null;
        }
        if ($acc) {
            if (($data['warmup_enabled'] ?? 0) && !$acc['warmup_started_at']) $data['warmup_started_at'] = now();
            $db->update('email_accounts', $data, 'id = ?', [$id]);
            $newId = $id;
            log_activity('account.update', $email);
        } else {
            $data += rx_account_defaults() + ['status' => 'active', 'warmup_enabled' => 1, 'auto_limit' => 1, 'warmup_started_at' => now(), 'created_at' => now()];
            $newId = $db->insert('email_accounts', $data);
            log_activity('account.add', $email);
        }
        Health::compute($db->row('SELECT * FROM email_accounts WHERE id = ?', [$newId]));
        if (!$acc && post('another') === '1') {
            flash('success', "$email connected – warmup started. Add the next one:");
            redirect('account_edit');
        }
        flash('success', $acc ? 'Account saved.' : "$email connected! Warmup starts automatically – campaigns can use it right away at a safe low volume.");
        redirect($acc ? 'account_view' : 'accounts', $acc ? ['id' => $newId] : []);
    }
}

$f = $_SESSION['acc_form'] ?? [];
unset($_SESSION['acc_form']);
$v = fn($k, $d = '') => $f[$k] ?? ($acc[$k] ?? $d);
$def = rx_account_defaults();
$companyId = (int)($acc['company_id'] ?? Auth::currentCompany() ?: (Auth::companyIds()[0] ?? 0));
$provider = $acc ? $acc['provider'] : ($f['provider'] ?? 'auto');

$ownerSelect = function (?int $selected) use ($owners, $companyId): string {
    if (!Auth::isAdmin()) return '';
    $h = '<label>Owner (agent)<select name="user_id">';
    foreach ($owners($companyId) as $o) $h .= '<option value="' . (int)$o['id'] . '"' . ((int)$o['id'] === ($selected ?? Auth::id()) ? ' selected' : '') . '>' . e($o['name']) . '</option>';
    return $h . '</select></label>';
};

layout_start($bulk || $bulkResults !== null ? 'Bulk connect accounts' : ($acc ? 'Edit ' . $acc['email'] : 'Connect email account'), 'accounts',
    $acc || $bulk || $bulkResults !== null ? '' : '<a class="btn" href="' . url('account_edit', ['bulk' => 1]) . '">Add many at once</a>');
?>
<?php if ($bulkResults !== null): $okCount = count(array_filter($bulkResults, fn($r) => $r[1])); ?>
<div class="card">
  <h2><?= $okCount ?> of <?= count($bulkResults) ?> accounts connected</h2>
  <table class="table">
    <tr><th>Account</th><th>Result</th></tr>
    <?php foreach ($bulkResults as [$em, $ok, $msg]): ?>
      <tr><td><?= e($em) ?></td><td><?= $ok ? '<span class="badge b-ok">✓</span> ' : '<span class="badge b-bad">✕</span> ' ?><span class="small"><?= e($msg) ?></span></td></tr>
    <?php endforeach; ?>
  </table>
  <div class="form-actions"><a class="btn btn-primary" href="<?= url('accounts') ?>">Go to accounts</a><a class="btn" href="<?= url('account_edit', ['bulk' => 1]) ?>">Add more</a></div>
</div>
<?php elseif ($bulk): ?>
<div class="grid-2-1">
  <div class="card">
    <form method="post" class="form" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="mode" value="bulk">
      <div class="grid2"><?= company_select('company_id', $companyId) ?><?= $ownerSelect(null) ?></div>
      <label>Provider<select name="provider"><option value="auto">Detect automatically (recommended)</option><?php foreach ($presets as $k => $p): if ($k === 'custom') continue; ?><option value="<?= $k ?>"><?= e($p['label']) ?></option><?php endforeach; ?></select></label>
      <label>Paste accounts – one per line: <code>email, app password, sender name</code>
        <textarea name="lines" rows="10" placeholder="john@example.com, abcd efgh ijkl mnop, John Carter&#10;sara@example.com, wxyz wxyz wxyz wxyz, Sara Malik"></textarea></label>
      <label>…or upload a CSV with the same 3 columns<input type="file" name="file" accept=".csv,.txt"></label>
      <label class="check"><input type="checkbox" name="skip_test" value="1"> Skip connection test (faster, not recommended)</label>
      <button class="btn btn-primary" onclick="this.textContent='Connecting… this takes a few seconds per account';">Connect all accounts</button>
    </form>
  </div>
  <div class="card help">
    <h2>Tips for bulk connecting</h2>
    <ul class="tips">
      <li>Create the App Password in each Google account first (2-Step Verification must be on).</li>
      <li>Spaces in app passwords are fine – they're removed automatically.</li>
      <li>The sender name is optional; it's guessed from the email if left out.</li>
      <li>Each account is tested, gets smart limits, joins the warmup network and is health-scored automatically.</li>
      <li>You get a result for every line – fix the failed ones and paste only those again.</li>
    </ul>
  </div>
</div>
<?php else: ?>
<div class="grid-2-1">
<div class="card">
  <form method="post" class="form" id="accform">
    <?= csrf_field() ?>
    <?php if (count(Auth::companies()) > 1 || Auth::isAdmin()): ?><div class="grid2"><?= company_select('company_id', $companyId) ?><?= $ownerSelect(isset($acc['user_id']) ? (int)$acc['user_id'] : null) ?></div><?php else: ?><?= company_select('company_id', $companyId) ?><?php endif; ?>

    <div class="grid2">
      <label>Email address<input type="email" name="email" value="<?= e($v('email')) ?>" required autocomplete="off" data-acc-email placeholder="john@example.com"></label>
      <label>Sender name <span class="muted small">(what carriers see)</span><input name="from_name" value="<?= e($v('from_name')) ?>" placeholder="John Carter" data-acc-name></label>
    </div>
    <label>App Password <?= $acc ? '<span class="muted small">(leave blank to keep current)</span>' : '<a class="small" href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">→ create one at Google</a>' ?>
      <input type="password" name="password" autocomplete="new-password" placeholder="16 letters, e.g. abcd efgh ijkl mnop" <?= $acc ? '' : 'required' ?>></label>
    <div class="test-row">
      <button type="button" class="btn" data-acc-test>Test connection</button>
      <span class="small" data-acc-result><?= $acc ? '' : 'Provider is detected automatically from the address.' ?></span>
    </div>

    <details class="adv" <?= $acc || ($f['provider'] ?? '') === 'custom' ? 'open' : '' ?>>
      <summary>Server settings <span class="muted small">(only needed for non-Gmail providers)</span></summary>
      <label>Provider<select name="provider" data-provider>
        <?php if (!$acc): ?><option value="auto" data-preset="{}" <?= $provider === 'auto' ? 'selected' : '' ?>>Detect automatically</option><?php endif; ?>
        <?php foreach ($presets as $k => $p): ?><option value="<?= $k ?>" <?= $provider === $k ? 'selected' : '' ?> data-preset="<?= e(json_encode($p)) ?>"><?= e($p['label']) ?></option><?php endforeach; ?></select></label>
      <div class="grid3">
        <label>SMTP host<input name="smtp_host" value="<?= e($v('smtp_host', 'smtp.gmail.com')) ?>"></label>
        <label>SMTP port<input name="smtp_port" type="number" value="<?= e($v('smtp_port', 587)) ?>"></label>
        <label>Security<select name="smtp_secure"><option value="tls" <?= $v('smtp_secure', 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option><option value="ssl" <?= $v('smtp_secure') === 'ssl' ? 'selected' : '' ?>>SSL (465)</option></select></label>
        <label>IMAP host<input name="imap_host" value="<?= e($v('imap_host', 'imap.gmail.com')) ?>"></label>
        <label>IMAP port<input name="imap_port" type="number" value="<?= e($v('imap_port', 993)) ?>"></label>
        <label>Login username<input name="username" value="<?= e($v('username')) ?>" placeholder="same as email"></label>
      </div>
    </details>

    <details class="adv" <?= $acc ? 'open' : '' ?>>
      <summary>Limits, warmup &amp; signature <span class="muted small">(smart defaults already applied)</span></summary>
      <div class="grid2">
        <label>Max campaign emails per day<input type="number" name="daily_limit" min="1" max="2000" value="<?= e($v('daily_limit', $def['daily_limit'])) ?>"></label>
        <label class="check"><input type="checkbox" name="auto_limit" value="1" <?= (int)$v('auto_limit', 1) ? 'checked' : '' ?>> <span><b>Smart limit (recommended)</b><br><span class="muted small">Never send more than the safe amount for this account's age and health.</span></span></label>
      </div>
      <label class="check"><input type="checkbox" name="warmup_enabled" value="1" <?= (int)$v('warmup_enabled', 1) ? 'checked' : '' ?>> <span><b>Warmup</b> <span class="muted small">– keep it on, even after warmup is complete</span></span></label>
      <div class="grid4">
        <label>Start/day<input type="number" name="warmup_start" min="1" value="<?= e($v('warmup_start', $def['warmup_start'])) ?>"></label>
        <label>Increase/day<input type="number" name="warmup_increase" min="1" value="<?= e($v('warmup_increase', $def['warmup_increase'])) ?>"></label>
        <label>Max/day<input type="number" name="warmup_max" min="2" max="100" value="<?= e($v('warmup_max', $def['warmup_max'])) ?>"></label>
        <label>Reply rate %<input type="number" name="warmup_reply_rate" min="0" max="100" value="<?= e($v('warmup_reply_rate', $def['warmup_reply_rate'])) ?>"></label>
      </div>
      <label>Signature <span class="muted small">– used where emails contain {{signature}}. Text only, no images.</span></label>
      <?= email_editor('signature_html', (string)$v('signature_html', '')) ?>
    </details>

    <label class="check"><input type="checkbox" name="skip_test" value="1"> Save without testing the connection</label>
    <div class="form-actions">
      <button class="btn btn-primary"><?= $acc ? 'Save' : 'Connect account' ?></button>
      <?php if (!$acc): ?><button class="btn" name="another" value="1">Connect &amp; add another</button><?php endif; ?>
      <?php if ($acc): ?><button class="btn btn-danger right" form="delform" onclick="return confirm('Delete this account? Its sent-email stats are kept.')">Delete</button><?php endif; ?>
    </div>
  </form>
  <?php if ($acc): ?><form id="delform" method="post" action="<?= url('accounts') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$acc['id'] ?>"><input type="hidden" name="action" value="delete"></form><?php endif; ?>
</div>

<div class="card help">
  <h2>Connect a Gmail account in 2 minutes</h2>
  <ol class="howto">
    <li><b>Turn on 2-Step Verification</b><br><a href="https://myaccount.google.com/signinoptions/twosv" target="_blank" rel="noopener">myaccount.google.com → Security → 2-Step Verification</a></li>
    <li><b>Create an App Password</b> named "Runnex"<br><a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a> → copy the 16 letters</li>
    <li><b>Check IMAP is on</b><br><a href="https://mail.google.com/mail/u/0/#settings/fwdandpop" target="_blank" rel="noopener">Gmail → Settings → Forwarding and POP/IMAP</a> → Enable IMAP</li>
    <li><b>Paste email + app password here</b>, click <i>Test connection</i>, then <i>Connect</i>.</li>
  </ol>
  <p class="muted small">Google Workspace: if App Passwords are missing, your Workspace admin must allow them (Admin console → Security → Authentication). Using your own domain? Check SPF/DKIM/DMARC in <a href="<?= url('deliverability') ?>">Anti-Spam Tools</a>.</p>
  <h3>What happens after connecting</h3>
  <ul class="tips small">
    <li>Warmup starts automatically (3/day, +2 each day, up to 30).</li>
    <li>Smart limit keeps campaign volume safe: 5/day in week 1, rising to 40–50/day after a month.</li>
    <li>Replies and bounces are picked up every 10 minutes.</li>
    <li>If the account starts landing in spam it is paused for campaigns and repaired by warmup – then resumed automatically.</li>
  </ul>
</div>
</div>
<?php endif; ?>
<?php layout_end();
