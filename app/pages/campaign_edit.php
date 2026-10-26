<?php
declare(strict_types=1);

$db = db();
$id = (int)get('id');
$c = $id ? $db->row('SELECT * FROM campaigns WHERE id = ?', [$id]) : null;
if ($id && (!$c || !Auth::canRow($c))) {
    flash('error', 'Campaign not found.');
    redirect('campaigns');
}

/** Accounts this user may send from in a company. */
$accountsFor = function (int $companyId) use ($db): array {
    if (Auth::isAdmin()) return $db->all('SELECT * FROM email_accounts WHERE company_id = ? ORDER BY email', [$companyId]);
    return $db->all('SELECT * FROM email_accounts WHERE company_id = ? AND user_id = ? ORDER BY email', [$companyId, Auth::id()]);
};

if (is_post()) {
    csrf_check();
    $companyId = $c ? (int)$c['company_id'] : (int)post('company_id');
    if (!Auth::canCompany($companyId)) {
        flash('error', 'Choose a company.');
        back('campaigns');
    }
    $days = array_values(array_intersect(array_map('intval', (array)($_POST['send_days'] ?? [])), [1, 2, 3, 4, 5, 6, 7]));
    $tz = post('timezone');
    try {
        new DateTimeZone($tz);
    } catch (Throwable $e) {
        $tz = app_tz()->getName();
    }
    $min = max(30, (int)post('min_delay', 90));
    $max = max($min, (int)post('max_delay', 240));
    $listId = (int)post('list_id') ?: null;
    if ($listId && !$db->val('SELECT COUNT(*) FROM lists WHERE id = ? AND company_id = ?', [$listId, $companyId])) $listId = null;
    $data = [
        'name' => post('name') ?: 'Untitled campaign',
        'list_id' => $listId,
        'track_opens' => post('track_opens') === '1' ? 1 : 0,
        'track_clicks' => post('track_clicks') === '1' ? 1 : 0,
        'plain_text' => post('plain_text') === '1' ? 1 : 0,
        'include_unsub' => post('include_unsub') === '1' ? 1 : 0,
        'stop_on_reply' => 1,
        'timezone' => $tz,
        'window_start' => preg_match('/^\d\d:\d\d$/', post('window_start')) ? post('window_start') : '08:00',
        'window_end' => preg_match('/^\d\d:\d\d$/', post('window_end')) ? post('window_end') : '17:00',
        'send_days' => implode(',', $days ?: [1, 2, 3, 4, 5]),
        'min_delay' => $min,
        'max_delay' => $max,
        'daily_limit' => max(0, (int)post('daily_limit')),
        'skip_recent_days' => max(0, (int)post('skip_recent_days')),
        'updated_at' => now(),
    ];
    if ($c) {
        $db->update('campaigns', $data, 'id = ?', [$id]);
    } else {
        $id = $db->insert('campaigns', $data + ['company_id' => $companyId, 'user_id' => Auth::id(), 'status' => 'draft', 'created_at' => now()]);
    }

    // sequence steps
    $steps = array_values(array_filter((array)($_POST['steps'] ?? []), fn($s) => trim(strip_tags((string)($s['body'] ?? ''))) !== ''));
    $db->exec('DELETE FROM campaign_steps WHERE campaign_id = ?', [$id]);
    foreach ($steps as $i => $s) {
        $db->insert('campaign_steps', [
            'campaign_id' => $id, 'step_no' => $i, 'delay_days' => $i === 0 ? 0 : max(1, (int)($s['delay'] ?? 3)),
            'subject' => mb_substr(trim((string)($s['subject'] ?? '')), 0, 250), 'body_html' => (string)$s['body'],
        ]);
    }

    // sending accounts
    $allowed = array_map(fn($a) => (int)$a['id'], $accountsFor($companyId));
    $chosen = array_values(array_intersect(array_map('intval', (array)($_POST['accounts'] ?? [])), $allowed));
    $db->exec('DELETE FROM campaign_accounts WHERE campaign_id = ?', [$id]);
    foreach ($chosen as $aid) $db->insert('campaign_accounts', ['campaign_id' => $id, 'account_id' => $aid]);

    $c = $db->row('SELECT * FROM campaigns WHERE id = ?', [$id]);
    $action = post('action');

    if ($action === 'test' || $action === 'placement') {
        $acc = $chosen ? $db->row('SELECT * FROM email_accounts WHERE id = ?', [$chosen[0]]) : null;
        $step = $db->row('SELECT * FROM campaign_steps WHERE campaign_id = ? AND step_no = 0', [$id]);
        if (!$acc || !$step) {
            flash('error', 'Select at least one sending account and write the first email.');
            redirect('campaign_edit', ['id' => $id]);
        }
        $company = $db->row('SELECT * FROM companies WHERE id = ?', [$companyId]) ?: [];
        $sample = $c['list_id'] ? $db->row("SELECT c.* FROM list_contacts lc JOIN contacts c ON c.id = lc.contact_id WHERE lc.list_id = ? AND c.status = 'active' LIMIT 1", [$c['list_id']]) : null;
        $sample = $sample ?: ['first_name' => 'Mike', 'company_name' => 'Sample Trucking LLC', 'email' => 'test@example.com', 'mc_number' => '123456', 'state' => 'TX', 'equipment_type' => 'Dry Van'];
        $mail = Mailer::composeCampaign($c, $step, ['thread_subject' => null, 'last_message_id' => null], $sample, $acc, $company, random_token(24));
        try {
            if ($action === 'test') {
                $to = strtolower(post('test_email'));
                if (!valid_email($to)) throw new RuntimeException('Enter a valid test email address.');
                Mailer::sendFromAccount($acc, ['to_email' => $to, 'subject' => '[TEST] ' . $mail['subject'], 'html' => $mail['html'], 'text' => $mail['text']]);
                flash('success', "Test email sent to $to from {$acc['email']} (using sample carrier data).");
            } else {
                $tid = Mailer::placementTest($acc, $mail['subject'], (string)($mail['html'] ?? Mailer::textToHtml($mail['text'])), $id);
                flash('success', 'Inbox placement test started – results appear within a few minutes.');
                redirect('deliverability', ['test' => $tid]);
            }
        } catch (Throwable $e) {
            flash('error', 'Could not send: ' . $e->getMessage());
        }
        redirect('campaign_edit', ['id' => $id]);
    }

    if ($action === 'launch' || $action === 'schedule') {
        $errors = [];
        $first = $db->row('SELECT * FROM campaign_steps WHERE campaign_id = ? AND step_no = 0', [$id]);
        if (!$first) $errors[] = 'Write the first email.';
        elseif (trim((string)$first['subject']) === '') $errors[] = 'The first email needs a subject.';
        if (!$chosen) $errors[] = 'Select at least one sending account.';
        if (!$c['list_id']) $errors[] = 'Choose a list of carriers.';
        if ($action === 'schedule' && post('scheduled_at') === '') $errors[] = 'Pick a start date & time.';
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('campaign_edit', ['id' => $id]);
        }
        $added = Engine::syncLeads($c);
        $total = (int)$db->val('SELECT COUNT(*) FROM campaign_leads WHERE campaign_id = ?', [$id]);
        if (!$total) {
            flash('error', 'The list has no active (non-unsubscribed) carriers.');
            redirect('campaign_edit', ['id' => $id]);
        }
        if ($action === 'schedule') {
            $db->update('campaigns', ['status' => 'scheduled', 'scheduled_at' => local_to_utc(post('scheduled_at'), new DateTimeZone($c['timezone']))], 'id = ?', [$id]);
            flash('success', "Campaign scheduled with $total carriers.");
        } else {
            $db->update('campaigns', ['status' => 'running', 'started_at' => $c['started_at'] ?: now(), 'scheduled_at' => null, 'auto_note' => null], 'id = ?', [$id]);
            $msg = "Campaign launched with $total carriers.";
            if (!Engine::inWindow($c)) $msg .= ' It is outside the sending window right now, so the first emails go out when the window opens.';
            flash('success', $msg);
        }
        log_activity('campaign.launch', $c['name']);
        redirect('campaign_view', ['id' => $id]);
    }

    if (in_array($c['status'], ['running', 'paused', 'scheduled'], true)) {
        $added = Engine::syncLeads($c);
        flash('success', 'Campaign saved.' . ($added ? " $added new carriers from the list were added to the queue." : ''));
    } else {
        flash('success', 'Draft saved.');
    }
    redirect('campaign_edit', ['id' => $id]);
}

// form
$companyId = (int)($c['company_id'] ?? Auth::currentCompany() ?: (Auth::companyIds()[0] ?? 0));
$c = $c ?: [
    'name' => '', 'list_id' => (int)get('list') ?: null, 'status' => 'draft', 'track_opens' => 1, 'track_clicks' => 0, 'plain_text' => 0, 'include_unsub' => 1,
    'timezone' => app_tz()->getName(), 'window_start' => '08:00', 'window_end' => '17:00', 'send_days' => '1,2,3,4,5',
    'min_delay' => 120, 'max_delay' => 300, 'daily_limit' => 0, 'skip_recent_days' => 7,
];
$steps = $id ? $db->all('SELECT * FROM campaign_steps WHERE campaign_id = ? ORDER BY step_no', [$id]) : [];
if (!$steps) {
    $steps = Sequences::steps(Sequences::get((string)get('seq')) ? (string)get('seq') : 'classic');
    if (!$id && Sequences::get((string)get('seq'))) $c['name'] = $c['name'] ?: Sequences::get((string)get('seq'))['name'];
}
$library = [];
foreach (Sequences::all() as $k => $s) {
    $library[$k] = ['name' => $s['name'], 'steps' => Sequences::steps($k)];
}
$lists = $db->all("SELECT l.id, l.name, (SELECT COUNT(*) FROM list_contacts lc JOIN contacts ct ON ct.id = lc.contact_id WHERE lc.list_id = l.id AND ct.status = 'active') AS n FROM lists l WHERE l.company_id = ? ORDER BY l.name", [$companyId]);
$accounts = $accountsFor($companyId);
$selected = $id ? array_map('intval', $db->col('SELECT account_id FROM campaign_accounts WHERE campaign_id = ?', [$id])) : array_map(fn($a) => (int)$a['id'], array_filter($accounts, fn($a) => $a['status'] === 'active' && !$a['send_paused_reason']));
[$ts, $tp] = Auth::scope('company_id', null);
$templates = $db->all("SELECT id, name, subject, body_html FROM templates WHERE 1=1 $ts ORDER BY name", $tp);
$days = array_map('intval', explode(',', (string)$c['send_days']));
$capacity = 0;
foreach ($accounts as $a) if (in_array((int)$a['id'], $selected, true)) $capacity += Health::effectiveLimit($a);

layout_start($id ? 'Edit campaign' : 'New campaign', 'campaigns');
?>
<?php if (count(Auth::companies()) > 1 && !$id): ?>
  <div class="alert alert-info">Creating in company: <b><?= e($db->val('SELECT name FROM companies WHERE id = ?', [$companyId])) ?></b>. Switch company in the top bar to create it elsewhere.</div>
<?php endif; ?>
<form method="post" class="form campaign-form" data-spamcheck>
  <?= csrf_field() ?>
  <input type="hidden" name="company_id" value="<?= $companyId ?>">
  <div class="grid-2-1">
    <div>
      <div class="card">
        <h2>1. Campaign</h2>
        <div class="grid2">
          <label>Campaign name<input name="name" value="<?= e($c['name']) ?>" placeholder="e.g. Reefer carriers – October" required></label>
          <label>Send to list<select name="list_id"><option value="">— choose list —</option><?php foreach ($lists as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$c['list_id'] === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?> (<?= num($l['n']) ?>)</option><?php endforeach; ?></select></label>
        </div>
        <p class="small muted"><?= icon('check') ?> Every address is verified before it is emailed – invalid emails (typos, fake, non-existent mailboxes) are skipped automatically. <a href="<?= url('verification') ?>">Email Verification →</a></p>
        <?php if (!$lists): ?><p class="small muted">No lists yet – <a href="<?= url('import') ?>">import a sheet</a> first.</p><?php endif; ?>
      </div>

      <div class="card">
        <div class="card-head"><h2>2. Email sequence</h2>
          <select data-load-sequence data-library="<?= e(json_encode($library)) ?>"><option value="">Load a ready-made sequence…</option><?php foreach (Sequences::all() as $k => $s): ?><option value="<?= e($k) ?>"><?= e($s['name']) ?> (<?= count($s['steps']) ?> emails)</option><?php endforeach; ?></select>
          <?php if ($templates): ?><select data-load-template><option value="">Load template into step 1…</option><?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>" data-subject="<?= e($t['subject']) ?>" data-body="<?= e($t['body_html']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        </div>
        <p class="muted small">Variables: <code>{{first_name}}</code> <code>{{company_name}}</code> <code>{{mc_number}}</code> <code>{{state}}</code> <code>{{equipment_type}}</code>… Fallback: <code>{{first_name|there}}</code>. Spintax: <code>{Hi|Hello|Hey}</code> makes every email unique. Follow-ups stop automatically when a carrier replies.</p>
        <div data-steps>
          <?php foreach ($steps as $i => $s): ?>
            <div class="step" data-step>
              <div class="step-head">
                <b data-step-title><?= $i === 0 ? 'Email 1' : 'Follow-up ' . $i ?></b>
                <span class="step-delay" <?= $i === 0 ? 'hidden' : '' ?>>send <input type="number" min="1" name="steps[<?= $i ?>][delay]" value="<?= (int)max(1, $s['delay_days']) ?>" class="tiny"> days after previous email if no reply</span>
                <button type="button" class="btn btn-sm btn-danger right" data-step-remove <?= $i === 0 ? 'hidden' : '' ?>>Remove</button>
              </div>
              <label>Subject <?= $i > 0 ? '<span class="muted small">(leave empty to reply in the same thread – recommended)</span>' : '' ?><input name="steps[<?= $i ?>][subject]" value="<?= e($s['subject']) ?>" data-sc-subject></label>
              <?= email_editor("steps[$i][body]", (string)$s['body_html']) ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn" data-step-add>+ Add follow-up</button>
      </div>

      <div class="card">
        <div class="card-head"><h2>3. Sending accounts</h2><span class="muted small">Emails rotate across all selected accounts. Each carrier always gets follow-ups from the same account.</span></div>
        <?php if (!$accounts): ?><p class="muted">No accounts in this company. <a href="<?= url('account_edit') ?>">Connect Gmail</a></p><?php else: ?>
        <div class="acc-pick">
          <label class="check"><input type="checkbox" data-checkall-scope=".acc-pick"> <b>Select all</b></label>
          <?php foreach ($accounts as $a): $st = $a['status'] === 'error' ? 'error' : $a['health_status']; ?>
            <label class="check acc-row"><input type="checkbox" name="accounts[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $selected, true) ? 'checked' : '' ?>>
              <span class="grow"><?= e($a['email']) ?> <span class="muted small"><?= e($a['from_name']) ?></span></span>
              <?= health_badge($st, (int)$a['health_score']) ?> <span class="small muted"><?= Health::effectiveLimit($a) ?>/day</span></label>
          <?php endforeach; ?>
        </div>
        <p class="small">Safe capacity of selected accounts: <b><?= num($capacity) ?> emails/day</b>. Accounts that are paused, in error or landing in spam are skipped automatically.</p>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>4. Schedule &amp; safety</h2>
        <div class="grid3">
          <label>Timezone<select name="timezone"><?php foreach (array_unique(array_merge([$c['timezone']], timezones_common())) as $tz): ?><option <?= $c['timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select></label>
          <label>Send from<input type="time" name="window_start" value="<?= e($c['window_start']) ?>"></label>
          <label>Send until<input type="time" name="window_end" value="<?= e($c['window_end']) ?>"></label>
        </div>
        <div class="days"><?php foreach ([1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $n => $d): ?><label class="day"><input type="checkbox" name="send_days[]" value="<?= $n ?>" <?= in_array($n, $days, true) ? 'checked' : '' ?>><span><?= $d ?></span></label><?php endforeach; ?></div>
        <div class="grid4">
          <label>Min gap between emails (sec)<input type="number" name="min_delay" min="30" value="<?= (int)$c['min_delay'] ?>"></label>
          <label>Max gap (sec)<input type="number" name="max_delay" min="30" value="<?= (int)$c['max_delay'] ?>"></label>
          <label>Campaign max/day <span class="muted small">0 = no cap</span><input type="number" name="daily_limit" min="0" value="<?= (int)$c['daily_limit'] ?>"></label>
          <label>Skip carriers emailed in last (days)<input type="number" name="skip_recent_days" min="0" value="<?= (int)$c['skip_recent_days'] ?>"></label>
        </div>
        <p class="small muted">Gaps apply per account – with 10 accounts and a 2–5 min gap you send ~150 emails/hour total while each account looks like a human sending one email every few minutes.</p>
        <div class="alert alert-success small"><b>Stop on reply is always on.</b> The moment a carrier replies to any email in the sequence – to any of your accounts, even if the reply was already read or archived in Gmail – all remaining follow-ups to them stop, in every campaign. Before each follow-up the inbox is re-checked; if it can't be read, the follow-up waits instead of sending. Out-of-office auto-replies only delay the next follow-up by 3 days.</div>
        <div class="checks">
          <label class="check"><input type="checkbox" name="track_opens" value="1" <?= (int)$c['track_opens'] ? 'checked' : '' ?>> <span>Track opens <span class="muted small">(adds an invisible 1px image)</span></span></label>
          <label class="check"><input type="checkbox" name="track_clicks" value="1" <?= (int)$c['track_clicks'] ? 'checked' : '' ?>> <span>Track clicks <span class="muted small">(rewrites links – can slightly hurt deliverability)</span></span></label>
          <label class="check"><input type="checkbox" name="plain_text" value="1" <?= (int)$c['plain_text'] ? 'checked' : '' ?>> <span>Send as plain text <span class="muted small">(best inbox rate, but no open tracking)</span></span></label>
          <label class="check"><input type="checkbox" name="include_unsub" value="1" <?= (int)$c['include_unsub'] ? 'checked' : '' ?>> <span>Add unsubscribe link + company address <span class="muted small">(recommended, required by CAN-SPAM)</span></span></label>
        </div>
      </div>
    </div>

    <div class="side">
      <div class="card sticky">
        <h2>Spam check</h2>
        <p class="muted small">Checks the email you are editing.</p>
        <div data-sc-result class="sc-result"><p class="muted">Click into an email to analyse it.</p></div>
        <hr>
        <h3>Test before sending</h3>
        <label>Send a test to<input type="email" name="test_email" value="<?= e(Auth::user()['email']) ?>"></label>
        <button class="btn btn-block" name="action" value="test" formnovalidate>Send test email</button>
        <button class="btn btn-block" name="action" value="placement" formnovalidate title="Sends email 1 to your other connected accounts and checks inbox vs spam">Inbox placement test</button>
        <hr>
        <button class="btn btn-block" name="action" value="save"><?= $c['status'] === 'draft' ? 'Save draft' : 'Save changes' ?></button>
        <?php if (in_array($c['status'], ['draft', 'paused', 'completed'], true)): ?>
          <button class="btn btn-primary btn-block" name="action" value="launch" onclick="return confirm('Launch this campaign now?')"><?= $c['status'] === 'draft' ? 'Launch campaign' : 'Save & resume' ?></button>
          <label>…or start at<input type="datetime-local" name="scheduled_at"></label>
          <button class="btn btn-block" name="action" value="schedule">Schedule</button>
        <?php else: ?>
          <p class="small muted">Status: <?= status_badge($c['status']) ?> – changes apply to emails not sent yet.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>
<template id="step-tpl">
  <div class="step" data-step>
    <div class="step-head">
      <b data-step-title>Follow-up</b>
      <span class="step-delay">send <input type="number" min="1" name="steps[__i__][delay]" value="3" class="tiny"> days after previous email if no reply</span>
      <button type="button" class="btn btn-sm btn-danger right" data-step-remove>Remove</button>
    </div>
    <label>Subject <span class="muted small">(leave empty to reply in the same thread – recommended)</span><input name="steps[__i__][subject]" value="" data-sc-subject></label>
    <?= email_editor('steps[__i__][body]', '') ?>
  </div>
</template>
<?php layout_end();
