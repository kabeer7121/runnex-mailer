<?php
declare(strict_types=1);

$db = db();
[$as, $ap] = Auth::scope('company_id', 'user_id');
$accounts = $db->all("SELECT * FROM email_accounts WHERE 1=1 $as ORDER BY email", $ap);

if (is_post() && post('action') === 'placement') {
    csrf_check();
    $acc = $db->row('SELECT * FROM email_accounts WHERE id = ?', [(int)post('account_id')]);
    if (!$acc || !Auth::canRow($acc)) {
        flash('error', 'Choose an account.');
        redirect('deliverability');
    }
    try {
        $body = (string)($_POST['body'] ?? '');
        $vars = Template::vars(['first_name' => 'Mike', 'company_name' => 'Sample Trucking LLC', 'mc_number' => '123456', 'state' => 'TX', 'equipment_type' => 'Dry Van'], $acc, $db->row('SELECT * FROM companies WHERE id = ?', [$acc['company_id']]) ?: []);
        $tid = Mailer::placementTest($acc, Template::render(post('subject') ?: 'Quick question', $vars), Template::render($body ?: 'Hi, just checking in.', $vars), null);
        flash('success', 'Test sent. Results update automatically in 2–5 minutes (requires the cron job).');
        redirect('deliverability', ['test' => $tid]);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('deliverability');
    }
}

$domain = strtolower(trim(get('domain')));
$dns = $domain !== '' && preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $domain) ? Dns::check($domain) : null;
$domains = array_values(array_unique(array_map(fn($a) => email_domain($a['email']), $accounts)));

[$ts, $tp] = Auth::scope('t.company_id', 't.user_id');
$tests = $db->all("SELECT t.*, a.email FROM placement_tests t LEFT JOIN email_accounts a ON a.id = t.account_id WHERE 1=1 $ts ORDER BY t.created_at DESC LIMIT 15", $tp);
$focus = (int)get('test');

layout_start('Anti-Spam Tools', 'deliverability');
?>
<div class="grid-2">
  <div class="card" data-spamcheck>
    <h2>Spam word &amp; content checker</h2>
    <p class="muted small">Paste your email. You get a score and exact fixes. (The campaign editor runs this automatically.)</p>
    <label>Subject<input data-sc-subject placeholder="Subject line"></label>
    <label>Body<textarea data-sc-body rows="9" placeholder="Paste your email body here…"></textarea></label>
    <div data-sc-result class="sc-result"></div>
  </div>
  <div class="card">
    <h2>Inbox placement test</h2>
    <p class="muted small">Sends your email to your other connected inboxes and shows whether it lands in <b>Inbox</b> or <b>Spam</b> – before you send it to carriers.</p>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="placement">
      <label>Send from<select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['email']) ?></option><?php endforeach; ?></select></label>
      <label>Subject<input name="subject" placeholder="Same subject you plan to send" required></label>
      <label>Body (HTML or text)<textarea name="body" rows="5" required></textarea></label>
      <button class="btn btn-primary" <?= count($accounts) ? '' : 'disabled' ?>>Run placement test</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>Placement test results</h2>
  <?php if (!$tests): ?><p class="muted">No tests yet. You can also run one from any campaign ("Inbox placement test").</p><?php else: ?>
  <?php foreach ($tests as $t):
      $res = $db->all('SELECT r.*, a.email FROM placement_results r LEFT JOIN email_accounts a ON a.id = r.seed_account_id WHERE r.test_id = ?', [$t['id']]);
      $cnt = ['inbox' => 0, 'spam' => 0, 'pending' => 0, 'missing' => 0];
      foreach ($res as $r) $cnt[$r['landed']] = ($cnt[$r['landed']] ?? 0) + 1;
      $checked = $cnt['inbox'] + $cnt['spam'];
      ?>
    <details class="ptest" <?= $focus === (int)$t['id'] || ($t === $tests[0] && !$focus) ? 'open' : '' ?>>
      <summary>
        <b><?= e($t['subject']) ?></b> <span class="muted small">from <?= e($t['email']) ?> · <?= e(time_ago($t['created_at'])) ?></span>
        <span class="right">
          <?php if ($checked): ?><span class="badge b-<?= pct($cnt['inbox'], $checked) >= 90 ? 'ok' : (pct($cnt['inbox'], $checked) >= 70 ? 'warn' : 'bad') ?>"><?= pct($cnt['inbox'], $checked) ?>% inbox</span><?php endif; ?>
          <?= $t['status'] === 'running' ? '<span class="badge b-info">checking…</span>' : '' ?>
        </span>
      </summary>
      <div class="ptest-grid">
        <?php foreach ($res as $r): ?><div class="pcell <?= e($r['landed']) ?>"><span class="small"><?= e($r['email']) ?></span><b><?= e(ucfirst($r['landed'])) ?></b></div><?php endforeach; ?>
      </div>
      <p class="small muted">Inbox <?= $cnt['inbox'] ?> · Spam <?= $cnt['spam'] ?> · Waiting <?= $cnt['pending'] ?><?= $cnt['missing'] ? ' · Not found ' . $cnt['missing'] : '' ?>. <?= $cnt['spam'] ? 'Fix the content issues from the spam checker, lower volume on this account and keep warmup on, then test again.' : '' ?></p>
    </details>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="grid-2">
  <div class="card">
    <h2>Domain health check</h2>
    <form class="toolbar" method="get"><input type="hidden" name="p" value="deliverability">
      <input name="domain" value="<?= e($domain) ?>" placeholder="yourdomain.com" list="doms" class="grow"><datalist id="doms"><?php foreach ($domains as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
      <button class="btn">Check SPF / DKIM / DMARC / blacklists</button></form>
    <?php if ($dns): ?>
      <table class="table compact"><?php foreach ($dns['checks'] as $c): ?><tr><td class="nowrap"><?= $c['ok'] ? '<span class="c-ok">✓</span>' : '<span class="c-bad">✕</span>' ?> <?= e($c['name']) ?></td><td class="small break"><?= e($c['info']) ?></td></tr><?php endforeach; ?></table>
    <?php endif; ?>
  </div>
  <div class="card tip">
    <h2>Deliverability checklist</h2>
    <ul class="checklist">
      <li>Warm up every new account 2–3 weeks before campaigns – and keep warmup on afterwards.</li>
      <li>Stay at 30–50 cold emails per account per day. Need more? Add accounts, don't raise limits.</li>
      <li>Keep bounces under 2%: import clean lists, use "verify domains" on import.</li>
      <li>Personalise ({{first_name}}, {{company_name}}, MC#) and use spintax so no two emails are identical.</li>
      <li>First email: no links, no images, no attachments, under 120 words. Ask a simple question.</li>
      <li>Follow-ups as replies in the same thread (leave subject empty).</li>
      <li>Own domain on Google Workspace? SPF + DKIM + DMARC are mandatory.</li>
      <li>Send in business hours on weekdays, with 2–5 minute random gaps.</li>
      <li>Always honour unsubscribes – Runnex suppresses them across all campaigns automatically.</li>
      <li>Run an inbox placement test before every new campaign.</li>
    </ul>
  </div>
</div>
<?php layout_end();
