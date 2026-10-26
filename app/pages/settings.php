<?php
declare(strict_types=1);

Auth::requireAdmin();
$db = db();

if (is_post()) {
    csrf_check();
    if (post('action') === 'run_cron') {
        $lock = fopen(RX_ROOT . '/storage/cron.lock', 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            @set_time_limit(90);
            $r = Engine::tick(time() + 40);
            flock($lock, LOCK_UN);
            flash('success', 'Worker ran once: ' . json_encode($r));
        } else {
            flash('warn', 'The worker is already running.');
        }
        redirect('settings');
    }
    $tz = post('timezone');
    try {
        new DateTimeZone($tz);
    } catch (Throwable $e) {
        $tz = 'America/Chicago';
    }
    $vals = [
        'app_url' => rtrim(post('app_url'), '/'),
        'timezone' => $tz,
        'default_daily_limit' => max(1, (int)post('default_daily_limit')),
        'default_warmup_start' => max(1, (int)post('default_warmup_start')),
        'default_warmup_increase' => max(1, (int)post('default_warmup_increase')),
        'default_warmup_max' => max(2, (int)post('default_warmup_max')),
        'default_warmup_reply_rate' => max(0, min(100, (int)post('default_warmup_reply_rate'))),
        'warmup_start_hour' => max(0, min(23, (int)post('warmup_start_hour'))),
        'warmup_end_hour' => max(1, min(24, (int)post('warmup_end_hour'))),
        'imap_interval' => max(180, (int)post('imap_interval')),
    ];
    foreach ($vals as $k => $v) set_setting($k, $v);
    log_activity('settings.save');
    flash('success', 'Settings saved.');
    redirect('settings');
}

$cronUrl = base_url() . '/cron.php?key=' . cfg('cron_key');
$cronCmd = '/usr/bin/php ' . RX_ROOT . '/cron.php';
$last = setting('cron_last_run');
$logs = $db->all('SELECT l.*, u.name FROM activity_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 40');
$logFile = RX_ROOT . '/storage/logs/app-' . gmdate('Y-m-d') . '.log';
$tail = is_file($logFile) ? array_slice(file($logFile, FILE_IGNORE_NEW_LINES) ?: [], -40) : [];

layout_start('Settings', 'settings');
?>
<div class="grid-2">
<div class="card">
  <h2>General</h2>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>App URL <span class="muted small">(public address – used for open/click tracking and unsubscribe links)</span><input name="app_url" value="<?= e(base_url()) ?>" required></label>
    <label>Timezone<select name="timezone"><?php foreach (array_unique(array_merge([app_tz()->getName()], timezones_common())) as $tz): ?><option <?= app_tz()->getName() === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select></label>
    <h3>Defaults for new email accounts</h3>
    <div class="grid3">
      <label>Campaign emails/day<input type="number" name="default_daily_limit" value="<?= (int)setting('default_daily_limit', 30) ?>"></label>
      <label>Warmup start/day<input type="number" name="default_warmup_start" value="<?= (int)setting('default_warmup_start', 3) ?>"></label>
      <label>Warmup increase/day<input type="number" name="default_warmup_increase" value="<?= (int)setting('default_warmup_increase', 2) ?>"></label>
      <label>Warmup max/day<input type="number" name="default_warmup_max" value="<?= (int)setting('default_warmup_max', 30) ?>"></label>
      <label>Warmup reply rate %<input type="number" name="default_warmup_reply_rate" value="<?= (int)setting('default_warmup_reply_rate', 35) ?>"></label>
    </div>
    <h3>Background jobs</h3>
    <div class="grid3">
      <label>Warmup from (hour)<input type="number" name="warmup_start_hour" min="0" max="23" value="<?= (int)setting('warmup_start_hour', 7) ?>"></label>
      <label>Warmup until (hour)<input type="number" name="warmup_end_hour" min="1" max="24" value="<?= (int)setting('warmup_end_hour', 20) ?>"></label>
      <label>Check inboxes every (sec)<input type="number" name="imap_interval" min="180" value="<?= (int)setting('imap_interval', 600) ?>"></label>
    </div>
    <button class="btn btn-primary">Save settings</button>
  </form>
</div>

<div class="card" id="cron">
  <h2>Cron job (required)</h2>
  <p>Status: <?= $last && strtotime($last . ' UTC') > time() - 600 ? '<span class="badge b-ok">Running</span> last run ' . e(time_ago($last)) : '<span class="badge b-bad">Not running</span>' . ($last ? ' last run ' . e(time_ago($last)) : '') ?></p>
  <p class="small">Everything automatic – sending, warmup, reply &amp; bounce detection, health scores – runs from <code>cron.php</code>. Set it to run <b>every minute</b>.</p>
  <h3>Hostinger</h3>
  <ol class="small">
    <li>hPanel → <b>Advanced → Cron Jobs</b>.</li>
    <li>Type: <b>PHP</b> (or Custom). Command:</li>
  </ol>
  <pre class="code" data-copy><?= e($cronCmd) ?></pre>
  <ol class="small" start="3"><li>Schedule: every minute (<code>* * * * *</code>) and save.</li></ol>
  <h3>Alternative: URL cron</h3>
  <p class="small">If your plan can't run PHP cron, call this URL every minute with a service like cron-job.org. Keep it secret.</p>
  <pre class="code" data-copy><?= e($cronUrl) ?></pre>
  <form method="post"><?= csrf_field() ?><button class="btn" name="action" value="run_cron">Run worker once now</button></form>
</div>
</div>

<div class="grid-2">
  <div class="card"><h2>Activity log</h2>
    <table class="table compact"><?php foreach ($logs as $l): ?><tr><td class="small muted nowrap"><?= e(local_dt($l['created_at'], 'M j g:i A')) ?></td><td class="small"><?= e($l['name'] ?? 'system') ?></td><td class="small"><?= e($l['action']) ?> <span class="muted"><?= e(mb_substr((string)$l['details'], 0, 80)) ?></span></td></tr><?php endforeach; ?></table>
  </div>
  <div class="card"><h2>Worker log (today, UTC)</h2>
    <pre class="code log"><?= $tail ? e(implode("\n", $tail)) : 'No entries today.' ?></pre>
    <p class="small muted">PHP <?= PHP_VERSION ?> · Runnex v<?= RX_VERSION ?> · DB: <?= e(db()->driver) ?></p>
  </div>
</div>
<?php layout_end();
