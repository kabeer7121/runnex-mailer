<?php
declare(strict_types=1);

$db = db();
$id = (int)get('id');
$c = $id ? $db->row('SELECT * FROM contacts WHERE id = ?', [$id]) : null;
if ($id && (!$c || !Auth::canCompany((int)$c['company_id']))) {
    flash('error', 'Contact not found.');
    redirect('contacts');
}
$fields = contact_fields();

if (is_post() && $c && post('action') === 'verify') {
    csrf_check();
    $r = Verifier::verifyContact(array_merge($c, ['verified_at' => null]));
    flash($r['status'] === 'invalid' ? 'error' : 'success', 'Email check: ' . strtoupper($r['status']) . ' – ' . $r['reason']);
    redirect('contact', ['id' => $id]);
}
if (is_post()) {
    csrf_check();
    $companyId = $c ? (int)$c['company_id'] : (int)post('company_id');
    if (!Auth::canCompany($companyId)) {
        flash('error', 'Choose a company.');
        back('contacts');
    }
    $data = [];
    foreach ($fields as $k => $label) {
        $data[$k] = post($k) === '' ? null : mb_substr((string)post($k), 0, $k === 'notes' ? 5000 : 190);
    }
    $data['email'] = strtolower((string)$data['email']);
    if (!valid_email($data['email'])) {
        flash('error', 'Enter a valid email.');
        back('contacts');
    }
    $data['label'] = array_key_exists(post('label'), lead_labels()) ? post('label') : null;
    $custom = [];
    foreach ((array)($_POST['custom_k'] ?? []) as $i => $k) {
        $k = trim((string)$k);
        if ($k !== '') $custom[$k] = trim((string)($_POST['custom_v'][$i] ?? ''));
    }
    $data['custom'] = $custom ? json_encode($custom) : null;
    $dupe = $db->val('SELECT id FROM contacts WHERE company_id = ? AND email = ? AND id <> ?', [$companyId, $data['email'], $id]);
    if ($dupe) {
        flash('error', 'Another contact already uses this email.');
        back('contacts');
    }
    if ($c) {
        $data['updated_at'] = now();
        if ($data['email'] !== $c['email']) $data += ['verified_at' => null, 'verify_status' => null, 'verify_reason' => null, 'verify_suggestion' => null];
        $db->update('contacts', $data, 'id = ?', [$id]);
    } else {
        $data += ['company_id' => $companyId, 'created_by' => Auth::id(), 'created_at' => now(), 'status' => 'active'];
        $id = $db->insert('contacts', $data);
    }
    foreach ((array)($_POST['lists'] ?? []) as $lid) {
        $l = $db->row('SELECT * FROM lists WHERE id = ? AND company_id = ?', [(int)$lid, $companyId]);
        if ($l) $db->insertIgnore('list_contacts', ['list_id' => $l['id'], 'contact_id' => $id]);
    }
    if (post('resubscribe') === '1' && $c && $c['status'] !== 'active') {
        $db->exec("UPDATE contacts SET status = 'active' WHERE id = ?", [$id]);
        $db->exec('DELETE FROM suppression WHERE email = ? AND company_id = ?', [$data['email'], $companyId]);
    }
    flash('success', 'Contact saved.');
    redirect('contact', ['id' => $id]);
}

$companyId = (int)($c['company_id'] ?? Auth::currentCompany() ?: (Auth::companyIds()[0] ?? 0));
$lists = $db->all('SELECT id, name FROM lists WHERE company_id = ? ORDER BY name', [$companyId]);
$inLists = $c ? array_map('intval', $db->col('SELECT list_id FROM list_contacts WHERE contact_id = ?', [$id])) : [];
$custom = $c ? (json_decode((string)$c['custom'], true) ?: []) : [];
$timeline = [];
if ($c) {
    foreach ($db->all('SELECT m.*, a.email AS from_email, cp.name AS campaign FROM messages m LEFT JOIN email_accounts a ON a.id = m.account_id LEFT JOIN campaigns cp ON cp.id = m.campaign_id WHERE m.contact_id = ? ORDER BY m.sent_at DESC LIMIT 50', [$id]) as $m) {
        $timeline[] = ['t' => $m['sent_at'], 'type' => $m['status'] === 'bounced' ? 'bounce' : 'sent', 'text' => 'Step ' . ((int)$m['step_no'] + 1) . ' of "' . ($m['campaign'] ?? 'campaign') . '" from ' . $m['from_email'] . ($m['subject'] ? ': ' . $m['subject'] : '')];
        if ($m['opened_at']) $timeline[] = ['t' => $m['opened_at'], 'type' => 'open', 'text' => 'Opened ' . (int)$m['open_count'] . 'x'];
        if ($m['clicked_at']) $timeline[] = ['t' => $m['clicked_at'], 'type' => 'click', 'text' => 'Clicked a link ' . (int)$m['click_count'] . 'x'];
        if ($m['unsubscribed_at']) $timeline[] = ['t' => $m['unsubscribed_at'], 'type' => 'unsub', 'text' => 'Unsubscribed'];
    }
    foreach ($db->all("SELECT * FROM replies WHERE contact_id = ? ORDER BY received_at DESC LIMIT 30", [$id]) as $r) {
        $timeline[] = ['t' => $r['received_at'], 'type' => $r['direction'] === 'in' ? 'reply' : 'sent', 'text' => ($r['direction'] === 'in' ? ($r['is_auto'] ? 'Auto-reply: ' : 'Replied: ') : 'You replied: ') . mb_substr((string)$r['body_text'], 0, 160), 'rid' => $r['id']];
    }
    usort($timeline, fn($a, $b) => strcmp($b['t'], $a['t']));
}

layout_start($c ? (trim($c['first_name'] . ' ' . $c['last_name']) ?: $c['email']) : 'Add carrier', 'contacts');
?>
<div class="grid-2-1">
<div class="card">
  <?php if ($c && $c['status'] !== 'active'): ?><div class="alert alert-warn">This contact is <b><?= e($c['status']) ?></b> and will not receive emails.</div><?php endif; ?>
  <?php if ($c): ?>
    <div class="vresult v-<?= e($c['verify_status'] ?: 'unverified') ?>" style="margin-bottom:16px">
      <div><?= Verifier::badge($c['verify_status']) ?> <b>Email check</b> <span class="muted small"><?= $c['verified_at'] ? 'checked ' . e(time_ago($c['verified_at'])) : 'not checked yet' ?></span>
        <form method="post" class="inline" style="float:right"><?= csrf_field() ?><button class="btn btn-sm" name="action" value="verify">Verify now</button></form></div>
      <?php if ($c['verify_reason']): ?><div class="small"><?= e($c['verify_reason']) ?><?= $c['verify_suggestion'] ? ' · suggestion: <b>' . e($c['verify_suggestion']) . '</b>' : '' ?></div><?php endif; ?>
    </div>
  <?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <?php if (!$c): ?><?= company_select('company_id', $companyId) ?><?php endif; ?>
    <div class="grid2">
      <?php foreach ($fields as $k => $label): if ($k === 'notes') continue; ?>
        <label><?= e($label) ?><input name="<?= $k ?>" value="<?= e($c[$k] ?? '') ?>" <?= $k === 'email' ? 'type="email" required' : '' ?>></label>
      <?php endforeach; ?>
      <label>Label<select name="label"><option value="">—</option><?php foreach (lead_labels() as $k => $l): ?><option value="<?= $k ?>" <?= ($c['label'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <label>Notes<textarea name="notes" rows="3"><?= e($c['notes'] ?? '') ?></textarea></label>
    <h3>Custom fields <span class="muted small">use as {{field_name}} in emails</span></h3>
    <div data-kv>
      <?php foreach ($custom + ['' => ''] as $k => $v): ?>
        <div class="kv-row"><input name="custom_k[]" value="<?= e($k) ?>" placeholder="Field name"><input name="custom_v[]" value="<?= e($v) ?>" placeholder="Value"></div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm" data-kv-add>+ Add field</button>
    <?php if ($lists): ?>
      <h3>Lists</h3>
      <div class="checks"><?php foreach ($lists as $l): ?><label class="check"><input type="checkbox" name="lists[]" value="<?= (int)$l['id'] ?>" <?= in_array((int)$l['id'], $inLists, true) ? 'checked disabled' : '' ?>> <?= e($l['name']) ?></label><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($c && $c['status'] !== 'active'): ?><label class="check"><input type="checkbox" name="resubscribe" value="1"> Re-activate this contact (only if they asked to hear from you again)</label><?php endif; ?>
    <button class="btn btn-primary">Save</button>
  </form>
</div>
<div class="card">
  <h2>Activity</h2>
  <?php if (!$timeline): ?><p class="muted">No emails yet.</p><?php else: ?>
  <ul class="timeline">
    <?php foreach ($timeline as $t): ?>
      <li class="tl-<?= e($t['type']) ?>"><span class="muted small"><?= e(local_dt($t['t'])) ?></span><div><?php if (!empty($t['rid'])): ?><a href="<?= url('inbox', ['id' => $t['rid']]) ?>"><?= e($t['text']) ?></a><?php else: ?><?= e($t['text']) ?><?php endif; ?></div></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
</div>
<?php layout_end();
