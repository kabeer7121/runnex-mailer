<?php
declare(strict_types=1);

$u = Auth::user();
if (is_post()) {
    csrf_check();
    $name = post('name');
    $data = ['name' => $name ?: $u['name']];
    $new = (string)($_POST['new_password'] ?? '');
    if ($new !== '') {
        if (!password_verify((string)($_POST['current_password'] ?? ''), $u['password_hash'])) {
            flash('error', 'Current password is wrong.');
            redirect('profile');
        }
        if (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
            redirect('profile');
        }
        $data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
    }
    db()->update('users', $data, 'id = ?', [$u['id']]);
    flash('success', 'Profile saved.');
    redirect('profile');
}

layout_start('My profile', 'profile');
?>
<div class="card narrow">
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Name<input name="name" value="<?= e($u['name']) ?>" required></label>
    <label>Email<input value="<?= e($u['email']) ?>" disabled></label>
    <h3>Change password</h3>
    <label>Current password<input type="password" name="current_password"></label>
    <label>New password<input type="password" name="new_password" minlength="8"></label>
    <button class="btn btn-primary">Save</button>
  </form>
</div>
<?php layout_end();
