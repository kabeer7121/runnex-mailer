<?php
declare(strict_types=1);

$db = db();
[$cs, $cp] = Auth::scope('c.company_id', null);

if (is_post()) {
    csrf_check();
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $action = post('action');
    if ($ids) {
        // keep only contacts the user can access
        $ids = array_map('intval', $db->col('SELECT c.id FROM contacts c WHERE c.id IN (' . DB::in($ids) . ") $cs", array_merge($ids, $cp)));
    }
    if (!$ids) {
        flash('warn', 'Select at least one contact.');
        back('contacts');
    }
    $in = DB::in($ids);
    switch ($action) {
        case 'add_list':
            $list = $db->row('SELECT * FROM lists WHERE id = ?', [(int)post('list_id')]);
            if ($list && Auth::canCompany((int)$list['company_id'])) {
                $n = 0;
                foreach ($db->col("SELECT id FROM contacts WHERE id IN ($in) AND company_id = ?", array_merge($ids, [$list['company_id']])) as $cid) {
                    $n += $db->insertIgnore('list_contacts', ['list_id' => $list['id'], 'contact_id' => $cid]) ? 1 : 0;
                }
                flash('success', "$n contact(s) added to {$list['name']}.");
            }
            break;
        case 'remove_list':
            $db->exec("DELETE FROM list_contacts WHERE list_id = ? AND contact_id IN ($in)", array_merge([(int)post('list_id')], $ids));
            flash('success', 'Removed from list.');
            break;
        case 'label':
            $label = array_key_exists(post('label'), lead_labels()) ? post('label') : null;
            $db->exec("UPDATE contacts SET label = ? WHERE id IN ($in)", array_merge([$label], $ids));
            flash('success', 'Label updated.');
            break;
        case 'unsubscribe':
            foreach ($db->all("SELECT id, email, company_id FROM contacts WHERE id IN ($in)", $ids) as $c) {
                suppress($c['email'], (int)$c['company_id'], 'manual');
            }
            $db->exec("UPDATE contacts SET status = 'unsubscribed' WHERE id IN ($in)", $ids);
            $db->exec("UPDATE campaign_leads SET status = 'stopped', next_send_at = NULL WHERE contact_id IN ($in) AND status IN ('pending','active')", $ids);
            flash('success', 'Contacts unsubscribed and added to the suppression list.');
            break;
        case 'verify':
            @set_time_limit(120);
            $res = ['valid' => 0, 'risky' => 0, 'invalid' => 0];
            foreach ($db->all("SELECT * FROM contacts WHERE id IN ($in)", $ids) as $ct) {
                $r = Verifier::verifyContact(array_merge($ct, ['verified_at' => null]));
                if (isset($res[$r['status']])) $res[$r['status']]++;
            }
            flash('success', "Verified: {$res['valid']} valid, {$res['risky']} risky, {$res['invalid']} invalid.");
            break;
        case 'delete':
            $db->exec("DELETE FROM list_contacts WHERE contact_id IN ($in)", $ids);
            $db->exec("DELETE FROM campaign_leads WHERE contact_id IN ($in) AND status IN ('pending')", $ids);
            $db->exec("UPDATE campaign_leads SET status = 'stopped', next_send_at = NULL WHERE contact_id IN ($in) AND status = 'active'", $ids);
            $db->exec("DELETE FROM contacts WHERE id IN ($in)", $ids);
            log_activity('contacts.delete', count($ids) . ' contacts');
            flash('success', count($ids) . ' contact(s) deleted.');
            break;
    }
    back('contacts');
}

$where = $cs;
$params = $cp;
$join = '';
$q = get('q');
if ($q !== '') {
    $where .= ' AND (c.email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ? OR c.mc_number LIKE ? OR c.dot_number LIKE ? OR c.phone LIKE ?)';
    array_push($params, ...array_fill(0, 7, "%$q%"));
}
$listId = (int)get('list');
if ($listId) {
    $join = ' JOIN list_contacts lc ON lc.contact_id = c.id AND lc.list_id = ' . $listId;
}
if (get('verify') !== '') {
    if (get('verify') === 'unverified') $where .= ' AND c.verified_at IS NULL';
    else {
        $where .= ' AND c.verify_status = ?';
        $params[] = get('verify');
    }
}
foreach (['status' => 'c.status', 'state' => 'c.state', 'label' => 'c.label', 'equipment' => 'c.equipment_type'] as $k => $col) {
    if (get($k) !== '') {
        $where .= " AND $col = ?";
        $params[] = get($k);
    }
}

if (get('export') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="carriers-' . local_today() . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $cols = array_keys(contact_fields());
    fputcsv($out, array_merge($cols, ['status', 'label', 'last_contacted', 'custom']), ',', '"', '\\');
    $st = $db->q("SELECT c.* FROM contacts c $join WHERE 1=1 $where ORDER BY c.id", $params);
    while ($r = $st->fetch()) {
        $line = [];
        foreach ($cols as $col) $line[] = $r[$col];
        fputcsv($out, array_merge($line, [$r['status'], $r['label'], $r['last_contacted_at'], $r['custom']]), ',', '"', '\\');
    }
    exit;
}

[$off, $lim, $page] = paging(50);
$total = (int)$db->val("SELECT COUNT(*) FROM contacts c $join WHERE 1=1 $where", $params);
$rows = $db->all("SELECT c.* FROM contacts c $join WHERE 1=1 $where ORDER BY c.id DESC LIMIT $lim OFFSET $off", $params);
[$ls, $lp] = Auth::scope('company_id', null);
$lists = $db->all("SELECT id, name FROM lists WHERE 1=1 $ls ORDER BY name", $lp);
$states = $db->col("SELECT DISTINCT c.state FROM contacts c WHERE c.state IS NOT NULL AND c.state <> '' $cs ORDER BY c.state", $cp);
$labels = lead_labels();

layout_start('Carriers / Contacts', 'contacts',
    '<a class="btn" href="?' . e(query_without_p(['export' => 1])) . '">Export CSV</a> <a class="btn" href="' . url('import') . '">' . icon('upload') . ' Import sheet</a> <a class="btn btn-primary" href="' . url('contact') . '">' . icon('plus') . ' Add carrier</a>');
?>
<div class="card">
  <form class="toolbar wrap" method="get">
    <input type="hidden" name="p" value="contacts">
    <input name="q" value="<?= e($q) ?>" placeholder="Search name, email, MC, DOT, phone…" class="grow">
    <select name="list"><option value="">All lists</option><?php foreach ($lists as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $listId === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
    <select name="status"><option value="">Any status</option><?php foreach (['active', 'unsubscribed', 'bounced'] as $s): ?><option <?= get('status') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <select name="state"><option value="">Any state</option><?php foreach ($states as $s): ?><option <?= get('state') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
    <select name="verify"><option value="">Any verification</option><?php foreach (Verifier::statuses() as $k => $l): ?><option value="<?= $k ?>" <?= get('verify') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select name="label"><option value="">Any label</option><?php foreach ($labels as $k => $l): ?><option value="<?= $k ?>" <?= get('label') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn">Filter</button>
  </form>
  <?php if (!$rows): ?>
    <?= empty_state('No carriers found', 'Import your carrier sheet (CSV, Excel or Google Sheet) or add carriers one by one.', '<a class="btn btn-primary" href="' . url('import') . '">Import sheet</a>') ?>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="bulkbar">
      <select name="action" data-bulk-action required>
        <option value="">Bulk action…</option>
        <option value="add_list">Add to list</option>
        <option value="remove_list">Remove from list</option>
        <option value="label">Set label</option>
        <option value="verify">Verify emails now</option>
        <option value="unsubscribe">Unsubscribe / suppress</option>
        <option value="delete">Delete</option>
      </select>
      <select name="list_id" data-show-for="add_list remove_list"><?php foreach ($lists as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select>
      <select name="label" data-show-for="label"><option value="">(no label)</option><?php foreach ($labels as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
      <button class="btn" onclick="return this.form.action.value!=='delete'||confirm('Delete selected contacts?')">Apply</button>
    </div>
    <div class="table-scroll">
    <table class="table">
      <tr><th><input type="checkbox" data-checkall></th><th>Contact</th><th>Carrier</th><th>MC / DOT</th><th>Location</th><th>Equipment</th><th>Email check</th><th>Status</th><th>Last emailed</th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
          <td><a href="<?= url('contact', ['id' => $r['id']]) ?>"><b><?= e(trim($r['first_name'] . ' ' . $r['last_name']) ?: '—') ?></b></a><div class="small muted"><?= e($r['email']) ?></div></td>
          <td><?= e($r['company_name']) ?><?php if ($r['label']): ?> <span class="badge b-info"><?= e($labels[$r['label']] ?? $r['label']) ?></span><?php endif; ?></td>
          <td class="small"><?= $r['mc_number'] ? 'MC ' . e($r['mc_number']) : '' ?><?= $r['dot_number'] ? '<br>DOT ' . e($r['dot_number']) : '' ?></td>
          <td class="small"><?= e(trim($r['city'] . ($r['city'] && $r['state'] ? ', ' : '') . $r['state'])) ?></td>
          <td class="small"><?= e($r['equipment_type']) ?><?= $r['truck_count'] ? ' · ' . e($r['truck_count']) . ' trucks' : '' ?></td>
          <td><?= Verifier::badge($r['verify_status'] ?? null, $r['verify_reason'] ?? null) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="small muted"><?= e(time_ago($r['last_contacted_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </form>
  <?= pager_html($total, $lim, $page) ?>
  <?php endif; ?>
</div>
<?php layout_end();
