<?php
declare(strict_types=1);

$db = db();
$id = (int)get('id');
$tpl = $id ? $db->row('SELECT * FROM templates WHERE id = ?', [$id]) : null;
if ($id && (!$tpl || !Auth::canCompany((int)$tpl['company_id']))) {
    flash('error', 'Template not found.');
    redirect('templates');
}

if (is_post()) {
    csrf_check();
    if (post('action') === 'delete' && $tpl) {
        $db->exec('DELETE FROM templates WHERE id = ?', [$id]);
        flash('success', 'Template deleted.');
        redirect('templates');
    }
    $companyId = $tpl ? (int)$tpl['company_id'] : (int)post('company_id');
    if (!Auth::canCompany($companyId)) {
        flash('error', 'Choose a company.');
        back('templates');
    }
    $data = ['name' => post('name') ?: 'Untitled', 'subject' => post('subject'), 'body_html' => (string)($_POST['body_html'] ?? ''), 'updated_at' => now()];
    if ($tpl) {
        $db->update('templates', $data, 'id = ?', [$id]);
    } else {
        $id = $db->insert('templates', $data + ['company_id' => $companyId, 'user_id' => Auth::id(), 'created_at' => now()]);
    }
    flash('success', 'Template saved.');
    redirect('templates', ['id' => $id]);
}

if ($tpl || get('new') === '1') {
    if (!$tpl) {
        $tpl = ['name' => '', 'subject' => '{Quick question|Capacity question} for {{company_name|your team}}', 'body_html' => "<p>{Hi|Hello} {{first_name|there}},</p><p>I saw {{company_name|your company}} runs {{equipment_type|trucks}} out of {{state|your area}}. We're a dispatch service that keeps trucks loaded with good-paying freight, handles broker paperwork and negotiates rates for you.</p><p>Would it make sense to have a quick call this week to see if we're a fit?</p><p>{{signature}}</p>"];
    }
    layout_start($id ? 'Edit template' : 'New template', 'templates');
    ?>
    <div class="grid-2-1">
    <div class="card">
      <form method="post" class="form" data-spamcheck>
        <?= csrf_field() ?>
        <?php if (!$id): ?><?= company_select() ?><?php endif; ?>
        <label>Template name<input name="name" value="<?= e($tpl['name']) ?>" required></label>
        <label>Subject<input name="subject" value="<?= e($tpl['subject']) ?>" data-sc-subject></label>
        <label>Body</label>
        <?= email_editor('body_html', (string)$tpl['body_html']) ?>
        <div class="form-actions">
          <button class="btn btn-primary">Save template</button>
          <?php if ($id): ?><button class="btn btn-danger right" name="action" value="delete" onclick="return confirm('Delete template?')">Delete</button><?php endif; ?>
        </div>
      </form>
    </div>
    <div class="card sticky"><h2>Spam score</h2><div data-sc-result class="sc-result"><p class="muted">Start typing…</p></div></div>
    </div>
    <?php
    layout_end();
    return;
}

[$ts, $tp] = Auth::scope('t.company_id', null);
$rows = $db->all("SELECT t.*, u.name AS author FROM templates t LEFT JOIN users u ON u.id = t.user_id WHERE 1=1 $ts ORDER BY t.updated_at DESC, t.id DESC", $tp);
layout_start('Templates & Sequences', 'templates', '<a class="btn btn-primary" href="' . url('templates', ['new' => 1]) . '">' . icon('plus') . ' New template</a>');
$sample = Template::vars(
    ['first_name' => 'Mike', 'last_name' => 'Johnson', 'company_name' => 'Johnson Trucking LLC', 'mc_number' => '1234567', 'state' => 'TX', 'city' => 'Dallas', 'equipment_type' => 'Dry Van', 'truck_count' => '3'],
    ['from_name' => Auth::user()['name'], 'email' => Auth::user()['email'], 'signature_html' => '<p>' . e(Auth::user()['name']) . '<br>Dispatch Team</p>'],
    db()->row('SELECT * FROM companies WHERE id = ?', [Auth::scopeIds()[0] ?? 0]) ?: ['name' => 'Runnex Logistics']
);
?>
<div class="card">
  <div class="card-head"><h2>Ready-made dispatch sequences</h2><span class="muted small">Written for deliverability: short, personalised, spintax in every line, no links in the first email, follow-ups reply in the same thread.</span></div>
  <div class="seq-grid">
    <?php foreach (Sequences::all() as $key => $seq): $sc = SpamChecker::analyze($seq['steps'][0][1], $seq['steps'][0][2]); ?>
      <div class="seq-card">
        <div class="seq-top"><span class="badge <?= $seq['tag'] === 'Main cold email' ? 'b-bad' : 'b-info' ?>"><?= e($seq['tag']) ?></span><span class="badge b-ok">Spam score <?= $sc['score'] ?></span></div>
        <h3><?= e($seq['name']) ?></h3>
        <p class="small muted"><?= e($seq['about']) ?></p>
        <div class="seq-steps small"><?php $day = 0; foreach ($seq['steps'] as $i => $s): $day += $s[0]; ?><span>Day <?= $day ?></span><?php endforeach; ?></div>
        <details class="seq-preview"><summary class="small">Preview all <?= count($seq['steps']) ?> emails (sample carrier)</summary>
          <?php foreach ($seq['steps'] as $i => $s): ?>
            <div class="seq-mail">
              <div class="small muted"><?= $i === 0 ? 'Email 1' : 'Follow-up ' . $i . ' · ' . (int)$s[0] . ' days later · same thread' ?></div>
              <b><?= e($i === 0 ? Template::render($s[1], $sample) : 'Re: ' . Template::render($seq['steps'][0][1], $sample)) ?></b>
              <div class="seq-body"><?= Template::render($s[2], $sample) ?></div>
            </div>
          <?php endforeach; ?>
          <p class="small muted">Every carrier gets a different variation of each line (spintax), so no two emails are identical.</p>
        </details>
        <a class="btn btn-primary btn-sm" href="<?= url('campaign_edit', ['seq' => $key]) ?>">Use this sequence →</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<div class="card">
  <div class="card-head"><h2>My templates</h2></div>
  <?php if (!$rows): ?><?= empty_state('No templates', 'Save your best-performing emails as templates and reuse them in campaigns.', '<a class="btn btn-primary" href="' . url('templates', ['new' => 1]) . '">Create template</a>') ?><?php else: ?>
  <table class="table">
    <tr><th>Name</th><th>Subject</th><th>Spam score</th><th>By</th><th>Updated</th></tr>
    <?php foreach ($rows as $t): $sc = SpamChecker::analyze($t['subject'], $t['body_html']); ?>
      <tr><td><a href="<?= url('templates', ['id' => $t['id']]) ?>"><b><?= e($t['name']) ?></b></a></td><td class="small"><?= e($t['subject']) ?></td>
        <td><span class="badge b-<?= $sc['score'] >= 85 ? 'ok' : ($sc['score'] >= 70 ? 'good' : ($sc['score'] >= 50 ? 'warn' : 'bad')) ?>"><?= $sc['score'] ?> · <?= e($sc['grade']) ?></span></td>
        <td class="small"><?= e($t['author']) ?></td><td class="small muted"><?= e(time_ago($t['updated_at'] ?: $t['created_at'])) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php layout_end();
