<?php
declare(strict_types=1);

$db = db();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('from')) ? get('from') : local_day_offset(-29);
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('to')) ? get('to') : local_today();
if ($from > $to) [$from, $to] = [$to, $from];

[$ms, $mp] = Auth::scope('m.company_id', 'm.user_id');
$where = ' AND m.sent_day >= ? AND m.sent_day <= ?' . $ms;
$params = array_merge([$from, $to], $mp);
[$es, $ep] = Auth::scope('e.company_id', 'e.user_id');
$ewhere = ' AND e.day >= ? AND e.day <= ?' . $es;
$eparams = array_merge([$from, $to], $ep);
foreach (['campaign' => 'campaign_id', 'account' => 'account_id', 'agent' => 'user_id'] as $k => $col) {
    if ((int)get($k)) {
        $where .= " AND m.$col = ?";
        $params[] = (int)get($k);
        $ewhere .= " AND e.$col = ?";
        $eparams[] = (int)get($k);
    }
}

$s = Stats::summary($where, $params);
// daily() adds its own date filter
$dailyWhere = substr($where, strlen(' AND m.sent_day >= ? AND m.sent_day <= ?'));
$daily = Stats::daily($dailyWhere, array_slice($params, 2), $from, $to);

if (get('export') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="email-report-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['sent_at', 'campaign', 'step', 'from_account', 'to_email', 'carrier', 'subject', 'status', 'opened_at', 'opens', 'clicked_at', 'clicks', 'replied_at', 'bounced_at', 'unsubscribed_at'], ',', '"', '\\');
    $st = $db->q("SELECT m.*, cp.name AS campaign, a.email AS acc, c.company_name FROM messages m LEFT JOIN campaigns cp ON cp.id = m.campaign_id LEFT JOIN email_accounts a ON a.id = m.account_id LEFT JOIN contacts c ON c.id = m.contact_id WHERE m.kind = 'campaign' $where ORDER BY m.sent_at", $params);
    while ($r = $st->fetch()) {
        fputcsv($out, [local_dt($r['sent_at'], 'Y-m-d H:i'), $r['campaign'], (int)$r['step_no'] + 1, $r['acc'], $r['to_email'], $r['company_name'], $r['subject'], $r['status'], $r['opened_at'] ? local_dt($r['opened_at'], 'Y-m-d H:i') : '', $r['open_count'], $r['clicked_at'] ? local_dt($r['clicked_at'], 'Y-m-d H:i') : '', $r['click_count'], $r['replied_at'] ? local_dt($r['replied_at'], 'Y-m-d H:i') : '', $r['bounced_at'] ? local_dt($r['bounced_at'], 'Y-m-d H:i') : '', $r['unsubscribed_at'] ? local_dt($r['unsubscribed_at'], 'Y-m-d H:i') : ''], ',', '"', '\\');
    }
    exit;
}

$byCampaign = Stats::grouped('campaign_id', $where, $params);
$byAccount = Stats::grouped('account_id', $where, $params);
$byAgent = Auth::isAdmin() ? Stats::grouped('user_id', $where, $params) : [];
$byCompany = Auth::isAdmin() && count(Auth::scopeIds()) > 1 ? Stats::grouped('company_id', $where, $params) : [];
$heatOpen = Stats::heatmap('open', $ewhere, $eparams);
$heatReply = Stats::heatmap('reply', $ewhere, $eparams);
$names = ['campaign' => [], 'account' => [], 'user' => [], 'company' => []];
foreach ($db->all('SELECT id, name FROM campaigns') as $r) $names['campaign'][$r['id']] = $r['name'];
foreach ($db->all('SELECT id, email FROM email_accounts') as $r) $names['account'][$r['id']] = $r['email'];
foreach ($db->all('SELECT id, name FROM users') as $r) $names['user'][$r['id']] = $r['name'];
foreach ($db->all('SELECT id, name FROM companies') as $r) $names['company'][$r['id']] = $r['name'];
[$cs, $cp] = Auth::scope('company_id', 'user_id');
$campaignOpts = $db->all("SELECT id, name FROM campaigns WHERE 1=1 $cs ORDER BY created_at DESC", $cp);
$accountOpts = $db->all("SELECT id, email FROM email_accounts WHERE 1=1 $cs ORDER BY email", $cp);
$agentOpts = Auth::isAdmin() ? $db->all("SELECT id, name FROM users ORDER BY name") : [];

$replyLabels = [];
[$rs, $rp] = Auth::scope('c.company_id', null);
foreach ($db->all("SELECT c.label, COUNT(*) AS n FROM contacts c WHERE c.label IS NOT NULL $rs GROUP BY c.label", $rp) as $r) $replyLabels[$r['label']] = (int)$r['n'];

function rx_breakdown(string $title, array $rows, array $names): void
{
    if (!$rows) return;
    echo '<div class="card"><h2>' . e($title) . '</h2><div class="table-scroll"><table class="table"><tr><th>Name</th><th class="right">Sent</th><th class="right">Delivered</th><th class="right">Opened</th><th class="right">Open rate</th><th class="right">Clicked</th><th class="right">Replied</th><th class="right">Reply rate</th><th class="right">Bounce rate</th><th class="right">Unsubs</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td>' . e($names[$r['k']] ?? '(deleted)') . '</td><td class="right">' . num($r['sent']) . '</td><td class="right">' . num((int)$r['sent'] - (int)$r['bounced']) . '</td><td class="right">' . num($r['opened']) . '</td><td class="right">' . $r['open_rate'] . '%</td><td class="right">' . num($r['clicked']) . '</td><td class="right">' . num($r['replied']) . '</td><td class="right"><b>' . $r['reply_rate'] . '%</b></td><td class="right' . ($r['bounce_rate'] > 3 ? ' c-bad' : '') . '">' . $r['bounce_rate'] . '%</td><td class="right">' . num($r['unsub']) . '</td></tr>';
    }
    echo '</table></div></div>';
}

layout_start('Reports & Stats', 'reports', '<a class="btn" href="?' . e(query_without_p(['export' => 1])) . '">Export CSV</a>');
?>
<div class="card">
  <form class="toolbar wrap" method="get">
    <input type="hidden" name="p" value="reports">
    <label class="inline-l">From <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="inline-l">To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <select name="campaign"><option value="">All campaigns</option><?php foreach ($campaignOpts as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)get('campaign') === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select>
    <select name="account"><option value="">All accounts</option><?php foreach ($accountOpts as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)get('account') === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['email']) ?></option><?php endforeach; ?></select>
    <?php if ($agentOpts): ?><select name="agent"><option value="">All agents</option><?php foreach ($agentOpts as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)get('agent') === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn btn-primary">Apply</button>
    <span class="quick">
      <a href="<?= url('reports', ['from' => local_today(), 'to' => local_today()]) ?>">Today</a>
      <a href="<?= url('reports', ['from' => local_day_offset(-6), 'to' => local_today()]) ?>">7d</a>
      <a href="<?= url('reports', ['from' => local_day_offset(-29), 'to' => local_today()]) ?>">30d</a>
      <a href="<?= url('reports', ['from' => local_day_offset(-89), 'to' => local_today()]) ?>">90d</a>
    </span>
  </form>
</div>

<div class="stats-grid">
  <?= stat_card('Sent', num($s['sent'])) ?>
  <?= stat_card('Delivered', num($s['delivered']), $s['delivery_rate'] . '% delivery rate') ?>
  <?= stat_card('Opened / seen', $s['open_rate'] . '%', num($s['opened']) . ' unique · ' . num($s['opens_total']) . ' total views', 'tone-blue') ?>
  <?= stat_card('Clicked', $s['click_rate'] . '%', num($s['clicked']) . ' · CTOR ' . $s['click_to_open'] . '%') ?>
  <?= stat_card('Replied', $s['reply_rate'] . '%', num($s['replied']) . ' replies', 'tone-green') ?>
  <?= stat_card('Bounced', $s['bounce_rate'] . '%', num($s['bounced']), $s['bounce_rate'] > 3 ? 'tone-red' : '') ?>
  <?= stat_card('Unsubscribed', $s['unsub_rate'] . '%', num($s['unsub'])) ?>
  <?= stat_card('Avg. views per opener', (string)$s['avg_opens'], 'how often carriers re-open') ?>
</div>

<div class="card">
  <h2>Daily trend</h2>
  <?= chart('line', $daily['labels'], [
      ['label' => 'Sent', 'data' => $daily['sent'], 'color' => '#6b7280'],
      ['label' => 'Opened', 'data' => $daily['opened'], 'color' => '#2563eb'],
      ['label' => 'Clicked', 'data' => $daily['clicked'], 'color' => '#9333ea'],
      ['label' => 'Replied', 'data' => $daily['replied'], 'color' => '#16a34a'],
      ['label' => 'Bounced', 'data' => $daily['bounced'], 'color' => '#dc2626'],
  ], 300) ?>
</div>

<div class="grid-2">
  <div class="card"><h2>Funnel</h2>
    <?php $fun = ['Sent' => $s['sent'], 'Delivered' => $s['delivered'], 'Opened' => $s['opened'], 'Clicked' => $s['clicked'], 'Replied' => $s['replied']]; ?>
    <div class="funnel"><?php foreach ($fun as $l => $n): ?><div class="fn-row"><span><?= $l ?></span><div class="fn-bar"><i style="width:<?= max(1, pct($n, max(1, $s['sent']))) ?>%"></i></div><b><?= num($n) ?></b><span class="muted small"><?= pct($n, max(1, $s['sent'])) ?>%</span></div><?php endforeach; ?></div>
    <?php if ($replyLabels): ?><h3>Lead outcomes</h3><div class="chips"><?php foreach ($replyLabels as $k => $n): ?><span class="chip"><?= e(lead_labels()[$k] ?? $k) ?>: <b><?= num($n) ?></b></span><?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="card"><h2>Best time – opens</h2><?= Stats::heatmapHtml($heatOpen) ?><h2 style="margin-top:18px">Best time – replies</h2><?= Stats::heatmapHtml($heatReply) ?></div>
</div>

<?php
rx_breakdown('By campaign', $byCampaign, $names['campaign']);
rx_breakdown('By sending account', $byAccount, $names['account']);
rx_breakdown('By agent', $byAgent, $names['user']);
rx_breakdown('By company', $byCompany, $names['company']);
layout_end();
