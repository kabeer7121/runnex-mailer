<?php
declare(strict_types=1);

$db = db();
if (is_post()) {
    csrf_check();
    $c = $db->row('SELECT * FROM campaigns WHERE id = ?', [(int)post('id')]);
    if (!$c || !Auth::canRow($c)) {
        flash('error', 'Campaign not found.');
        redirect('campaigns');
    }
    switch (post('action')) {
        case 'pause':
            $db->update('campaigns', ['status' => 'paused'], 'id = ?', [$c['id']]);
            flash('success', 'Campaign paused.');
            break;
        case 'resume':
            $db->update('campaigns', ['status' => 'running', 'started_at' => $c['started_at'] ?: now(), 'auto_note' => null], 'id = ?', [$c['id']]);
            flash('success', 'Campaign resumed.');
            break;
        case 'duplicate':
            $copy = $c;
            unset($copy['id']);
            $copy = array_merge($copy, ['name' => $c['name'] . ' (copy)', 'status' => 'draft', 'user_id' => Auth::id(), 'auto_note' => null, 'started_at' => null, 'completed_at' => null, 'scheduled_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            $nid = $db->insert('campaigns', $copy);
            foreach ($db->all('SELECT * FROM campaign_steps WHERE campaign_id = ?', [$c['id']]) as $s) {
                unset($s['id']);
                $s['campaign_id'] = $nid;
                $db->insert('campaign_steps', $s);
            }
            foreach ($db->col('SELECT account_id FROM campaign_accounts WHERE campaign_id = ?', [$c['id']]) as $aid) {
                $db->insert('campaign_accounts', ['campaign_id' => $nid, 'account_id' => $aid]);
            }
            flash('success', 'Campaign duplicated.');
            redirect('campaign_edit', ['id' => $nid]);
        case 'delete':
            foreach (['campaign_steps', 'campaign_accounts', 'campaign_leads'] as $t) $db->exec("DELETE FROM $t WHERE campaign_id = ?", [$c['id']]);
            $db->exec('DELETE FROM campaigns WHERE id = ?', [$c['id']]);
            log_activity('campaign.delete', $c['name']);
            flash('success', 'Campaign deleted. Sent-email stats are kept in reports.');
            break;
    }
    back('campaigns');
}

[$cs, $cp] = Auth::scope('c.company_id', 'c.user_id');
$status = get('status');
if ($status !== '') {
    $cs .= ' AND c.status = ?';
    $cp[] = $status;
}
$rows = $db->all("SELECT c.*, u.name AS owner,
    (SELECT COUNT(*) FROM campaign_leads l WHERE l.campaign_id = c.id) AS leads,
    (SELECT COUNT(*) FROM campaign_leads l WHERE l.campaign_id = c.id AND l.status IN ('pending','active','sending')) AS queued
    FROM campaigns c LEFT JOIN users u ON u.id = c.user_id WHERE 1=1 $cs ORDER BY c.created_at DESC", $cp);

layout_start('Campaigns', 'campaigns', '<a class="btn btn-primary" href="' . url('campaign_edit') . '">' . icon('plus') . ' New campaign</a>');
?>
<div class="card">
  <div class="tabs">
    <?php foreach (['' => 'All', 'running' => 'Running', 'scheduled' => 'Scheduled', 'paused' => 'Paused', 'draft' => 'Drafts', 'completed' => 'Completed'] as $k => $l): ?>
      <a href="<?= url('campaigns', $k ? ['status' => $k] : []) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?>
    <?= empty_state('No campaigns', 'A campaign sends a sequence of personalised emails (with automatic follow-ups) to a list of carriers, rotating across your email accounts.', '<a class="btn btn-primary" href="' . url('campaign_edit') . '">Create campaign</a>') ?>
  <?php else: ?>
  <div class="table-scroll">
  <table class="table">
    <tr><th>Campaign</th><th>Status</th><th class="right">Leads</th><th class="right">Sent</th><th class="right">Opened</th><th class="right">Clicked</th><th class="right">Replied</th><th class="right">Bounced</th><th></th></tr>
    <?php foreach ($rows as $c): $s = Stats::summary(' AND m.campaign_id = ?', [$c['id']]); ?>
      <tr>
        <td><a href="<?= url($c['status'] === 'draft' ? 'campaign_edit' : 'campaign_view', ['id' => $c['id']]) ?>"><b><?= e($c['name']) ?></b></a>
          <div class="small muted"><?= Auth::isAdmin() ? e($c['owner']) . ' · ' : '' ?>created <?= e(local_dt($c['created_at'], 'M j')) ?><?= $c['status'] === 'scheduled' && $c['scheduled_at'] ? ' · starts ' . e(local_dt($c['scheduled_at'])) : '' ?></div></td>
        <td><?= status_badge($c['status']) ?></td>
        <td class="right"><?= num($c['leads']) ?><div class="small muted"><?= num($c['queued']) ?> in queue</div></td>
        <td class="right"><?= num($s['sent']) ?></td>
        <td class="right"><?= $s['open_rate'] ?>%<div class="small muted"><?= num($s['opened']) ?></div></td>
        <td class="right"><?= $s['click_rate'] ?>%<div class="small muted"><?= num($s['clicked']) ?></div></td>
        <td class="right"><b class="c-ok"><?= $s['reply_rate'] ?>%</b><div class="small muted"><?= num($s['replied']) ?></div></td>
        <td class="right"><?= $s['bounce_rate'] ?>%</td>
        <td class="nowrap right">
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <?php if ($c['status'] === 'running' || $c['status'] === 'scheduled'): ?><button class="btn btn-sm" name="action" value="pause">Pause</button>
            <?php elseif ($c['status'] === 'paused'): ?><button class="btn btn-sm" name="action" value="resume">Resume</button><?php endif; ?>
            <a class="btn btn-sm" href="<?= url('campaign_edit', ['id' => $c['id']]) ?>">Edit</a>
            <button class="btn btn-sm" name="action" value="duplicate">Copy</button>
            <button class="btn btn-sm btn-danger" name="action" value="delete" onclick="return confirm('Delete this campaign?')">✕</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php layout_end();
