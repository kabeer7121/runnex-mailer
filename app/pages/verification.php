<?php
declare(strict_types=1);

$db = db();
$ids = Auth::scopeIds() ?: [0];
$in = DB::in($ids);

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'settings' && Auth::isAdmin()) {
        $prov = in_array(post('verify_api_provider'), ['none', 'verifalia', 'millionverifier', 'zerobounce', 'neverbounce'], true) ? post('verify_api_provider') : 'none';
        set_setting('verify_api_provider', $prov);
        if ($prov === 'verifalia') {
            if (post('verifalia_user') !== '' && post('verifalia_pass') !== '') set_setting('verify_api_key', Crypto::encrypt(post('verifalia_user') . ':' . post('verifalia_pass')));
            set_setting('verifalia_user', post('verifalia_user'));
            set_setting('verifalia_quality', in_array(post('verifalia_quality'), ['Standard', 'High', 'Extreme'], true) ? post('verifalia_quality') : 'Standard');
            set_setting('verifalia_paused_until', 0);
        } elseif (post('verify_api_key') !== '') {
            set_setting('verify_api_key', Crypto::encrypt(post('verify_api_key')));
        }
        set_setting('verify_auto_remove', post('verify_auto_remove') === '1' ? '1' : '0');
        if ($prov === 'none') set_setting('verify_api_key', '');
        set_setting('verify_block_risky', post('verify_block_risky') === '1' ? '1' : '0');
        set_setting('verify_smtp', post('verify_smtp') === 'off' ? 'off' : 'auto');
        set_setting('port25_status', '');
        $msg = 'Verification settings saved.';
        if ($prov === 'verifalia') {
            $bal = Verifier::verifaliaBalance();
            $msg .= isset($bal['error']) ? ' Verifalia: ' . $bal['error'] : ' Connected to Verifalia – ' . (int)($bal['free'] ?? 0) . ' free + ' . (int)($bal['packs'] ?? 0) . ' paid credits available.';
            flash(isset($bal['error']) ? 'error' : 'success', $msg);
        } else {
            flash('success', $msg);
        }
        redirect('verification');
    }
    if ($action === 'run') {
        @set_time_limit(60);
        $n = Verifier::runBatch(time() + 25, $ids, 400);
        $left = (int)$db->val("SELECT COUNT(*) FROM contacts WHERE verified_at IS NULL AND company_id IN ($in)", $ids);
        if (!$left) flash('success', "Verified $n email(s). All contacts are verified.");
        redirect('verification', $left && $n ? ['auto' => 1] : []);
    }
    if ($action === 'reverify') {
        $db->exec("UPDATE contacts SET verified_at = NULL, verify_status = NULL, verify_reason = NULL, verify_suggestion = NULL WHERE company_id IN ($in) AND status = 'active'", $ids);
        $db->exec('DELETE FROM verify_domains');
        flash('success', 'All active contacts will be verified again.');
        redirect('verification', ['auto' => 1]);
    }
    if ($action === 'fix_all') {
        $n = 0;
        foreach ($db->all("SELECT * FROM contacts WHERE verify_suggestion IS NOT NULL AND verify_status = 'invalid' AND company_id IN ($in)", $ids) as $c) {
            if (!$db->val('SELECT COUNT(*) FROM contacts WHERE company_id = ? AND email = ?', [$c['company_id'], $c['verify_suggestion']])) {
                $db->update('contacts', ['email' => $c['verify_suggestion'], 'verify_status' => null, 'verify_reason' => null, 'verify_suggestion' => null, 'verified_at' => null], 'id = ?', [$c['id']]);
                $db->exec("UPDATE campaign_leads SET status = CASE WHEN current_step = 0 THEN 'pending' ELSE 'active' END, note = NULL WHERE contact_id = ? AND status = 'skipped' AND note LIKE 'Invalid email%'", [$c['id']]);
                $n++;
            }
        }
        flash('success', "Corrected $n misspelled email(s). They will be re-verified now.");
        redirect('verification', ['auto' => 1]);
    }
    if ($action === 'fix_one') {
        $c = $db->row('SELECT * FROM contacts WHERE id = ?', [(int)post('id')]);
        if ($c && Auth::canCompany((int)$c['company_id']) && $c['verify_suggestion']) {
            if ($db->val('SELECT COUNT(*) FROM contacts WHERE company_id = ? AND email = ?', [$c['company_id'], $c['verify_suggestion']])) {
                flash('warn', $c['verify_suggestion'] . ' already exists as another contact.');
            } else {
                $db->update('contacts', ['email' => $c['verify_suggestion'], 'verified_at' => null], 'id = ?', [$c['id']]);
                $res = Verifier::verifyContact($db->row('SELECT * FROM contacts WHERE id = ?', [$c['id']]));
                if ($res['status'] !== 'invalid') $db->exec("UPDATE campaign_leads SET status = CASE WHEN current_step = 0 THEN 'pending' ELSE 'active' END, note = NULL WHERE contact_id = ? AND status = 'skipped' AND note LIKE 'Invalid email%'", [$c['id']]);
                flash('success', 'Corrected to ' . $c['verify_suggestion'] . ' – now ' . $res['status'] . '.');
            }
        }
        back('verification');
    }
    if ($action === 'remove_invalid') {
        $cids = array_map('intval', $db->col("SELECT id FROM contacts WHERE verify_status = 'invalid' AND company_id IN ($in)", $ids));
        if ($cids) {
            $cin = DB::in($cids);
            $db->exec("DELETE FROM list_contacts WHERE contact_id IN ($cin)", $cids);
            $db->exec("DELETE FROM campaign_leads WHERE contact_id IN ($cin) AND status IN ('pending','skipped')", $cids);
            $db->exec("DELETE FROM contacts WHERE id IN ($cin)", $cids);
        }
        log_activity('verify.remove_invalid', count($cids) . ' contacts');
        flash('success', count($cids) . ' invalid contact(s) removed.');
        redirect('verification');
    }
}

// quick single check
$single = null;
if (get('check') !== '') {
    $single = Verifier::check(get('check'));
}

$counts = ['valid' => 0, 'risky' => 0, 'invalid' => 0, 'unverified' => 0];
foreach ($db->all("SELECT COALESCE(verify_status, 'unverified') AS s, COUNT(*) AS n FROM contacts WHERE company_id IN ($in) GROUP BY COALESCE(verify_status, 'unverified')", $ids) as $r) {
    $counts[$r['s'] === 'queued' ? 'unverified' : $r['s']] += (int)$r['n'];
}
$total = array_sum($counts);
$done = $total - $counts['unverified'];
$fixable = (int)$db->val("SELECT COUNT(*) FROM contacts WHERE verify_suggestion IS NOT NULL AND verify_status = 'invalid' AND company_id IN ($in)", $ids);
$auto = get('auto') === '1' && $counts['unverified'] > 0;

$tab = in_array(get('tab'), ['invalid', 'risky', 'valid', 'unverified'], true) ? get('tab') : 'invalid';
$where = $tab === 'unverified' ? 'verified_at IS NULL' : 'verify_status = ?';
$params = $tab === 'unverified' ? $ids : array_merge([$tab], $ids);
[$off, $lim, $page] = paging(50);
$rowsTotal = (int)$db->val("SELECT COUNT(*) FROM contacts WHERE $where AND company_id IN ($in)", $tab === 'unverified' ? $ids : array_merge([$tab], $ids));
$rows = $db->all("SELECT * FROM contacts WHERE $where AND company_id IN ($in) ORDER BY verified_at DESC, id DESC LIMIT $lim OFFSET $off", $params);
$reasons = $db->all("SELECT verify_reason, COUNT(*) AS n FROM contacts WHERE verify_status IN ('invalid','risky') AND company_id IN ($in) GROUP BY verify_reason ORDER BY n DESC LIMIT 8", $ids);

$provider = (string)setting('verify_api_provider', 'none');
$port = (string)setting('port25_status', '');
$portState = $port ? explode('|', $port)[0] : 'not tested';

layout_start('Email Verification', 'verification');
if ($auto) {
    // keep verifying in the browser batch after batch (the cron worker also continues in the background)
    echo '<form id="autogo" method="post" action="' . e(url('verification')) . '">' . csrf_field() . '<input type="hidden" name="action" value="run"></form><script>setTimeout(function(){document.getElementById("autogo").submit()},800)</script>';
}
?>
<div class="stats-grid">
  <?= stat_card('Total contacts', num($total), pct($done, $total) . '% verified', '', 'contact') ?>
  <?= stat_card('Valid', num($counts['valid']), pct($counts['valid'], $total) . '% – safe to send', 'tone-green', 'check') ?>
  <?= stat_card('Risky', num($counts['risky']), 'catch-all / role / unconfirmed', '', 'activity') ?>
  <?= stat_card('Invalid', num($counts['invalid']), 'never emailed', $counts['invalid'] ? 'tone-red' : '', 'ban') ?>
  <?= stat_card('Not verified yet', num($counts['unverified']), $counts['unverified'] ? 'verifying automatically' : 'all done', '', 'search') ?>
</div>

<?php if ($auto): ?>
  <div class="alert alert-info"><b>Verifying your list…</b> <?= num($done) ?> of <?= num($total) ?> done (<?= pct($done, $total) ?>%). You can leave this page – the background worker finishes the rest automatically. <a href="<?= url('verification') ?>">Stop</a></div>
<?php endif; ?>

<div class="grid-2-1">
  <div class="card">
    <div class="card-head"><h2>Verification progress</h2>
      <div class="page-actions">
        <?php if ($counts['unverified']): ?><form method="post" class="inline"><?= csrf_field() ?><button class="btn btn-primary" name="action" value="run"><?= icon('check') ?> Verify <?= num($counts['unverified']) ?> now</button></form><?php endif; ?>
        <?php if ($fixable): ?><form method="post" class="inline"><?= csrf_field() ?><button class="btn" name="action" value="fix_all">Fix <?= num($fixable) ?> typos</button></form><?php endif; ?>
        <?php if ($counts['invalid']): ?><form method="post" class="inline" onsubmit="return confirm('Delete all <?= (int)$counts['invalid'] ?> invalid contacts permanently?')"><?= csrf_field() ?><button class="btn btn-danger" name="action" value="remove_invalid">Remove invalid</button></form><?php endif; ?>
        <form method="post" class="inline" onsubmit="return confirm('Verify every active contact again?')"><?= csrf_field() ?><button class="btn" name="action" value="reverify">Re-verify all</button></form>
      </div>
    </div>
    <div class="vbar">
      <?php foreach (['valid' => 'var(--ok)', 'risky' => 'var(--warn)', 'invalid' => 'var(--bad)', 'unverified' => '#cbd2dd'] as $k => $col): if (!$counts[$k]) continue; ?>
        <span style="width:<?= pct($counts[$k], $total) ?>%;background:<?= $col ?>" title="<?= e(Verifier::statuses()[$k]) ?>: <?= num($counts[$k]) ?>"></span>
      <?php endforeach; ?>
    </div>
    <div class="vlegend small"><span><i style="background:var(--ok)"></i>Valid</span><span><i style="background:var(--warn)"></i>Risky</span><span><i style="background:var(--bad)"></i>Invalid</span><span><i style="background:#cbd2dd"></i>Not verified</span></div>
    <?php if ($reasons): ?>
      <h3>Top problems</h3>
      <table class="table compact"><?php foreach ($reasons as $r): ?><tr><td class="small"><?= e($r['verify_reason']) ?></td><td class="right"><?= num($r['n']) ?></td></tr><?php endforeach; ?></table>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Check a single email</h2>
    <form method="get" class="toolbar"><input type="hidden" name="p" value="verification"><input name="check" value="<?= e(get('check')) ?>" placeholder="name@carrier.com" class="grow" required><button class="btn btn-primary">Check</button></form>
    <?php if ($single): ?>
      <div class="vresult v-<?= e($single['status']) ?>">
        <?= Verifier::badge($single['status']) ?> <b><?= e(get('check')) ?></b>
        <div class="small"><?= e($single['reason']) ?></div>
        <?php if ($single['suggestion']): ?><div class="small">Suggestion: <a href="<?= url('verification', ['check' => $single['suggestion']]) ?>"><?= e($single['suggestion']) ?></a></div><?php endif; ?>
        <div class="small muted">Method: <?= e($single['method']) ?></div>
      </div>
    <?php endif; ?>
    <h3>How it works</h3>
    <ul class="tips small">
      <li>Every imported carrier is verified automatically in the background.</li>
      <li>Before <b>any</b> campaign email is sent, the address is checked – invalid emails are never sent to.</li>
      <li>Hard bounces mark the address invalid permanently.</li>
      <li><b>Invalid</b>: bad format, misspelled domain (gmial.com), disposable, fake, no mail server or mailbox does not exist.</li>
      <li><b>Risky</b>: catch-all domains, shared inboxes (info@, office@) or mailboxes the server would not confirm. Sent by default – you can block them below.</li>
    </ul>
  </div>
</div>

<div class="card">
  <div class="tabs">
    <?php foreach (['invalid' => 'Invalid', 'risky' => 'Risky', 'unverified' => 'Not verified', 'valid' => 'Valid'] as $k => $l): ?>
      <a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= url('verification', ['tab' => $k]) ?>"><?= $l ?> (<?= num($counts[$k]) ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?><p class="muted">Nothing here.</p><?php else: ?>
  <div class="table-scroll">
  <table class="table">
    <tr><th>Email</th><th>Carrier</th><th>Result</th><th>Reason</th><th>Checked</th><th></th></tr>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><a href="<?= url('contact', ['id' => $c['id']]) ?>"><?= e($c['email']) ?></a></td>
        <td class="small"><?= e($c['company_name']) ?></td>
        <td><?= Verifier::badge($c['verify_status']) ?></td>
        <td class="small"><?= e($c['verify_reason'] ?: '—') ?></td>
        <td class="small muted"><?= e(time_ago($c['verified_at'])) ?></td>
        <td class="right nowrap"><?php if ($c['verify_suggestion']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm" name="action" value="fix_one">Fix → <?= e($c['verify_suggestion']) ?></button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?= pager_html($rowsTotal, $lim, $page) ?>
  <?php endif; ?>
</div>

<?php if (Auth::isAdmin()): ?>
<div class="card" id="vsettings">
  <div class="card-head"><h2>Verification settings</h2><?php if ($provider === 'verifalia' && setting('verify_api_key')): $bal = Verifier::verifaliaBalance(); ?><span class="small"><?= isset($bal['error']) ? '<span class="c-bad">' . e($bal['error']) . '</span>' : 'Verifalia credits: <b>' . (int)($bal['free'] ?? 0) . '</b> free today + <b>' . (int)($bal['packs'] ?? 0) . '</b> paid' ?></span><?php endif; ?></div>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="settings">
    <div class="grid2">
      <label>Mailbox verification service <span class="muted small">(optional – most accurate)</span>
        <select name="verify_api_provider" data-vprov>
          <?php foreach (['none' => 'None – use built-in checks', 'verifalia' => 'Verifalia (25 free checks/day)', 'millionverifier' => 'MillionVerifier (cheapest paid)', 'zerobounce' => 'ZeroBounce', 'neverbounce' => 'NeverBounce'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $provider === $k ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label data-vshow="millionverifier zerobounce neverbounce">API key <?= setting('verify_api_key') && $provider !== 'verifalia' ? '<span class="muted small">(saved – leave blank to keep)</span>' : '' ?><input type="password" name="verify_api_key" autocomplete="off"></label>
      <label data-vshow="verifalia">Verifalia username <span class="muted small">(create a user at verifalia.com → Client area → Users)</span><input name="verifalia_user" value="<?= e((string)setting('verifalia_user', '')) ?>" autocomplete="off"></label>
      <label data-vshow="verifalia">Verifalia password <?= $provider === 'verifalia' && setting('verify_api_key') ? '<span class="muted small">(saved – re-enter only to change)</span>' : '' ?><input type="password" name="verifalia_pass" autocomplete="new-password"></label>
      <label data-vshow="verifalia">Quality <select name="verifalia_quality"><?php foreach (['Standard' => 'Standard (1 credit/email)', 'High' => 'High (more thorough)', 'Extreme' => 'Extreme (most thorough)'] as $k => $l): ?><option value="<?= $k ?>" <?= setting('verifalia_quality', 'Standard') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label>Built-in mailbox check (SMTP)
        <select name="verify_smtp"><option value="auto">On when the server allows it</option><option value="off" <?= setting('verify_smtp') === 'off' ? 'selected' : '' ?>>Off</option></select>
        <span class="muted small">Port 25 on this server: <b><?= e($portState) ?></b>. Most shared hosting (incl. Hostinger) blocks it – then format, typo, disposable and mail-server checks still run, and a service above confirms mailboxes.</span></label>
      <label class="check"><input type="checkbox" name="verify_auto_remove" value="1" <?= setting('verify_auto_remove', '1') === '1' ? 'checked' : '' ?>> <span><b>Remove invalid emails from lists automatically</b><br><span class="muted small">They stay on file marked invalid so a re-import can never add them back.</span></span></label>
      <label class="check"><input type="checkbox" name="verify_block_risky" value="1" <?= setting('verify_block_risky') === '1' ? 'checked' : '' ?>> <span><b>Also skip risky emails</b><br><span class="muted small">Safest for new accounts. Skips catch-all, role (info@) and unconfirmed addresses.</span></span></label>
    </div>
    <button class="btn btn-primary">Save settings</button>
  </form>
</div>
<?php endif; ?>
<?php layout_end();
