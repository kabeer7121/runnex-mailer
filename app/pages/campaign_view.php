<?php
declare(strict_types=1);

$db = db();
$id = (int)get('id');
$c = $db->row('SELECT * FROM campaigns WHERE id = ?', [$id]);
if (!$c || !Auth::canRow($c)) {
    flash('error', 'Campaign not found.');
    redirect('campaigns');
}

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'sync') {
        $n = Engine::syncLeads($c);
        flash('success', "$n new carriers added from the list.");
    } elseif (in_array($action, ['stop_leads', 'restart_leads'], true)) {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        if ($ids) {
            $in = DB::in($ids);
            if ($action === 'stop_leads') {
                $db->exec("UPDATE campaign_leads SET status = 'stopped', next_send_at = NULL WHERE campaign_id = ? AND id IN ($in) AND status IN ('pending','active')", array_merge([$id], $ids));
            } else {
                $db->exec("UPDATE campaign_leads SET status = CASE WHEN current_step = 0 THEN 'pending' ELSE 'active' END, next_send_at = ?, attempts = 0 WHERE campaign_id = ? AND id IN ($in) AND status IN ('stopped','failed','skipped')", array_merge([now()], [$id], $ids));
            }
            flash('success', 'Leads updated.');
        }
    }
    back('campaign_view');
}

$s = Stats::summary(' AND m.campaign_id = ?', [$id]);
$byStep = Stats::grouped('step_no', ' AND m.campaign_id = ?', [$id]);
usort($byStep, fn($a, $b) => $a['k'] <=> $b['k']);
$byAcc = Stats::grouped('account_id', ' AND m.campaign_id = ?', [$id]);
$accNames = [];
foreach ($db->all('SELECT id, email FROM email_accounts') as $a) $accNames[$a['id']] = $a['email'];
$firstDay = $db->val('SELECT MIN(sent_day) FROM messages WHERE campaign_id = ?', [$id]) ?: local_today();
if ($firstDay < local_day_offset(-60)) $firstDay = local_day_offset(-60);
$daily = Stats::daily(' AND m.campaign_id = ?', [$id], $firstDay, local_today());
$leadCounts = [];
foreach ($db->all('SELECT status, COUNT(*) AS n FROM campaign_leads WHERE campaign_id = ? GROUP BY status', [$id]) as $r) $leadCounts[$r['status']] = (int)$r['n'];
$totalLeads = array_sum($leadCounts);
$finished = ($leadCounts['completed'] ?? 0) + ($leadCounts['replied'] ?? 0) + ($leadCounts['bounced'] ?? 0) + ($leadCounts['unsubscribed'] ?? 0) + ($leadCounts['stopped'] ?? 0) + ($leadCounts['skipped'] ?? 0) + ($leadCounts['failed'] ?? 0);
$steps = $db->all('SELECT * FROM campaign_steps WHERE campaign_id = ? ORDER BY step_no', [$id]);
$accs = $db->all('SELECT a.* FROM email_accounts a JOIN campaign_accounts ca ON ca.account_id = a.id WHERE ca.campaign_id = ?', [$id]);
$capacity = array_sum(array_map(fn($a) => Health::effectiveLimit($a), $accs));
$clicks = $db->all("SELECT url, COUNT(*) AS n FROM events WHERE campaign_id = ? AND type = 'click' GROUP BY url ORDER BY n DESC LIMIT 10", [$id]);
$heat = Stats::heatmap('open', ' AND e.campaign_id = ?', [$id]);

$lf = get('lead_status');
$lw = 'l.campaign_id = ?';
$lp = [$id];
if ($lf !== '') {
    $lw .= ' AND l.status = ?';
    $lp[] = $lf;
}
if (get('q') !== '') {
    $lw .= ' AND (ct.email LIKE ? OR ct.company_name LIKE ? OR ct.first_name LIKE ?)';
    array_push($lp, '%' . get('q') . '%', '%' . get('q') . '%', '%' . get('q') . '%');
}
[$off, $lim, $page] = paging(50);
$leadTotal = (int)$db->val("SELECT COUNT(*) FROM campaign_leads l JOIN contacts ct ON ct.id = l.contact_id WHERE $lw", $lp);
$leads = $db->all("SELECT l.*, ct.email, ct.first_name, ct.last_name, ct.company_name, a.email AS acc_email,
    (SELECT MAX(m.opened_at) FROM messages m WHERE m.lead_id = l.id) AS opened,
    (SELECT SUM(m.open_count) FROM messages m WHERE m.lead_id = l.id) AS opens,
    (SELECT MAX(m.clicked_at) FROM messages m WHERE m.lead_id = l.id) AS clicked
    FROM campaign_leads l JOIN contacts ct ON ct.id = l.contact_id LEFT JOIN email_accounts a ON a.id = l.account_id WHERE $lw ORDER BY l.last_sent_at DESC, l.id LIMIT $lim OFFSET $off", $lp);

$actions = '<form method="post" action="' . url('campaigns') . '" class="inline">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '">'
    . (in_array($c['status'], ['running', 'scheduled'], true) ? '<button class="btn" name="action" value="pause">Pause</button> ' : '')
    . ($c['status'] === 'paused' ? '<button class="btn btn-primary" name="action" value="resume">Resume</button> ' : '')
    . '</form><form method="post" class="inline">' . csrf_field() . '<button class="btn" name="action" value="sync">Add new list members</button></form> '
    . '<a class="btn" href="' . url('reports', ['campaign' => $id]) . '">Report</a> <a class="btn btn-primary" href="' . url('campaign_edit', ['id' => $id]) . '">Edit</a>';
layout_start($c['name'], 'campaigns', $actions);
?>
<?php if (!empty($c['auto_note']) && $c['status'] === 'paused'): ?><div class="alert alert-error"><b>Paused automatically to protect your accounts.</b> <?= e($c['auto_note']) ?></div><?php endif; ?>
<div class="camp-meta">
  <?= status_badge($c['status']) ?>
  <span><?= count($steps) ?> email<?= count($steps) === 1 ? '' : 's' ?> in sequence</span>
  <span><?= count($accs) ?> sending accounts · <?= num($capacity) ?>/day safe capacity</span>
  <span><?= e($c['window_start']) ?>–<?= e($c['window_end']) ?> <?= e($c['timezone']) ?></span>
  <?php if ($c['status'] === 'running' && !Engine::inWindow($c)): ?><span class="badge b-info">Outside sending window – waiting</span><?php endif; ?>
  <?php if ($c['status'] === 'running' && !$capacity): ?><span class="badge b-bad">No healthy account available to send</span><?php endif; ?>
</div>
<div class="progress-wrap"><div class="progress"><span style="width:<?= pct($finished, $totalLeads) ?>%"></span></div><span class="small muted"><?= num($finished) ?> / <?= num($totalLeads) ?> carriers finished (<?= pct($finished, $totalLeads) ?>%)</span></div>

<div class="stats-grid">
  <?= stat_card('Emails sent', num($s['sent']), num($s['delivered']) . ' delivered') ?>
  <?= stat_card('Opened (seen)', $s['open_rate'] . '%', num($s['opened']) . ' carriers · ' . num($s['opens_total']) . ' views · ' . $s['avg_opens'] . ' per opener', 'tone-blue') ?>
  <?= stat_card('Clicked', $s['click_rate'] . '%', num($s['clicked']) . ' · ' . $s['click_to_open'] . '% of openers') ?>
  <?= stat_card('Replied', $s['reply_rate'] . '%', num($s['replied']) . ' replies', 'tone-green') ?>
  <?= stat_card('Bounced', $s['bounce_rate'] . '%', num($s['bounced']), $s['bounce_rate'] > 3 ? 'tone-red' : '') ?>
  <?= stat_card('Unsubscribed', $s['unsub_rate'] . '%', num($s['unsub'])) ?>
</div>

<div class="grid-2-1">
  <div class="card"><h2>Daily performance</h2>
    <?= chart('bar', $daily['labels'], [
        ['label' => 'Sent', 'data' => $daily['sent'], 'color' => '#9ca3af'],
        ['label' => 'Opened', 'data' => $daily['opened'], 'color' => '#2563eb'],
        ['label' => 'Replied', 'data' => $daily['replied'], 'color' => '#16a34a'],
    ], 260) ?></div>
  <div class="card"><h2>Lead status</h2>
    <?php $colors = ['pending' => '#9ca3af', 'active' => '#2563eb', 'sending' => '#60a5fa', 'completed' => '#111827', 'replied' => '#16a34a', 'bounced' => '#dc2626', 'unsubscribed' => '#f59e0b', 'stopped' => '#6b7280', 'skipped' => '#d1d5db', 'failed' => '#7f1d1d'];
    $lk = array_keys($leadCounts); ?>
    <?= $leadCounts ? chart('doughnut', array_map('ucfirst', $lk), [['label' => 'Leads', 'data' => array_values($leadCounts), 'colors' => array_map(fn($k) => $colors[$k] ?? '#999', $lk)]], 240) : '<p class="muted">No leads yet.</p>' ?></div>
</div>

<div class="grid-2">
  <div class="card"><h2>Performance by step</h2>
    <table class="table">
      <tr><th>Step</th><th class="right">Sent</th><th class="right">Open</th><th class="right">Click</th><th class="right">Reply</th><th class="right">Bounce</th></tr>
      <?php foreach ($byStep as $r): ?>
        <tr><td><?= (int)$r['k'] === 0 ? 'Email 1' : 'Follow-up ' . (int)$r['k'] ?><div class="small muted"><?= e(mb_substr((string)($steps[(int)$r['k']]['subject'] ?? '') ?: '(same thread)', 0, 50)) ?></div></td>
          <td class="right"><?= num($r['sent']) ?></td><td class="right"><?= $r['open_rate'] ?>%</td><td class="right"><?= $r['click_rate'] ?>%</td><td class="right"><b><?= $r['reply_rate'] ?>%</b></td><td class="right"><?= $r['bounce_rate'] ?>%</td></tr>
      <?php endforeach; ?>
      <?php if (!$byStep): ?><tr><td colspan="6" class="muted">Nothing sent yet.</td></tr><?php endif; ?>
    </table>
  </div>
  <div class="card"><h2>Performance by sending account</h2>
    <table class="table">
      <tr><th>Account</th><th class="right">Sent</th><th class="right">Open</th><th class="right">Reply</th><th class="right">Bounce</th></tr>
      <?php foreach ($byAcc as $r): ?>
        <tr><td class="small"><?= e($accNames[$r['k']] ?? 'deleted') ?></td><td class="right"><?= num($r['sent']) ?></td><td class="right"><?= $r['open_rate'] ?>%</td><td class="right"><?= $r['reply_rate'] ?>%</td><td class="right"><?= $r['bounce_rate'] ?>%</td></tr>
      <?php endforeach; ?>
      <?php if (!$byAcc): ?><tr><td colspan="5" class="muted">Nothing sent yet.</td></tr><?php endif; ?>
    </table>
  </div>
</div>

<div class="grid-2">
  <div class="card"><h2>When carriers open (local time)</h2><?= Stats::heatmapHtml($heat) ?></div>
  <div class="card"><h2>Top clicked links</h2>
    <?php if (!$clicks): ?><p class="muted"><?= (int)$c['track_clicks'] ? 'No clicks yet.' : 'Click tracking is off for this campaign.' ?></p><?php else: ?>
    <table class="table compact"><?php foreach ($clicks as $k): ?><tr><td class="small break"><?= e($k['url']) ?></td><td class="right"><?= num($k['n']) ?></td></tr><?php endforeach; ?></table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Leads</h2></div>
  <form class="toolbar wrap" method="get">
    <input type="hidden" name="p" value="campaign_view"><input type="hidden" name="id" value="<?= $id ?>">
    <input name="q" value="<?= e(get('q')) ?>" placeholder="Search carrier…">
    <select name="lead_status"><option value="">All (<?= num($totalLeads) ?>)</option><?php foreach ($leadCounts as $k => $n): ?><option value="<?= e($k) ?>" <?= $lf === $k ? 'selected' : '' ?>><?= e(ucfirst($k)) ?> (<?= num($n) ?>)</option><?php endforeach; ?></select>
    <button class="btn">Filter</button>
  </form>
  <form method="post">
    <?= csrf_field() ?>
    <div class="bulkbar"><select name="action"><option value="stop_leads">Stop selected</option><option value="restart_leads">Restart stopped/failed</option></select><button class="btn btn-sm">Apply</button></div>
    <div class="table-scroll">
    <table class="table">
      <tr><th><input type="checkbox" data-checkall></th><th>Carrier</th><th>Status</th><th>Step</th><th>Sender</th><th>Opened</th><th>Last sent</th><th>Next</th></tr>
      <?php foreach ($leads as $l): ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= (int)$l['id'] ?>"></td>
          <td><a href="<?= url('contact', ['id' => $l['contact_id']]) ?>"><?= e(trim($l['first_name'] . ' ' . $l['last_name']) ?: $l['email']) ?></a><div class="small muted"><?= e($l['company_name']) ?> · <?= e($l['email']) ?></div></td>
          <td><?= status_badge($l['status']) ?><?php if ($l['note']): ?><div class="small muted" title="<?= e($l['note']) ?>"><?= e(mb_substr($l['note'], 0, 40)) ?></div><?php endif; ?></td>
          <td><?= (int)$l['current_step'] ?>/<?= count($steps) ?></td>
          <td class="small"><?= e($l['acc_email'] ?? '—') ?></td>
          <td class="small"><?= $l['opened'] ? '<span class="c-ok">✓</span> ' . (int)$l['opens'] . 'x' : '—' ?><?= $l['clicked'] ? ' · clicked' : '' ?></td>
          <td class="small muted"><?= e(time_ago($l['last_sent_at'])) ?></td>
          <td class="small muted"><?= in_array($l['status'], ['pending', 'active'], true) ? e($l['next_send_at'] ? local_dt($l['next_send_at'], 'M j g:i A') : 'next in queue') : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </form>
  <?= pager_html($leadTotal, $lim, $page) ?>
</div>
<?php layout_end();
