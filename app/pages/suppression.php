<?php
declare(strict_types=1);

$db = db();
$ids = Auth::scopeIds();

if (is_post()) {
    csrf_check();
    if (post('action') === 'add') {
        $companyId = (int)post('company_id');
        if ($companyId === 0 && !Auth::isAdmin()) $companyId = -1;
        if ($companyId !== 0 && !Auth::canCompany($companyId)) {
            flash('error', 'Choose a company.');
            redirect('suppression');
        }
        $n = 0;
        foreach (preg_split('/[\s,;]+/', (string)($_POST['emails'] ?? '')) as $em) {
            $em = strtolower(trim($em));
            if (!valid_email($em)) continue;
            suppress($em, $companyId, 'manual');
            $cids = $companyId ? [$companyId] : Auth::companyIds();
            if ($cids) {
                $db->exec("UPDATE contacts SET status = 'unsubscribed' WHERE email = ? AND company_id IN (" . DB::in($cids) . ')', array_merge([$em], $cids));
                $db->exec("UPDATE campaign_leads SET status = 'stopped', next_send_at = NULL WHERE status IN ('pending','active') AND contact_id IN (SELECT id FROM contacts WHERE email = ? AND company_id IN (" . DB::in($cids) . '))', array_merge([$em], $cids));
            }
            $n++;
        }
        flash('success', "$n email(s) added to the suppression list.");
    } elseif (post('action') === 'remove') {
        $row = $db->row('SELECT * FROM suppression WHERE id = ?', [(int)post('id')]);
        if ($row && ((int)$row['company_id'] === 0 ? Auth::isAdmin() : Auth::canCompany((int)$row['company_id']))) {
            $db->exec('DELETE FROM suppression WHERE id = ?', [$row['id']]);
            flash('success', 'Removed. (The contact stays unsubscribed until you re-activate it on the contact page.)');
        }
    }
    redirect('suppression');
}

$where = 's.company_id IN (' . DB::in(array_merge([0], $ids)) . ')';
$params = array_merge([0], $ids);
if (get('q') !== '') {
    $where .= ' AND s.email LIKE ?';
    $params[] = '%' . get('q') . '%';
}
if (get('reason') !== '') {
    $where .= ' AND s.reason = ?';
    $params[] = get('reason');
}
[$off, $lim, $page] = paging(50);
$total = (int)$db->val("SELECT COUNT(*) FROM suppression s WHERE $where", $params);
$rows = $db->all("SELECT s.*, c.name AS company FROM suppression s LEFT JOIN companies c ON c.id = s.company_id WHERE $where ORDER BY s.created_at DESC LIMIT $lim OFFSET $off", $params);

layout_start('Suppression List', 'suppression');
?>
<div class="grid-2-1">
<div class="card">
  <p class="muted small">Addresses here are never emailed again: unsubscribes, hard bounces, "remove me" replies and anything you add manually.</p>
  <form class="toolbar" method="get"><input type="hidden" name="p" value="suppression"><input name="q" value="<?= e(get('q')) ?>" placeholder="Search email…">
    <select name="reason"><option value="">Any reason</option><?php foreach (['unsubscribe', 'bounce', 'manual'] as $r): ?><option <?= get('reason') === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select><button class="btn">Filter</button></form>
  <table class="table">
    <tr><th>Email</th><th>Reason</th><th>Scope</th><th>Added</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['email']) ?></td><td><?= status_badge($r['reason'] === 'bounce' ? 'bounced' : ($r['reason'] === 'unsubscribe' ? 'unsubscribed' : 'stopped')) ?></td>
        <td class="small"><?= (int)$r['company_id'] === 0 ? 'All companies' : e($r['company']) ?></td><td class="small muted"><?= e(local_dt($r['created_at'], 'M j, Y')) ?></td>
        <td class="right"><form method="post" class="inline" onsubmit="return confirm('Remove from suppression list?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm" name="action" value="remove">Remove</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Nothing here yet.</td></tr><?php endif; ?>
  </table>
  <?= pager_html($total, $lim, $page) ?>
</div>
<div class="card">
  <h2>Add addresses</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label>Scope<select name="company_id">
      <?php if (Auth::isAdmin()): ?><option value="0">All companies (global)</option><?php endif; ?>
      <?php foreach (Auth::companies() as $c): ?><option value="<?= (int)$c['id'] ?>" <?= Auth::currentCompany() === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select></label>
    <label>Emails (one per line or comma separated)<textarea name="emails" rows="8" required></textarea></label>
    <button class="btn btn-primary">Add to suppression list</button>
  </form>
</div>
</div>
<?php layout_end();
