<?php
declare(strict_types=1);

Auth::requireAdmin();
$db = db();
$id = (int)get('id');
$u = $id ? $db->row('SELECT * FROM users WHERE id = ?', [$id]) : null;
$companies = $db->all('SELECT id, name FROM companies ORDER BY name');

if (is_post()) {
    csrf_check();
    $email = strtolower(post('email'));
    if (!valid_email($email)) {
        flash('error', 'Valid email required.');
        back('users');
    }
    if ($db->val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
        flash('error', 'A user with this email already exists.');
        back('users');
    }
    $role = post('role') === 'admin' ? 'admin' : 'agent';
    $status = post('status') === 'disabled' ? 'disabled' : 'active';
    if ($u && (int)$u['id'] === Auth::id() && ($role !== 'admin' || $status !== 'active')) {
        flash('error', 'You cannot demote or disable your own account.');
        back('users');
    }
    $data = ['name' => post('name'), 'email' => $email, 'role' => $role, 'status' => $status];
    $pass = (string)($_POST['password'] ?? '');
    if ($pass !== '') {
        if (strlen($pass) < 8) {
            flash('error', 'Password must be at least 8 characters.');
            back('users');
        }
        $data['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
    } elseif (!$u) {
        flash('error', 'Set a password for the new user.');
        back('users');
    }
    if ($u) {
        $db->update('users', $data, 'id = ?', [$id]);
    } else {
        $id = $db->insert('users', $data + ['created_at' => now()]);
    }
    $db->exec('DELETE FROM company_users WHERE user_id = ?', [$id]);
    foreach (array_map('intval', (array)($_POST['companies'] ?? [])) as $cid) {
        $db->insertIgnore('company_users', ['company_id' => $cid, 'user_id' => $id]);
    }
    if ($status === 'disabled') {
        // stop their accounts from sending while disabled
        $db->exec("UPDATE email_accounts SET status = 'paused' WHERE user_id = ? AND status = 'active'", [$id]);
    }
    log_activity('user.save', $email);
    flash('success', 'User saved.' . ($u ? '' : ' Share the login details with them – they can change the password under My profile.'));
    redirect('users');
}

if ($u || get('new') === '1') {
    $u = $u ?: ['name' => '', 'email' => '', 'role' => 'agent', 'status' => 'active'];
    $mine = $id ? array_map('intval', $db->col('SELECT company_id FROM company_users WHERE user_id = ?', [$id])) : array_filter([Auth::currentCompany()]);
    layout_start($id ? 'Edit user' : 'Add user', 'users');
    ?>
    <div class="card narrow">
      <form method="post" class="form">
        <?= csrf_field() ?>
        <label>Name<input name="name" value="<?= e($u['name']) ?>" required></label>
        <label>Email (login)<input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
        <label>Password <?= $id ? '<span class="muted small">(leave blank to keep)</span>' : '' ?><input type="password" name="password" minlength="8" autocomplete="new-password" <?= $id ? '' : 'required' ?>></label>
        <div class="grid2">
          <label>Role<select name="role"><option value="agent" <?= $u['role'] === 'agent' ? 'selected' : '' ?>>Agent</option><option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select></label>
          <label>Status<select name="status"><option value="active">Active</option><option value="disabled" <?= $u['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option></select></label>
        </div>
        <h3>Companies</h3>
        <div class="checks"><?php foreach ($companies as $c): ?><label class="check"><input type="checkbox" name="companies[]" value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $mine, true) ? 'checked' : '' ?>> <?= e($c['name']) ?></label><?php endforeach; ?></div>
        <div class="card tip small">
          <b>Agent</b>: connects their own email accounts, imports carriers, runs their own campaigns, answers their own replies and sees their own stats – only inside the companies above.<br>
          <b>Admin</b>: full access to every company, every agent's accounts, campaigns and stats, users and settings.
        </div>
        <button class="btn btn-primary">Save user</button>
      </form>
    </div>
    <?php
    layout_end();
    return;
}

$rows = $db->all('SELECT u.*,
    (SELECT COUNT(*) FROM email_accounts a WHERE a.user_id = u.id) AS accounts,
    (SELECT COUNT(*) FROM campaigns c WHERE c.user_id = u.id) AS campaigns,
    (SELECT COUNT(*) FROM messages m WHERE m.user_id = u.id AND m.sent_day >= ?) AS sent30
    FROM users u ORDER BY u.role, u.name', [local_day_offset(-29)]);
$cmap = [];
foreach ($db->all('SELECT cu.user_id, c.name FROM company_users cu JOIN companies c ON c.id = cu.company_id') as $r) $cmap[$r['user_id']][] = $r['name'];
layout_start('Users & Agents', 'users', '<a class="btn btn-primary" href="' . url('users', ['new' => 1]) . '">' . icon('plus') . ' Add user</a>');
?>
<div class="card">
  <table class="table">
    <tr><th>User</th><th>Role</th><th>Companies</th><th class="right">Email accounts</th><th class="right">Campaigns</th><th class="right">Sent (30d)</th><th>Last login</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['status'] === 'disabled' ? 'dim' : '' ?>"><td><b><?= e($r['name']) ?></b><div class="small muted"><?= e($r['email']) ?></div></td>
        <td><?= $r['role'] === 'admin' ? '<span class="badge b-bad">Admin</span>' : '<span class="badge b-info">Agent</span>' ?><?= $r['status'] === 'disabled' ? ' <span class="badge b-muted">Disabled</span>' : '' ?></td>
        <td class="small"><?= $r['role'] === 'admin' ? 'All' : e(implode(', ', $cmap[$r['id']] ?? ['—'])) ?></td>
        <td class="right"><?= num($r['accounts']) ?></td><td class="right"><?= num($r['campaigns']) ?></td><td class="right"><?= num($r['sent30']) ?></td>
        <td class="small muted"><?= e(time_ago($r['last_login_at'])) ?></td>
        <td class="right"><a class="btn btn-sm" href="<?= url('users', ['id' => $r['id']]) ?>">Edit</a></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php layout_end();
