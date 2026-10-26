<?php
declare(strict_types=1);

function layout_start(string $title, string $active = '', string $actions = ''): void
{
    $u = Auth::user();
    $companies = Auth::companies();
    $cur = Auth::currentCompany();
    $db = db();
    [$rs, $rp] = Auth::scope('company_id', 'user_id');
    $unread = (int)$db->val("SELECT COUNT(*) FROM replies WHERE direction = 'in' AND is_read = 0 AND is_auto = 0 $rs", $rp);
    [$as, $ap] = Auth::scope('company_id', 'user_id');
    $errAccounts = (int)$db->val("SELECT COUNT(*) FROM email_accounts WHERE (status = 'error' OR send_paused_reason IS NOT NULL) $as", $ap);
    [$vs, $vp] = Auth::scope('company_id', null);
    $invalidCount = 0;
    try {
        $invalidCount = (int)$db->val("SELECT COUNT(*) FROM contacts WHERE verified_at IS NULL AND status = 'active' $vs", $vp);
    } catch (Throwable $e) {
    }
    $cronLast = setting('cron_last_run');
    $cronDown = !$cronLast || strtotime($cronLast . ' UTC') < time() - 600;
    $nav = [
        '—Overview',
        ['dashboard', 'Dashboard', 'home'],
        ['inbox', 'Unibox', 'inbox', $unread],
        ['reports', 'Reports & Stats', 'chart'],
        '—Outreach',
        ['campaigns', 'Campaigns', 'send'],
        ['templates', 'Templates & Sequences', 'file'],
        ['contacts', 'Carriers / Contacts', 'contact'],
        ['lists', 'Lists', 'list'],
        ['import', 'Import Sheet', 'upload'],
        ['verification', 'Email Verification', 'check', $invalidCount],
        '—Deliverability',
        ['accounts', 'Email Accounts', 'mail', $errAccounts ? '!' : 0],
        ['warmup', 'Warmup & Health', 'flame'],
        ['deliverability', 'Anti-Spam Tools', 'shield'],
        ['suppression', 'Suppression List', 'ban'],
    ];
    if (Auth::isAdmin()) {
        $nav[] = '—Admin';
        $nav[] = ['companies', 'Companies', 'building'];
        $nav[] = ['users', 'Users & Agents', 'users'];
        $nav[] = ['settings', 'Settings', 'settings'];
    }
    $initial = e(strtoupper(mb_substr($u['name'], 0, 1)));
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · Runnex Email Marketing</title>
<link rel="icon" href="assets/img/favicon-light-192x192.png">
<link rel="apple-touch-icon" href="assets/img/android-chrome-192x192.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="assets/css/app.css?v=<?= RX_VERSION ?>-5">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
<script src="assets/js/app.js?v=<?= RX_VERSION ?>-5" defer></script>
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= url('dashboard') ?>">
      <span class="brand-mark"><img src="assets/img/android-chrome-192x192.png" alt=""></span>
      <span class="brand-text"><b>Runnex Logistics</b><small>Email Marketing</small></span>
    </a>
    <nav>
      <?php foreach ($nav as $n): ?>
        <?php if (is_string($n)): ?><div class="nav-sec"><?= e(substr($n, 3)) ?></div><?php continue; endif; ?>
        <a href="<?= url($n[0]) ?>" class="<?= $active === $n[0] ? 'active' : '' ?>"><?= icon($n[2]) ?><span><?= e($n[1]) ?></span><?php if (!empty($n[3])): ?><em class="pill<?= $n[3] === '!' ? ' pill-bad' : '' ?>"><?= e((string)$n[3]) ?></em><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-user">
      <span class="avatar"><?= $initial ?></span>
      <span class="side-user-text"><b><?= e($u['name']) ?></b><small><?= $u['role'] === 'admin' ? 'Administrator' : 'Agent' ?></small></span>
      <form method="post" action="<?= url('logout') ?>"><?= csrf_field() ?><button type="submit" class="icon-btn" title="Log out" aria-label="Log out"><?= icon('logout') ?></button></form>
    </div>
  </aside>
  <div class="scrim" onclick="document.getElementById('sidebar').classList.remove('open')"></div>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn mobile-only" onclick="document.getElementById('sidebar').classList.toggle('open')" aria-label="Menu"><?= icon('menu') ?></button>
      <span class="mobile-only topbar-brand"><img src="assets/img/favicon-light-192x192.png" alt=""> Runnex</span>
      <div class="topbar-right">
        <?php if (count($companies) > 1): ?>
        <form method="post" action="<?= url('switch') ?>" class="company-switch">
          <?= csrf_field() ?>
          <?= icon('building') ?>
          <select name="company_id" onchange="this.form.submit()" aria-label="Company">
            <option value="0">All companies</option>
            <?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cur === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
          </select>
        </form>
        <?php elseif ($companies): ?><span class="company-switch"><?= icon('building') ?> <?= e($companies[0]['name']) ?></span><?php endif; ?>
        <a class="icon-btn notif" href="<?= url('inbox', ['f' => 'unread']) ?>" title="Unread replies" aria-label="Unread replies"><?= icon('inbox') ?><?php if ($unread): ?><i><?= $unread > 99 ? '99+' : $unread ?></i><?php endif; ?></a>
        <div class="user-menu">
          <button class="user-btn" onclick="this.parentNode.classList.toggle('open')"><span class="avatar"><?= $initial ?></span><span class="hide-sm"><?= e($u['name']) ?></span></button>
          <div class="dropdown">
            <div class="dd-head"><b><?= e($u['name']) ?></b><small><?= e($u['email']) ?></small></div>
            <a href="<?= url('profile') ?>"><?= icon('user') ?> My profile</a>
            <?php if (Auth::isAdmin()): ?><a href="<?= url('settings') ?>"><?= icon('settings') ?> Settings</a><?php endif; ?>
            <form method="post" action="<?= url('logout') ?>"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?> Log out</button></form>
          </div>
        </div>
      </div>
    </header>
    <div class="content">
      <div class="page-head">
        <h1 class="page-title"><?= e($title) ?></h1>
        <?php if ($actions): ?><div class="page-actions"><?= $actions ?></div><?php endif; ?>
      </div>
      <?php if ($cronDown): ?>
        <div class="alert alert-error"><b>Background worker is not running.</b> Emails, warmup and stats only run when cron.php is called every minute.
          <?php if (Auth::isAdmin()): ?><a href="<?= url('settings') ?>#cron">See cron setup →</a><?php else: ?>Ask your admin to set up the cron job.<?php endif; ?></div>
      <?php endif; ?>
      <?php if (!$companies): ?>
        <div class="alert alert-warn">You are not assigned to any company yet. Ask an admin to add you to a company.</div>
      <?php endif; ?>
      <?php foreach (take_flashes() as [$type, $msg]): ?>
        <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
      <?php endforeach; ?>
<?php
}

function layout_end(): void
{
    ?>
    </div>
    <footer class="foot">Runnex Logistics · Email Marketing v<?= RX_VERSION ?></footer>
  </div>
</div>
</body>
</html>
<?php
}

/** <select> of companies the user can create things in. */
function company_select(string $name = 'company_id', ?int $selected = null): string
{
    $companies = Auth::companies();
    $selected = $selected ?? Auth::currentCompany();
    if (count($companies) === 1) {
        return '<input type="hidden" name="' . e($name) . '" value="' . (int)$companies[0]['id'] . '">';
    }
    $h = '<label>Company<select name="' . e($name) . '" required>';
    foreach ($companies as $c) {
        $h .= '<option value="' . (int)$c['id'] . '"' . ($selected === (int)$c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    }
    return $h . '</select></label>';
}

function stat_card(string $label, string $value, string $sub = '', string $tone = '', string $icon = ''): string
{
    return '<div class="stat ' . e($tone) . '">' . ($icon !== '' ? '<span class="stat-ic">' . icon($icon) . '</span>' : '') . '<div class="stat-label">' . e($label) . '</div><div class="stat-value">' . $value . '</div>' . ($sub !== '' ? '<div class="stat-sub">' . $sub . '</div>' : '') . '</div>';
}

function empty_state(string $title, string $text, string $btn = ''): string
{
    return '<div class="empty"><h3>' . e($title) . '</h3><p>' . e($text) . '</p>' . $btn . '</div>';
}

/** Render a chart canvas; data is read by app.js. */
function chart(string $type, array $labels, array $datasets, int $height = 260, array $opts = []): string
{
    $cfg = ['type' => $type, 'labels' => $labels, 'datasets' => $datasets, 'opts' => $opts];
    return '<div class="chart-wrap" style="height:' . $height . 'px"><canvas data-chart="' . e(json_encode($cfg)) . '"></canvas></div>';
}

/** Editor for an email body (contenteditable with HTML source toggle). */
function email_editor(string $name, string $value, string $id = ''): string
{
    $id = $id ?: 'ed_' . random_token(6);
    $vars = '';
    foreach (Template::variableList() as $v) {
        $vars .= '<option value="{{' . $v . '}}">{{' . $v . '}}</option>';
    }
    return '<div class="editor" data-editor id="' . e($id) . '">
      <div class="editor-bar">
        <button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
        <button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
        <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
        <button type="button" data-cmd="insertUnorderedList" title="List">• List</button>
        <button type="button" data-cmd="createLink" title="Link">Link</button>
        <button type="button" data-cmd="removeFormat" title="Clear formatting">Clear</button>
        <select data-insert><option value="">Insert variable…</option>' . $vars . '<option value="{Hi|Hello|Hey}">Spintax {Hi|Hello|Hey}</option></select>
        <button type="button" data-source class="right">HTML</button>
      </div>
      <div class="editor-area" contenteditable="true"></div>
      <textarea name="' . e($name) . '" class="editor-src" hidden>' . e($value) . '</textarea>
    </div>';
}
