<?php
declare(strict_types=1);

$db = db();
if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'create') {
        $companyId = (int)post('company_id');
        if (Auth::canCompany($companyId) && post('name') !== '') {
            $db->insert('lists', ['company_id' => $companyId, 'name' => post('name'), 'description' => post('description'), 'created_by' => Auth::id(), 'created_at' => now()]);
            flash('success', 'List created.');
        }
    } else {
        $list = $db->row('SELECT * FROM lists WHERE id = ?', [(int)post('id')]);
        if ($list && Auth::canCompany((int)$list['company_id'])) {
            if ($action === 'rename' && post('name') !== '') {
                $db->update('lists', ['name' => post('name'), 'description' => post('description')], 'id = ?', [$list['id']]);
                flash('success', 'List updated.');
            } elseif ($action === 'delete') {
                $db->exec('DELETE FROM list_contacts WHERE list_id = ?', [$list['id']]);
                $db->exec('DELETE FROM lists WHERE id = ?', [$list['id']]);
                flash('success', 'List deleted (contacts were kept).');
            }
        }
    }
    redirect('lists');
}

[$ls, $lp] = Auth::scope('l.company_id', null);
$lists = $db->all("SELECT l.*, co.name AS company,
    (SELECT COUNT(*) FROM list_contacts lc WHERE lc.list_id = l.id) AS total,
    (SELECT COUNT(*) FROM list_contacts lc JOIN contacts c ON c.id = lc.contact_id WHERE lc.list_id = l.id AND c.status = 'active') AS active
    FROM lists l LEFT JOIN companies co ON co.id = l.company_id WHERE 1=1 $ls ORDER BY l.created_at DESC", $lp);

layout_start('Lists', 'lists');
?>
<div class="grid-2-1">
<div class="card">
  <?php if (!$lists): ?><?= empty_state('No lists yet', 'Lists group carriers for campaigns – e.g. "Reefer carriers TX" or "New authorities March".') ?><?php else: ?>
  <table class="table">
    <tr><th>List</th><?php if (count(Auth::companyIds()) > 1): ?><th>Company</th><?php endif; ?><th class="right">Contacts</th><th class="right">Sendable</th><th>Created</th><th></th></tr>
    <?php foreach ($lists as $l): ?>
      <tr>
        <td><a href="<?= url('contacts', ['list' => $l['id']]) ?>"><b><?= e($l['name']) ?></b></a><div class="small muted"><?= e($l['description']) ?></div></td>
        <?php if (count(Auth::companyIds()) > 1): ?><td><?= e($l['company']) ?></td><?php endif; ?>
        <td class="right"><?= num($l['total']) ?></td><td class="right"><?= num($l['active']) ?></td>
        <td class="small muted"><?= e(local_dt($l['created_at'], 'M j, Y')) ?></td>
        <td class="nowrap right">
          <details class="inline-edit"><summary class="btn btn-sm">Edit</summary>
            <form method="post" class="form pop"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="action" value="rename">
              <label>Name<input name="name" value="<?= e($l['name']) ?>"></label><label>Description<input name="description" value="<?= e($l['description']) ?>"></label><button class="btn btn-primary btn-sm">Save</button></form>
          </details>
          <form method="post" class="inline" onsubmit="return confirm('Delete this list? Contacts are kept.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn btn-sm btn-danger" name="action" value="delete">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<div class="card">
  <h2>New list</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <?= company_select() ?>
    <label>Name<input name="name" required placeholder="e.g. Dry van carriers – Texas"></label>
    <label>Description<input name="description"></label>
    <button class="btn btn-primary">Create list</button>
  </form>
</div>
</div>
<?php layout_end();
