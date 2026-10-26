<?php
declare(strict_types=1);

Auth::requireAdmin();
$db = db();
$id = (int)get('id');
$co = $id ? $db->row('SELECT * FROM companies WHERE id = ?', [$id]) : null;

if (is_post()) {
    csrf_check();
    if (post('action') === 'delete' && $co) {
        $used = (int)$db->val('SELECT COUNT(*) FROM email_accounts WHERE company_id = ?', [$id]) + (int)$db->val('SELECT COUNT(*) FROM campaigns WHERE company_id = ?', [$id]);
        if ($used) {
            flash('error', 'This company still has email accounts or campaigns. Move or delete them first.');
            redirect('companies', ['id' => $id]);
        }
        foreach (['contacts', 'lists', 'templates', 'company_users'] as $t) $db->exec("DELETE FROM $t WHERE company_id = ?", [$id]);
        $db->exec('DELETE FROM list_contacts WHERE list_id NOT IN (SELECT id FROM lists)');
        $db->exec('DELETE FROM companies WHERE id = ?', [$id]);
        log_activity('company.delete', $co['name']);
        flash('success', 'Company deleted.');
        redirect('companies');
    }
    $data = [
        'name' => post('name'), 'website' => post('website'), 'phone' => post('phone'), 'address' => post('address'),
        'footer_text' => post('footer_text'), 'signature_html' => (string)($_POST['signature_html'] ?? ''),
    ];
    if ($data['name'] === '') {
        flash('error', 'Name is required.');
        back('companies');
    }
    if ($co) {
        $db->update('companies', $data, 'id = ?', [$id]);
    } else {
        $id = $db->insert('companies', $data + ['created_at' => now()]);
        $db->insertIgnore('company_users', ['company_id' => $id, 'user_id' => Auth::id()]);
    }
    $db->exec('DELETE FROM company_users WHERE company_id = ?', [$id]);
    foreach (array_map('intval', (array)($_POST['users'] ?? [])) as $uid) {
        $db->insertIgnore('company_users', ['company_id' => $id, 'user_id' => $uid]);
    }
    flash('success', 'Company saved.');
    redirect('companies');
}

if ($co || get('new') === '1') {
    $co = $co ?: ['name' => '', 'website' => '', 'phone' => '', 'address' => '', 'footer_text' => '', 'signature_html' => ''];
    $users = $db->all("SELECT id, name, email, role FROM users WHERE status = 'active' ORDER BY role, name");
    $members = $id ? array_map('intval', $db->col('SELECT user_id FROM company_users WHERE company_id = ?', [$id])) : [Auth::id()];
    layout_start($id ? 'Edit company' : 'New company', 'companies');
    ?>
    <div class="card narrow-wide">
      <form method="post" class="form">
        <?= csrf_field() ?>
        <div class="grid2">
          <label>Company name<input name="name" value="<?= e($co['name']) ?>" required></label>
          <label>Website<input name="website" value="<?= e($co['website']) ?>"></label>
          <label>Phone<input name="phone" value="<?= e($co['phone']) ?>"></label>
          <label>Physical mailing address <span class="muted small">(shown in email footer – required by CAN-SPAM)</span><input name="address" value="<?= e($co['address']) ?>"></label>
        </div>
        <label>Email footer text <span class="muted small">use {unsubscribe} where the link goes</span>
          <input name="footer_text" value="<?= e($co['footer_text']) ?>" placeholder="Not the right person or don't want these emails? {unsubscribe}"></label>
        <label>Default signature <span class="muted small">(used when an account has no signature of its own)</span></label>
        <?= email_editor('signature_html', (string)$co['signature_html']) ?>
        <h3>Who can access this company</h3>
        <div class="checks">
          <?php foreach ($users as $u): ?><label class="check"><input type="checkbox" name="users[]" value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $members, true) ? 'checked' : '' ?>> <?= e($u['name']) ?> <span class="muted small"><?= e($u['role']) ?></span></label><?php endforeach; ?>
        </div>
        <p class="muted small">Admins can always see every company.</p>
        <div class="form-actions"><button class="btn btn-primary">Save company</button>
          <?php if ($id): ?><button class="btn btn-danger right" name="action" value="delete" onclick="return confirm('Delete this company and all its contacts, lists and templates?')">Delete</button><?php endif; ?></div>
      </form>
    </div>
    <?php
    layout_end();
    return;
}

$rows = $db->all('SELECT c.*,
    (SELECT COUNT(*) FROM email_accounts a WHERE a.company_id = c.id) AS accounts,
    (SELECT COUNT(*) FROM contacts t WHERE t.company_id = c.id) AS contacts,
    (SELECT COUNT(*) FROM campaigns p WHERE p.company_id = c.id) AS campaigns,
    (SELECT COUNT(*) FROM company_users u WHERE u.company_id = c.id) AS users
    FROM companies c ORDER BY c.name');
layout_start('Companies', 'companies', '<a class="btn btn-primary" href="' . url('companies', ['new' => 1]) . '">' . icon('plus') . ' Add company</a>');
?>
<div class="card">
  <p class="muted small">Each company has its own carriers, lists, templates, email accounts and campaigns. Use the switcher in the top bar to change the company you are working in.</p>
  <table class="table">
    <tr><th>Company</th><th class="right">Users</th><th class="right">Email accounts</th><th class="right">Contacts</th><th class="right">Campaigns</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr><td><b><?= e($r['name']) ?></b><div class="small muted"><?= e($r['website']) ?></div></td><td class="right"><?= num($r['users']) ?></td><td class="right"><?= num($r['accounts']) ?></td><td class="right"><?= num($r['contacts']) ?></td><td class="right"><?= num($r['campaigns']) ?></td>
        <td class="right"><a class="btn btn-sm" href="<?= url('companies', ['id' => $r['id']]) ?>">Edit</a></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php layout_end();
