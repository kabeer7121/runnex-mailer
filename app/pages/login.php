<?php
declare(strict_types=1);

if (Auth::user()) redirect('dashboard');
$error = '';
if (is_post()) {
    csrf_check();
    try {
        if (Auth::attempt(post('email'), (string)($_POST['password'] ?? ''))) {
            log_activity('login');
            redirect('dashboard');
        }
        $error = 'Wrong email or password.';
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · Runnex Email Marketing</title>
<link rel="icon" href="assets/img/favicon-light-192x192.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"><link rel="stylesheet" href="assets/css/app.css?v=<?= RX_VERSION ?>-5">
<script src="assets/js/app.js?v=<?= RX_VERSION ?>-5" defer></script>
</head><body class="auth-body">
<div class="auth-card">
  <div class="auth-brand"><span class="brand-mark"><img src="assets/img/favicon-light-192x192.png" alt=""></span><span class="brand-text"><b>Runnex Logistics</b><small>Email Marketing</small></span></div>
  <h1>Welcome back</h1>
  <p class="muted">Sign in to continue to your workspace</p>
  <?php if (isset($_GET['installed'])): ?><div class="alert alert-success">Installed! Sign in with your admin account. Delete install.php from the server.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Email<input type="email" name="email" value="<?= e(post('email')) ?>" required autofocus></label>
    <label>Password<input type="password" name="password" required></label>
    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
  </form>
  <div class="auth-foot">© <?= date('Y') ?> Runnex Logistics</div>
</div>
</body></html>
<?php exit;
