<?php
declare(strict_types=1);

$db = db();
$dir = RX_ROOT . '/storage/imports';
$step = 'upload';
$file = null;
$result = null;

if (is_post()) {
    csrf_check();
    @set_time_limit(600);
    try {
        if (post('stage') === 'upload') {
            $token = random_token(16);
            if (post('sheet_url') !== '') {
                $path = "$dir/$token.csv";
                Importer::fetchGoogleSheet(post('sheet_url'), $path);
                $name = 'Google Sheet';
            } else {
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Max upload size on this server: ' . ini_get('upload_max_filesize'));
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) throw new RuntimeException('Upload a .csv or .xlsx file (old .xls: open in Excel and "Save as" .xlsx or CSV).');
                $path = "$dir/$token.$ext";
                if (!move_uploaded_file($f['tmp_name'], $path)) throw new RuntimeException('Could not save the uploaded file. Check that storage/imports is writable.');
                $name = $f['name'];
            }
            if (filesize($path) > Importer::MAX_BYTES) {
                @unlink($path);
                throw new RuntimeException('File is too large. Keep imports under ' . (Importer::MAX_BYTES >> 20) . ' MB or split the sheet into smaller files.');
            }
            $_SESSION['import'] = ['path' => $path, 'name' => $name];
            redirect('import', ['step' => 'map']);
        }
        if (post('stage') === 'run') {
            $imp = $_SESSION['import'] ?? null;
            if (!$imp || !is_file($imp['path'])) throw new RuntimeException('Upload the file again.');
            $companyId = (int)post('company_id');
            if (!Auth::canCompany($companyId)) throw new RuntimeException('Choose a company.');
            $listId = (int)post('list_id') ?: null;
            if (post('new_list') !== '') {
                $listId = $db->insert('lists', ['company_id' => $companyId, 'name' => post('new_list'), 'created_by' => Auth::id(), 'created_at' => now()]);
            } elseif ($listId && !$db->val('SELECT COUNT(*) FROM lists WHERE id = ? AND company_id = ?', [$listId, $companyId])) {
                throw new RuntimeException('That list belongs to another company.');
            }
            $rows = Importer::read($imp['path']);
            $hasHeader = post('has_header') === '1';
            $headers = $hasHeader ? array_shift($rows) : [];
            if (count($rows) > Importer::MAX_ROWS) {
                @unlink($imp['path']);
                unset($_SESSION['import']);
                throw new RuntimeException('This file has ' . number_format(count($rows)) . ' rows. Import at most ' . number_format(Importer::MAX_ROWS) . ' rows at a time by splitting the sheet.');
            }
            $map = array_map('strval', (array)($_POST['map'] ?? []));
            $result = Importer::import($rows, $headers, $map, $companyId, $listId, ['update_existing' => post('update_existing') === '1', 'fix_typos' => post('fix_typos') === '1']);
            if (post('verify_now') === '1') {
                // verify the freshly imported carriers right away (the background worker finishes any remainder)
                $result['verified'] = Verifier::runBatch(time() + 40, [$companyId], 2000);
            }
            $result['list_id'] = $listId;
            @unlink($imp['path']);
            unset($_SESSION['import']);
            log_activity('contacts.import', json_encode($result));
            $step = 'done';
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('import', post('stage') === 'run' ? ['step' => 'map'] : []);
    }
}

if ($step !== 'done' && get('step') === 'map' && !empty($_SESSION['import']['path']) && is_file($_SESSION['import']['path'])) {
    $step = 'map';
    $preview = Importer::read($_SESSION['import']['path'], 6);
    if (!$preview) {
        flash('error', 'The file is empty.');
        redirect('import');
    }
    $header = $preview[0];
    $looksHeader = !array_filter($header, fn($h) => valid_email((string)$h));
    $cols = max(array_map('count', $preview));
}
$fields = ['__skip' => '— Skip —', '__custom' => 'Custom field (keep)', '__full_name' => 'Full name (split)'] + contact_fields();
[$ls, $lp] = Auth::scope('company_id', null);
$lists = $db->all("SELECT id, name, company_id FROM lists WHERE 1=1 $ls ORDER BY name", $lp);

layout_start('Import carriers', 'import');
?>
<div class="steps"><span class="<?= $step === 'upload' ? 'on' : 'done' ?>">1. Upload</span><span class="<?= $step === 'map' ? 'on' : ($step === 'done' ? 'done' : '') ?>">2. Match columns</span><span class="<?= $step === 'done' ? 'on' : '' ?>">3. Done</span></div>

<?php if ($step === 'upload'): ?>
<div class="grid-2-1">
<div class="card">
  <form method="post" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?><input type="hidden" name="stage" value="upload">
    <label class="dropzone">
      <?= icon('upload') ?>
      <b>Choose a CSV or Excel (.xlsx) file</b>
      <span class="muted small">Max <?= e(ini_get('upload_max_filesize')) ?>, up to <?= num(Importer::MAX_ROWS) ?> rows</span>
      <input type="file" name="file" accept=".csv,.xlsx,.txt">
    </label>
    <div class="or">or</div>
    <label>Google Sheets link <span class="muted small">(sharing: "Anyone with the link can view")</span>
      <input name="sheet_url" placeholder="https://docs.google.com/spreadsheets/d/…"></label>
    <button class="btn btn-primary">Continue</button>
  </form>
</div>
<div class="card help">
  <h2>Sheet format</h2>
  <p class="small">One carrier per row. The first row should be column names. Runnex recognises common names automatically:</p>
  <table class="table compact small">
    <tr><td>Email</td><td>email, email address</td></tr>
    <tr><td>Name</td><td>first name, last name, contact name, owner</td></tr>
    <tr><td>Carrier</td><td>company, carrier name, legal name, DBA</td></tr>
    <tr><td>MC / DOT</td><td>MC, MC#, docket, DOT, USDOT</td></tr>
    <tr><td>Other</td><td>phone, city, state, equipment / truck type, power units</td></tr>
  </table>
  <p class="small">Any other column is kept as a custom field you can use in emails, e.g. <code>{{lane}}</code>. Invalid, duplicate, disposable and suppressed emails are skipped automatically.</p>
</div>
</div>

<?php elseif ($step === 'map'): ?>
<div class="card">
  <p>File: <b><?= e($_SESSION['import']['name']) ?></b></p>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="stage" value="run">
    <div class="table-scroll">
    <table class="table map-table">
      <tr>
        <?php for ($i = 0; $i < $cols; $i++): $g = $looksHeader ? Importer::guess((string)($header[$i] ?? '')) : ($i === 0 ? 'email' : '__custom'); ?>
          <th><select name="map[<?= $i ?>]"><?php foreach ($fields as $k => $l): ?><option value="<?= $k ?>" <?= $g === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></th>
        <?php endfor; ?>
      </tr>
      <?php foreach ($preview as $ri => $row): ?>
        <tr class="<?= $ri === 0 && $looksHeader ? 'hdr' : '' ?>"><?php for ($i = 0; $i < $cols; $i++): ?><td class="small"><?= e(mb_substr((string)($row[$i] ?? ''), 0, 40)) ?></td><?php endfor; ?></tr>
      <?php endforeach; ?>
    </table>
    </div>
    <div class="grid3">
      <?= company_select() ?>
      <label>Add to existing list<select name="list_id"><option value="">— none —</option><?php foreach ($lists as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></label>
      <label>…or create a new list<input name="new_list" placeholder="e.g. Carriers <?= e(date('M j')) ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="has_header" value="1" <?= $looksHeader ? 'checked' : '' ?>> First row contains column names</label>
    <label class="check"><input type="checkbox" name="update_existing" value="1" checked> Update carriers that already exist (matched by email)</label>
    <label class="check"><input type="checkbox" name="fix_typos" value="1" checked> Auto-correct obvious typos (gmial.com → gmail.com, yahoo.con → yahoo.com)</label>
    <label class="check"><input type="checkbox" name="verify_now" value="1" checked> <span>Verify every email right after import <span class="muted small">(mail server + mailbox check – invalid emails are never sent to)</span></span></label>
    <div class="form-actions"><button class="btn btn-primary">Import carriers</button><a class="btn" href="<?= url('import') ?>">Start over</a></div>
  </form>
</div>

<?php else: ?>
<div class="card narrow center">
  <h2>Import complete</h2>
  <div class="stats-grid sm">
    <?= stat_card('Rows', num($result['total'])) ?>
    <?= stat_card('Added', num($result['added']), '', 'tone-green') ?>
    <?= stat_card('Updated', num($result['updated'])) ?>
    <?= stat_card('Duplicates', num($result['duplicates'])) ?>
    <?= stat_card('Rejected (bad emails)', num($result['invalid']), 'format, fake, disposable', $result['invalid'] ? 'tone-red' : '') ?>
    <?= stat_card('Typos corrected', num($result['fixed'])) ?>
    <?= stat_card('Suppressed', num($result['suppressed']), 'unsubscribed / bounced before') ?>
  </div>
  <?php
    $vc = ['valid' => 0, 'risky' => 0, 'invalid' => 0, 'unverified' => 0];
    if ($result['list_id']) {
        foreach (db()->all("SELECT COALESCE(c.verify_status, 'unverified') AS s, COUNT(*) AS n FROM list_contacts lc JOIN contacts c ON c.id = lc.contact_id WHERE lc.list_id = ? GROUP BY COALESCE(c.verify_status, 'unverified')", [$result['list_id']]) as $r) $vc[$r['s']] = (int)$r['n'];
    }
  ?>
  <?php if ($result['list_id']): ?>
    <h3>Email verification of this list</h3>
    <div class="stats-grid sm">
      <?= stat_card('Valid', num($vc['valid']), '', 'tone-green') ?>
      <?= stat_card('Risky', num($vc['risky'])) ?>
      <?= stat_card('Invalid', num($vc['invalid']), 'will never be emailed', $vc['invalid'] ? 'tone-red' : '') ?>
      <?= stat_card('Still verifying', num($vc['unverified']), $vc['unverified'] ? 'finishes automatically' : 'done') ?>
    </div>
  <?php endif; ?>
  <?php if ($result['invalid_samples']): ?>
    <details class="adv" style="text-align:left"><summary>Show rejected emails</summary><ul class="small"><?php foreach ($result['invalid_samples'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
  <div class="form-actions center">
    <a class="btn" href="<?= url('verification') ?>">Verification details</a>
    <a class="btn" href="<?= url('contacts', $result['list_id'] ? ['list' => $result['list_id']] : []) ?>">View carriers</a>
    <a class="btn btn-primary" href="<?= url('campaign_edit', $result['list_id'] ? ['list' => $result['list_id']] : []) ?>">Create campaign</a>
  </div>
</div>
<?php endif; ?>
<?php layout_end();
