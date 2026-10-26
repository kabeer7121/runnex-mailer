<?php
declare(strict_types=1);

$db = db();
$id = (int)get('id');
$acc = $db->row('SELECT * FROM email_accounts WHERE id = ?', [$id]);
if (!$acc || !Auth::canRow($acc)) {
    flash('error', 'Account not found.');
    redirect('accounts');
}
if (get('recalc') === '1') {
    Health::compute($acc);
    $acc = $db->row('SELECT * FROM email_accounts WHERE id = ?', [$id]);
}
$hd = json_decode((string)$acc['health_data'], true) ?: [];
$from = local_day_offset(-29);
$rows = $db->all('SELECT * FROM account_daily WHERE account_id = ? AND day >= ? ORDER BY day', [$id, $from]);
$byDay = [];
foreach ($rows as $r) $byDay[$r['day']] = $r;
$labels = $sent = $wsent = $winbox = $wspam = $bounces = $replies = [];
for ($i = 29; $i >= 0; $i--) {
    $d = local_day_offset(-$i);
    $labels[] = date('M j', strtotime($d));
    $sent[] = (int)($byDay[$d]['sent'] ?? 0);
    $wsent[] = (int)($byDay[$d]['warmup_sent'] ?? 0);
    $winbox[] = (int)($byDay[$d]['warmup_inbox'] ?? 0);
    $wspam[] = (int)($byDay[$d]['warmup_spam'] ?? 0);
    $bounces[] = (int)($byDay[$d]['bounces'] ?? 0);
    $replies[] = (int)($byDay[$d]['replies'] ?? 0);
}
$s = Stats::summary(' AND m.account_id = ?', [$id]);
$dns = get('dns') === '1' ? Dns::check(email_domain($acc['email'])) : null;
$recentWarm = $db->all('SELECT w.*, a.email AS peer FROM warmup_messages w LEFT JOIN email_accounts a ON a.id = w.to_account_id WHERE w.from_account_id = ? ORDER BY w.sent_at DESC LIMIT 12', [$id]);
$effective = Health::effectiveLimit($acc);
$status = $acc['status'] === 'error' ? 'error' : $acc['health_status'];
$ring = ['excellent' => '#16a34a', 'good' => '#65a30d', 'at_risk' => '#d97706', 'critical' => '#dc2626', 'error' => '#dc2626', 'new' => '#6b7280'][$status] ?? '#6b7280';

$actions = '<form method="post" action="' . url('accounts') . '" class="inline">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '">'
    . '<button class="btn" name="action" value="check_now">Check inbox now</button> '
    . '<button class="btn" name="action" value="test">Test connection</button> '
    . ($acc['status'] === 'active' ? '<button class="btn" name="action" value="pause">Pause</button> ' : '<button class="btn" name="action" value="resume">Resume</button> ')
    . '</form><a class="btn btn-primary" href="' . url('account_edit', ['id' => $id]) . '">Edit</a>';
layout_start($acc['email'], 'accounts', $actions);
?>
<?php if ($acc['send_paused_reason']): ?><div class="alert alert-error"><b>Campaign sending paused automatically.</b> <?= e($acc['send_paused_reason']) ?></div><?php endif; ?>
<?php if ($acc['status'] === 'error'): ?><div class="alert alert-error"><b>Connection error:</b> <?= e($acc['last_error']) ?></div><?php endif; ?>

<div class="grid-1-2">
  <div class="card center">
    <div class="score-ring" style="--p:<?= (int)$acc['health_score'] ?>;--c:<?= $ring ?>"><div><b><?= (int)$acc['health_score'] ?></b><span>/100</span></div></div>
    <div style="margin-top:10px"><?= health_badge($status) ?></div>
    <p class="muted small">Auto-updated every 20 min<?= setting('health_last_run_ts') ? ' (last ' . e(time_ago(gmdate('Y-m-d H:i:s', (int)setting('health_last_run_ts')))) . ')' : '' ?> · <a href="<?= url('account_view', ['id' => $id, 'recalc' => 1]) ?>">recalculate now</a></p>
    <table class="table compact left-text">
      <tr><td>Inbox placement (45)</td><td class="right"><?= e((string)($hd['parts']['placement'] ?? '—')) ?></td></tr>
      <tr><td>Bounce rate (25)</td><td class="right"><?= e((string)($hd['parts']['bounce'] ?? '—')) ?></td></tr>
      <tr><td>Connection (15)</td><td class="right"><?= e((string)($hd['parts']['connection'] ?? '—')) ?></td></tr>
      <tr><td>Account maturity (15)</td><td class="right"><?= e((string)($hd['parts']['maturity'] ?? '—')) ?></td></tr>
      <?php if (!empty($hd['parts']['penalty'])): ?><tr><td>Unsubscribe penalty</td><td class="right c-bad">-<?= (int)$hd['parts']['penalty'] ?></td></tr><?php endif; ?>
    </table>
  </div>
  <div class="card">
    <h2>Health report</h2>
    <div class="stats-grid sm">
      <?= stat_card('Inbox rate (7d)', isset($hd['inbox_rate']) && $hd['inbox_rate'] !== null ? $hd['inbox_rate'] . '%' : '—', ($hd['inbox'] ?? 0) . ' inbox / ' . ($hd['spam'] ?? 0) . ' spam', ($hd['spam_rate'] ?? 0) >= 10 ? 'tone-red' : 'tone-green') ?>
      <?= stat_card('Bounce rate (14d)', ($hd['bounce_rate'] ?? 0) . '%', ($hd['sent14'] ?? 0) . ' sent') ?>
      <?= stat_card('Reply rate (14d)', ($hd['reply_rate'] ?? 0) . '%') ?>
      <?= stat_card('Warmed up', ($hd['days'] ?? 0) . ' days', (int)$acc['warmup_enabled'] ? 'Warmup on · ' . (int)$acc['warmup_sent_today'] . '/' . Health::warmupTarget($acc) . ' today' : 'Warmup off') ?>
    </div>
    <h3>Recommendations</h3>
    <ul class="tips"><?php foreach ($hd['tips'] ?? ['Waiting for first health check (runs with the cron job).'] as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
    <h3>Sending limit</h3>
    <div class="limit-box">
      <div><span class="muted">Your limit</span><b><?= (int)$acc['daily_limit'] ?>/day</b></div>
      <div><span class="muted">Suggested safe limit</span><b class="c-ok"><?= (int)($hd['suggested_limit'] ?? 0) ?>/day</b></div>
      <div><span class="muted">Enforced today</span><b><?= $effective ?>/day</b></div>
      <div><span class="muted">Sent today</span><b><?= (int)$acc['sent_today'] ?></b></div>
      <form method="post" action="<?= url('accounts') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-sm" name="action" value="apply_suggested">Use suggested</button></form>
    </div>
    <p class="muted small">Suggested limits: week 1 → 5/day, week 2 → 15, week 3 → 25, week 4 → 35, after 30 days → 40 (Gmail) / 50 (Workspace). Reduced automatically when health drops.</p>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <h2>Warmup placement (where this account's emails land)</h2>
    <?= chart('bar', $labels, [
        ['label' => 'Inbox', 'data' => $winbox, 'color' => '#16a34a', 'stack' => 'p'],
        ['label' => 'Spam', 'data' => $wspam, 'color' => '#dc2626', 'stack' => 'p'],
    ], 240, ['stacked' => true]) ?>
  </div>
  <div class="card">
    <h2>Daily activity</h2>
    <?= chart('line', $labels, [
        ['label' => 'Campaign sent', 'data' => $sent, 'color' => '#111827'],
        ['label' => 'Warmup sent', 'data' => $wsent, 'color' => '#f59e0b'],
        ['label' => 'Replies', 'data' => $replies, 'color' => '#16a34a'],
        ['label' => 'Bounces', 'data' => $bounces, 'color' => '#dc2626'],
    ], 240) ?>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <h2>Campaign stats (all time)</h2>
    <div class="stats-grid sm">
      <?= stat_card('Sent', num($s['sent'])) ?>
      <?= stat_card('Opened', $s['open_rate'] . '%', num($s['opened'])) ?>
      <?= stat_card('Replied', $s['reply_rate'] . '%', num($s['replied'])) ?>
      <?= stat_card('Bounced', $s['bounce_rate'] . '%', num($s['bounced'])) ?>
    </div>
    <h3>Domain authentication</h3>
    <?php if (!$dns): ?>
      <a class="btn btn-sm" href="<?= url('account_view', ['id' => $id, 'dns' => 1]) ?>">Run SPF / DKIM / DMARC / blacklist check</a>
    <?php else: ?>
      <table class="table compact"><?php foreach ($dns['checks'] as $c): ?><tr><td><?= $c['ok'] ? '<span class="c-ok">✓</span>' : '<span class="c-bad">✕</span>' ?> <?= e($c['name']) ?></td><td class="small"><?= e($c['info']) ?></td></tr><?php endforeach; ?></table>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Recent warmup emails</h2>
    <?php if (!$recentWarm): ?><p class="muted">No warmup emails yet. Warmup needs at least 2 connected accounts and runs between <?= (int)setting('warmup_start_hour', 7) ?>:00 and <?= (int)setting('warmup_end_hour', 20) ?>:00.</p><?php else: ?>
    <table class="table compact">
      <tr><th>To</th><th>Subject</th><th>Landed</th><th>When</th></tr>
      <?php foreach ($recentWarm as $w): ?>
        <tr><td class="small"><?= e($w['peer']) ?></td><td class="small"><?= e(mb_substr((string)$w['subject'], 0, 40)) ?><?= (int)$w['is_reply'] ? ' <span class="badge b-info">reply</span>' : '' ?></td>
          <td><?= $w['landed'] ? status_badge($w['landed']) : '<span class="muted small">checking…</span>' ?></td><td class="small muted"><?= e(time_ago($w['sent_at'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php layout_end();
