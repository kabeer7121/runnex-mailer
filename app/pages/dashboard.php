<?php
declare(strict_types=1);

$db = db();
[$ms, $mp] = Auth::scope('m.company_id', 'm.user_id');
$today = local_today();
$from = local_day_offset(-29);

$todayStats = Stats::summary(" AND m.sent_day = ? $ms", array_merge([$today], $mp));
$s30 = Stats::summary(" AND m.sent_day >= ? $ms", array_merge([$from], $mp));
$daily = Stats::daily($ms, $mp, $from, $today);

[$as, $ap] = Auth::scope('company_id', 'user_id');
$accounts = $db->all("SELECT * FROM email_accounts WHERE 1=1 $as ORDER BY health_score ASC", $ap);
$hCount = ['excellent' => 0, 'good' => 0, 'at_risk' => 0, 'critical' => 0, 'new' => 0, 'error' => 0];
$capacity = 0;
$sentToday = 0;
foreach ($accounts as $a) {
    $hCount[$a['health_status']] = ($hCount[$a['health_status']] ?? 0) + 1;
    $capacity += Health::effectiveLimit($a);
    $sentToday += (int)$a['sent_today'];
}
[$cs, $cp] = Auth::scope('company_id', 'user_id');
$running = $db->all("SELECT * FROM campaigns WHERE status IN ('running','scheduled','paused') $cs ORDER BY status, created_at DESC LIMIT 6", $cp);
[$rs, $rp] = Auth::scope('r.company_id', 'r.user_id');
$replies = $db->all("SELECT r.*, c.first_name, c.company_name FROM replies r LEFT JOIN contacts c ON c.id = r.contact_id WHERE r.direction = 'in' AND r.is_auto = 0 $rs ORDER BY r.received_at DESC LIMIT 6", $rp);

// onboarding checklist (disappears once everything is done)
[$ks, $kp] = Auth::scope('company_id', null);
$cronOk = setting('cron_last_run') && strtotime(setting('cron_last_run') . ' UTC') > time() - 600;
$onboard = [
    ['Cron job running', 'Powers sending, warmup and stats.', $cronOk, Auth::isAdmin() ? url('settings') . '#cron' : null],
    ['Connect 2+ email accounts', 'Warmup needs at least two; 5+ is ideal.', count($accounts) >= 2, url('account_edit')],
    ['Add company address', 'Required in the email footer (CAN-SPAM).', !$db->val("SELECT COUNT(*) FROM companies WHERE (address IS NULL OR address = '') AND id IN (" . DB::in(Auth::scopeIds() ?: [0]) . ')', Auth::scopeIds() ?: [0]), Auth::isAdmin() ? url('companies') : null],
    ['Import carriers', 'Upload your sheet – columns are matched automatically.', (bool)$db->val("SELECT COUNT(*) FROM contacts WHERE 1=1 $ks", $kp), url('import')],
    ['Launch a campaign', 'Start from a ready-made dispatch sequence.', (bool)$db->val("SELECT COUNT(*) FROM campaigns WHERE status <> 'draft' $cs", $cp), url('templates')],
];
$onboardDone = count(array_filter($onboard, fn($o) => $o[2]));

layout_start('Dashboard', 'dashboard', '<a class="btn btn-primary" href="' . url('campaign_edit') . '">' . icon('plus') . ' New campaign</a>');
?>
<?php if ($onboardDone < count($onboard)): ?>
<div class="card onboard">
  <div class="card-head"><h2>Getting started – <?= $onboardDone ?>/<?= count($onboard) ?> done</h2></div>
  <ol>
    <?php foreach ($onboard as [$t, $d, $done, $link]): ?>
      <li class="<?= $done ? 'done' : '' ?>"><b><?= $done ? '✓ ' : '' ?><?= $link && !$done ? '<a href="' . e($link) . '">' . e($t) . ' →</a>' : e($t) ?></b><?= e($d) ?></li>
    <?php endforeach; ?>
  </ol>
</div>
<?php endif; ?>
<div class="stats-grid">
  <?= stat_card('Sent today', num($todayStats['sent']), num($sentToday) . ' / ' . num($capacity + $sentToday) . ' safe capacity used', '', 'send') ?>
  <?= stat_card('Sent (30 days)', num($s30['sent']), num($s30['delivered']) . ' delivered · ' . $s30['delivery_rate'] . '%', '', 'mail') ?>
  <?= stat_card('Open / seen rate', $s30['open_rate'] . '%', num($s30['opened']) . ' carriers opened · ' . num($s30['opens_total']) . ' total views', 'tone-blue', 'activity') ?>
  <?= stat_card('Reply rate', $s30['reply_rate'] . '%', num($s30['replied']) . ' replies', 'tone-green', 'inbox') ?>
  <?= stat_card('Click rate', $s30['click_rate'] . '%', num($s30['clicked']) . ' clicked', '', 'check') ?>
  <?= stat_card('Bounce rate', $s30['bounce_rate'] . '%', $s30['bounce_rate'] > 3 ? 'Too high – clean your list' : 'Healthy (keep under 2–3%)', $s30['bounce_rate'] > 3 ? 'tone-red' : '', 'ban') ?>
</div>

<div class="grid-2-1">
  <div class="card">
    <div class="card-head"><h2>Last 30 days</h2><a href="<?= url('reports') ?>" class="link">Full reports →</a></div>
    <?= chart('line', $daily['labels'], [
        ['label' => 'Sent', 'data' => $daily['sent'], 'color' => '#6b7280'],
        ['label' => 'Opened', 'data' => $daily['opened'], 'color' => '#2563eb'],
        ['label' => 'Replied', 'data' => $daily['replied'], 'color' => '#16a34a'],
        ['label' => 'Bounced', 'data' => $daily['bounced'], 'color' => '#dc2626'],
    ], 280) ?>
  </div>
  <div class="card">
    <div class="card-head"><h2>Account health</h2><a href="<?= url('warmup') ?>" class="link">Details →</a></div>
    <div class="health-summary">
      <div><b class="c-ok"><?= $hCount['excellent'] + $hCount['good'] ?></b><span>Healthy</span></div>
      <div><b class="c-muted"><?= $hCount['new'] ?></b><span>Warming up</span></div>
      <div><b class="c-warn"><?= $hCount['at_risk'] ?></b><span>At risk</span></div>
      <div><b class="c-bad"><?= $hCount['critical'] + $hCount['error'] ?></b><span>Spam / error</span></div>
    </div>
    <?php if (!$accounts): ?>
      <?= empty_state('No email accounts', 'Connect your Gmail accounts to start warming up.', '<a class="btn btn-primary" href="' . url('account_edit') . '">Connect Gmail</a>') ?>
    <?php else: ?>
      <table class="table compact">
        <?php foreach (array_slice($accounts, 0, 7) as $a): ?>
          <tr><td><a href="<?= url('account_view', ['id' => $a['id']]) ?>"><?= e($a['email']) ?></a></td><td class="right"><?= health_badge($a['status'] === 'error' ? 'error' : $a['health_status'], (int)$a['health_score']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-head"><h2>Active campaigns</h2><a href="<?= url('campaigns') ?>" class="link">All →</a></div>
    <?php if (!$running): ?>
      <?= empty_state('No active campaigns', 'Create a campaign to start reaching carriers.') ?>
    <?php else: ?>
      <table class="table">
        <tr><th>Campaign</th><th>Status</th><th class="right">Sent</th><th class="right">Opened</th><th class="right">Replied</th></tr>
        <?php foreach ($running as $c): $cs2 = Stats::summary(' AND m.campaign_id = ?', [$c['id']]); ?>
          <tr><td><a href="<?= url('campaign_view', ['id' => $c['id']]) ?>"><?= e($c['name']) ?></a></td><td><?= status_badge($c['status']) ?></td>
            <td class="right"><?= num($cs2['sent']) ?></td><td class="right"><?= $cs2['open_rate'] ?>%</td><td class="right"><?= $cs2['reply_rate'] ?>%</td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><h2>Latest replies</h2><a href="<?= url('inbox') ?>" class="link">Unibox →</a></div>
    <?php if (!$replies): ?>
      <?= empty_state('No replies yet', 'Replies from carriers show up here automatically.') ?>
    <?php else: ?>
      <div class="reply-list">
        <?php foreach ($replies as $r): ?>
          <a class="reply-item <?= $r['is_read'] ? '' : 'unread' ?>" href="<?= url('inbox', ['id' => $r['id']]) ?>">
            <b><?= e($r['from_name'] ?: $r['from_email']) ?></b><?php if ($r['company_name']): ?> <span class="muted">· <?= e($r['company_name']) ?></span><?php endif; ?>
            <span class="muted right"><?= e(time_ago($r['received_at'])) ?></span>
            <div class="snippet"><?= e(mb_substr((string)$r['body_text'], 0, 110)) ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (Auth::isAdmin()):
    $agents = Stats::grouped('user_id', " AND m.sent_day >= ? $ms", array_merge([$from], $mp));
    $names = [];
    foreach ($db->all('SELECT id, name FROM users') as $u) $names[$u['id']] = $u['name'];
    ?>
<div class="card">
  <div class="card-head"><h2>Agent performance (30 days)</h2></div>
  <?php if (!$agents): ?><p class="muted">No sends yet.</p><?php else: ?>
  <table class="table">
    <tr><th>Agent</th><th class="right">Sent</th><th class="right">Open rate</th><th class="right">Reply rate</th><th class="right">Replies</th><th class="right">Bounce rate</th></tr>
    <?php foreach ($agents as $g): ?>
      <tr><td><?= e($names[$g['k']] ?? 'Unknown') ?></td><td class="right"><?= num($g['sent']) ?></td><td class="right"><?= $g['open_rate'] ?>%</td><td class="right"><?= $g['reply_rate'] ?>%</td><td class="right"><?= num($g['replied']) ?></td><td class="right"><?= $g['bounce_rate'] ?>%</td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_end();
