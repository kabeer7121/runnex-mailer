<?php
declare(strict_types=1);

$db = db();

if (is_post()) {
    csrf_check();
    $ids = array_map('intval', (array)($_POST['ids'] ?? [post('id')]));
    $action = post('action');
    $done = 0;
    foreach ($ids as $id) {
        $acc = $db->row('SELECT * FROM email_accounts WHERE id = ?', [$id]);
        if (!$acc || !Auth::canRow($acc)) continue;
        switch ($action) {
            case 'pause':
                $db->update('email_accounts', ['status' => 'paused'], 'id = ?', [$id]);
                break;
            case 'resume':
                $db->update('email_accounts', ['status' => 'active', 'last_error' => null, 'next_send_at' => null], 'id = ?', [$id]);
                break;
            case 'warmup_on':
                $db->update('email_accounts', ['warmup_enabled' => 1, 'warmup_started_at' => $acc['warmup_started_at'] ?: now()], 'id = ?', [$id]);
                break;
            case 'warmup_off':
                $db->update('email_accounts', ['warmup_enabled' => 0], 'id = ?', [$id]);
                break;
            case 'apply_suggested':
                $hd = json_decode((string)$acc['health_data'], true) ?: [];
                $sug = (int)($hd['suggested_limit'] ?? Health::suggestedLimit($acc, $acc['health_status'], Health::warmDays($acc)));
                $db->update('email_accounts', ['daily_limit' => max(1, $sug)], 'id = ?', [$id]);
                break;
            case 'test':
                $r = Mailbox::testConnection($acc);
                if ($r['ok']) {
                    $db->update('email_accounts', ['status' => $acc['status'] === 'error' ? 'active' : $acc['status'], 'last_error' => null], 'id = ?', [$id]);
                    flash('success', $acc['email'] . ': SMTP and IMAP connection OK.');
                } else {
                    $db->update('email_accounts', ['status' => 'error', 'last_error' => 'SMTP: ' . $r['smtp'] . ' | IMAP: ' . $r['imap']], 'id = ?', [$id]);
                    flash('error', $acc['email'] . ' – SMTP: ' . $r['smtp'] . ' | IMAP: ' . $r['imap']);
                }
                Health::compute($db->row('SELECT * FROM email_accounts WHERE id = ?', [$id]));
                break;
            case 'check_now':
                try {
                    $sum = Mailbox::check($acc);
                    flash('success', sprintf('%s checked: %d warmup in inbox, %d rescued from spam, %d replies, %d bounces.', $acc['email'], $sum['inbox'], $sum['spam'], $sum['replies'], $sum['bounces']));
                } catch (Throwable $e) {
                    flash('error', $acc['email'] . ': ' . $e->getMessage());
                }
                Health::compute($db->row('SELECT * FROM email_accounts WHERE id = ?', [$id]));
                break;
            case 'delete':
                $db->exec('DELETE FROM email_accounts WHERE id = ?', [$id]);
                $db->exec('DELETE FROM campaign_accounts WHERE account_id = ?', [$id]);
                $db->exec("UPDATE campaign_leads SET account_id = NULL WHERE account_id = ? AND status = 'pending'", [$id]);
                $db->exec("UPDATE campaign_leads SET status = 'stopped', note = 'Sending account deleted' WHERE account_id = ? AND status = 'active'", [$id]);
                log_activity('account.delete', $acc['email']);
                break;
            default:
                continue 2;
        }
        $done++;
    }
    if ($done && !in_array($action, ['test', 'check_now'], true)) flash('success', "Updated $done account(s).");
    back('accounts');
}

[$as, $ap] = Auth::scope('a.company_id', 'a.user_id');
$q = get('q');
$where = $as;
$params = $ap;
if ($q !== '') {
    $where .= ' AND (a.email LIKE ? OR a.from_name LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$status = get('status');
if ($status === 'problem') $where .= " AND (a.status = 'error' OR a.send_paused_reason IS NOT NULL OR a.health_status IN ('critical','at_risk'))";
$accounts = $db->all("SELECT a.*, u.name AS owner, c.name AS company FROM email_accounts a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN companies c ON c.id = a.company_id WHERE 1=1 $where ORDER BY a.email", $params);

layout_start('Email Accounts', 'accounts', '<a class="btn" href="' . url('account_edit', ['bulk' => 1]) . '">Bulk connect</a> <a class="btn btn-primary" href="' . url('account_edit') . '">' . icon('plus') . ' Connect Gmail</a>');
?>
<div class="card">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="accounts">
    <input name="q" value="<?= e($q) ?>" placeholder="Search email…">
    <select name="status"><option value="">All accounts</option><option value="problem" <?= $status === 'problem' ? 'selected' : '' ?>>Needs attention</option></select>
    <button class="btn">Filter</button>
    <span class="muted right"><?= count($accounts) ?> accounts</span>
  </form>
  <?php if (!$accounts): ?>
    <?= empty_state('No email accounts connected', 'Connect Gmail / Google Workspace accounts with an App Password. Connect as many as you want – each one adds sending capacity and joins the warmup network.', '<a class="btn btn-primary" href="' . url('account_edit') . '">Connect Gmail</a>') ?>
  <?php else: ?>
  <form method="post" id="bulkform">
    <?= csrf_field() ?>
    <div class="bulkbar">
      <select name="action" required>
        <option value="">Bulk action…</option>
        <option value="resume">Resume sending</option><option value="pause">Pause sending</option>
        <option value="warmup_on">Warmup ON</option><option value="warmup_off">Warmup OFF</option>
        <option value="apply_suggested">Apply suggested daily limit</option>
        <option value="test">Test connection</option>
        <option value="delete">Delete</option>
      </select>
      <button class="btn" onclick="return this.form.action.value!=='delete'||confirm('Delete selected accounts?')">Apply</button>
    </div>
    <div class="table-scroll">
    <table class="table">
      <tr><th><input type="checkbox" data-checkall></th><th>Account</th><?php if (Auth::isAdmin()): ?><th>Owner</th><?php endif; ?><th>Health</th><th>Warmup</th><th>Sent today</th><th>Daily limit</th><th>Status</th><th></th></tr>
      <?php foreach ($accounts as $a):
          $hd = json_decode((string)$a['health_data'], true) ?: [];
          $eff = Health::effectiveLimit($a);
          $target = Health::warmupTarget($a);
          ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= (int)$a['id'] ?>"></td>
          <td><a href="<?= url('account_view', ['id' => $a['id']]) ?>"><b><?= e($a['email']) ?></b></a><div class="muted small"><?= e($a['from_name']) ?> · <?= e($a['company']) ?></div><?php if ($a['last_error'] && $a['status'] === 'error'): ?><div class="small c-bad" title="<?= e($a['last_error']) ?>"><?= e(mb_strimwidth($a['last_error'], 0, 110, '…')) ?></div><?php endif; ?></td>
          <?php if (Auth::isAdmin()): ?><td><?= e($a['owner']) ?></td><?php endif; ?>
          <td><?= health_badge($a['status'] === 'error' ? 'error' : $a['health_status'], (int)$a['health_score']) ?>
            <?php if (isset($hd['inbox_rate']) && $hd['inbox_rate'] !== null): ?><div class="muted small">Inbox <?= $hd['inbox_rate'] ?>% · Spam <?= $hd['spam_rate'] ?>%</div><?php endif; ?></td>
          <td><?= (int)$a['warmup_enabled'] ? '<span class="badge b-ok">On</span> <span class="muted small">' . (int)$a['warmup_sent_today'] . '/' . $target . '</span>' : '<span class="badge b-muted">Off</span>' ?></td>
          <td><?= (int)$a['sent_today'] ?> / <?= $eff ?></td>
          <td><?= (int)$a['daily_limit'] ?><?php if ((int)$a['auto_limit']): ?><div class="muted small">Suggested <?= (int)($hd['suggested_limit'] ?? 0) ?> (auto)</div><?php endif; ?></td>
          <td><?= status_badge($a['status']) ?>
            <?php if ($a['send_paused_reason']): ?><div class="small c-bad"><?= e($a['send_paused_reason']) ?></div><?php endif; ?>
</td>
          <td class="nowrap">
            <a class="btn btn-sm" href="<?= url('account_edit', ['id' => $a['id']]) ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </form>
  <?php endif; ?>
</div>
<div class="card tip">
  <b>Deliverability rules Runnex enforces automatically:</b>
  new accounts send max 5 campaign emails/day in week 1, ramping to 40–50/day after a month; accounts landing in spam or with &gt;8% bounces are auto-paused while warmup repairs them;
  every account stays far below Google's hard limits (500/day Gmail, 2,000/day Workspace). For more volume, connect more accounts instead of raising limits.
</div>
<?php layout_end();
