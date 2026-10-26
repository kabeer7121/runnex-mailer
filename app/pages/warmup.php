<?php
declare(strict_types=1);

$db = db();
if (is_post() && Auth::isAdmin()) {
    csrf_check();
    set_setting('warmup_paused', post('paused') === '1' ? '1' : '0');
    flash('success', post('paused') === '1' ? 'Warmup paused for all accounts.' : 'Warmup resumed.');
    redirect('warmup');
}

[$as, $ap] = Auth::scope('company_id', 'user_id');
$accounts = $db->all("SELECT * FROM email_accounts WHERE 1=1 $as ORDER BY health_score ASC, email", $ap);
$ids = array_map(fn($a) => (int)$a['id'], $accounts);
$poolSize = (int)$db->val("SELECT COUNT(*) FROM email_accounts WHERE warmup_enabled = 1 AND status = 'active'");
$from = local_day_offset(-29);
$labels = $inbox = $spam = $wsent = [];
$byDay = [];
if ($ids) {
    foreach ($db->all('SELECT day, SUM(warmup_sent) AS ws, SUM(warmup_inbox) AS wi, SUM(warmup_spam) AS wsp FROM account_daily WHERE day >= ? AND account_id IN (' . DB::in($ids) . ') GROUP BY day', array_merge([$from], $ids)) as $r) {
        $byDay[$r['day']] = $r;
    }
}
for ($i = 29; $i >= 0; $i--) {
    $d = local_day_offset(-$i);
    $labels[] = date('M j', strtotime($d));
    $wsent[] = (int)($byDay[$d]['ws'] ?? 0);
    $inbox[] = (int)($byDay[$d]['wi'] ?? 0);
    $spam[] = (int)($byDay[$d]['wsp'] ?? 0);
}
$in7 = array_sum(array_slice($inbox, -7));
$sp7 = array_sum(array_slice($spam, -7));
$todaySent = array_sum(array_map(fn($a) => (int)$a['warmup_sent_today'], $accounts));
$paused = setting('warmup_paused') === '1';

$actions = '';
if (Auth::isAdmin()) {
    $actions = '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="paused" value="' . ($paused ? '0' : '1') . '"><button class="btn">' . ($paused ? 'Resume warmup' : 'Pause all warmup') . '</button></form>';
}
layout_start('Warmup & Health', 'warmup', $actions);
?>
<?php if ($paused): ?><div class="alert alert-warn">Warmup is paused for all accounts.</div><?php endif; ?>
<?php if ($poolSize < 2): ?><div class="alert alert-warn">Warmup needs at least <b>2 active accounts</b> with warmup enabled (you have <?= $poolSize ?>). The more accounts you connect, the better warmup works – 5+ is ideal.</div><?php endif; ?>

<div class="stats-grid">
  <?= stat_card('Accounts in warmup pool', num($poolSize), 'across all companies') ?>
  <?= stat_card('Warmup emails today', num($todaySent)) ?>
  <?= stat_card('Inbox placement (7d)', ($in7 + $sp7) ? pct($in7, $in7 + $sp7) . '%' : '—', num($in7) . ' inbox · ' . num($sp7) . ' spam', ($in7 + $sp7) && pct($sp7, $in7 + $sp7) >= 10 ? 'tone-red' : 'tone-green') ?>
  <?= stat_card('Rescued from spam (7d)', num($sp7), 'moved to inbox & marked important') ?>
</div>

<div class="card">
  <h2>Inbox vs spam – last 30 days</h2>
  <?= chart('bar', $labels, [
      ['label' => 'Landed in inbox', 'data' => $inbox, 'color' => '#16a34a', 'stack' => 'p'],
      ['label' => 'Landed in spam', 'data' => $spam, 'color' => '#dc2626', 'stack' => 'p'],
  ], 260, ['stacked' => true]) ?>
</div>

<div class="card">
  <h2>Accounts</h2>
  <?php if (!$accounts): ?><?= empty_state('No accounts', 'Connect email accounts to start warmup.', '<a class="btn btn-primary" href="' . url('account_edit') . '">Connect Gmail</a>') ?><?php else: ?>
  <div class="table-scroll">
  <table class="table">
    <tr><th>Account</th><th>Health</th><th>Inbox rate (7d)</th><th>Warmup today</th><th>Days warmed</th><th>Safe campaign limit</th><th>What to do</th></tr>
    <?php foreach ($accounts as $a): $hd = json_decode((string)$a['health_data'], true) ?: []; ?>
      <tr>
        <td><a href="<?= url('account_view', ['id' => $a['id']]) ?>"><?= e($a['email']) ?></a></td>
        <td><?= health_badge($a['status'] === 'error' ? 'error' : $a['health_status'], (int)$a['health_score']) ?></td>
        <td><?php if (($hd['samples'] ?? 0) > 0): ?>
          <div class="bar"><span style="width:<?= (float)$hd['inbox_rate'] ?>%"></span></div><span class="small"><?= $hd['inbox_rate'] ?>% (<?= (int)$hd['samples'] ?> checked)</span>
        <?php else: ?><span class="muted small">no data yet</span><?php endif; ?></td>
        <td><?= (int)$a['warmup_enabled'] ? (int)$a['warmup_sent_today'] . ' / ' . Health::warmupTarget($a) : '<span class="badge b-muted">Off</span>' ?></td>
        <td><?= (int)($hd['days'] ?? Health::warmDays($a)) ?></td>
        <td><?= (int)($hd['suggested_limit'] ?? 0) ?>/day</td>
        <td class="small"><?= e($hd['tips'][0] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
</div>

<div class="card tip">
  <h3>How Runnex keeps accounts out of spam</h3>
  <ul>
    <li><b>Warmup network</b> – accounts send each other natural conversations every day, ramping up slowly (default 3 → 30/day).</li>
    <li><b>Spam rescue</b> – every warmup email found in spam is moved to the inbox (the "Not spam" signal), then opened, starred/marked important and replied to.</li>
    <li><b>Placement tracking</b> – we measure where each account's emails land. Health drops as soon as spam placement rises.</li>
    <li><b>Auto-protection</b> – accounts with 25%+ spam placement or 8%+ bounces stop sending campaigns automatically while warmup repairs them; "at risk" accounts get half volume.</li>
    <li><b>Smart limits</b> – campaign volume follows the account's age and health, with random gaps between emails and sending only in business hours.</li>
    <li><b>Weekend rhythm</b> – warmup volume drops ~40% on weekends, like a real business inbox.</li>
    <li><b>Self-healing</b> – accounts with connection errors are re-tested every 3 hours and resume on their own once fixed; auto-paused accounts resume when their health recovers.</li>
    <li><b>List protection</b> – a campaign whose list bounces 5%+ is paused automatically before it can damage your accounts.</li>
    <li><b>Reply handling</b> – replies stop the sequence, are auto-labelled (Interested / Call back / Not interested / Wrong contact / Unsubscribe) and out-of-office replies just delay the next follow-up.</li>
  </ul>
</div>
<?php layout_end();
