<?php
declare(strict_types=1);

$db = db();
[$rs, $rp] = Auth::scope('r.company_id', 'r.user_id');
$id = (int)get('id');
$cur = $id ? $db->row("SELECT r.* FROM replies r WHERE r.id = ? $rs", array_merge([$id], $rp)) : null;

if (is_post() && $cur) {
    csrf_check();
    $action = post('action');
    if ($action === 'reply') {
        $acc = $db->row('SELECT * FROM email_accounts WHERE id = ?', [$cur['account_id']]);
        $body = trim((string)($_POST['body'] ?? ''));
        if (!$acc || $body === '') {
            flash('error', 'Write a message first.');
        } else {
            try {
                $subject = 'Re: ' . preg_replace('/^(re:\s*)+/i', '', (string)$cur['subject']);
                $quote = "\n\nOn " . local_dt($cur['received_at']) . ', ' . ($cur['from_name'] ?: $cur['from_email']) . " wrote:\n> " . str_replace("\n", "\n> ", (string)$cur['body_text']);
                $mid = Mailer::sendFromAccount($acc, [
                    'to_email' => $cur['from_email'], 'to_name' => $cur['from_name'], 'subject' => $subject,
                    'text' => $body . $quote, 'html' => Mailer::textToHtml($body) . '<br><br><blockquote style="border-left:2px solid #ccc;margin:0;padding-left:10px;color:#666">' . nl2br(e((string)$cur['body_text'])) . '</blockquote>',
                    'in_reply_to' => $cur['message_id'], 'references' => trim(($cur['in_reply_to'] ?? '') . ' ' . ($cur['message_id'] ?? '')),
                ]);
                $db->insert('replies', [
                    'company_id' => $cur['company_id'], 'account_id' => $acc['id'], 'user_id' => $acc['user_id'], 'contact_id' => $cur['contact_id'],
                    'campaign_id' => $cur['campaign_id'], 'message_ref_id' => $cur['message_ref_id'], 'direction' => 'out', 'from_email' => $acc['email'],
                    'from_name' => $acc['from_name'], 'to_email' => $cur['from_email'], 'subject' => $subject, 'body_text' => $body, 'message_id' => $mid,
                    'in_reply_to' => $cur['message_id'], 'received_at' => now(), 'is_read' => 1,
                ]);
                flash('success', 'Reply sent.');
            } catch (Throwable $e) {
                flash('error', 'Could not send: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'label' && $cur['contact_id']) {
        $label = array_key_exists(post('label'), lead_labels()) ? post('label') : null;
        $db->exec('UPDATE contacts SET label = ? WHERE id = ?', [$label, $cur['contact_id']]);
        flash('success', 'Label saved.');
    } elseif ($action === 'unread') {
        $db->exec('UPDATE replies SET is_read = 0 WHERE id = ?', [$id]);
        redirect('inbox');
    } elseif ($action === 'unsubscribe' && $cur['contact_id']) {
        $db->exec("UPDATE contacts SET status = 'unsubscribed' WHERE id = ?", [$cur['contact_id']]);
        suppress($cur['from_email'], (int)$cur['company_id'], 'unsubscribe');
        $db->exec("UPDATE campaign_leads SET status = 'unsubscribed', next_send_at = NULL WHERE contact_id = ? AND status IN ('pending','active')", [$cur['contact_id']]);
        flash('success', 'Contact unsubscribed.');
    }
    redirect('inbox', ['id' => $id]);
}

if ($cur && !(int)$cur['is_read']) {
    $db->exec('UPDATE replies SET is_read = 1 WHERE id = ?', [$id]);
}

$filter = get('f', 'all');
$where = "r.direction = 'in'" . $rs;
$params = $rp;
if ($filter === 'unread') $where .= ' AND r.is_read = 0 AND r.is_auto = 0';
elseif ($filter === 'auto') $where .= ' AND r.is_auto = 1';
elseif (array_key_exists($filter, lead_labels())) {
    $where .= ' AND c.label = ?';
    $params[] = $filter;
} else $where .= ' AND r.is_auto = 0';
if (get('q') !== '') {
    $where .= ' AND (r.from_email LIKE ? OR r.from_name LIKE ? OR r.subject LIKE ? OR c.company_name LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . get('q') . '%'));
}
[$off, $lim, $page] = paging(40);
$total = (int)$db->val("SELECT COUNT(*) FROM replies r LEFT JOIN contacts c ON c.id = r.contact_id WHERE $where", $params);
$items = $db->all("SELECT r.*, c.company_name, c.label FROM replies r LEFT JOIN contacts c ON c.id = r.contact_id WHERE $where ORDER BY r.received_at DESC LIMIT $lim OFFSET $off", $params);
$labels = lead_labels();

layout_start('Unibox', 'inbox');
?>
<div class="unibox">
  <div class="card ub-list">
    <form class="toolbar" method="get"><input type="hidden" name="p" value="inbox"><input type="hidden" name="f" value="<?= e($filter) ?>"><input name="q" value="<?= e(get('q')) ?>" placeholder="Search replies…" class="grow"></form>
    <div class="tabs small">
      <?php foreach (['all' => 'All', 'unread' => 'Unread'] + $labels + ['auto' => 'Auto-replies'] as $k => $l): ?><a class="<?= $filter === $k ? 'on' : '' ?>" href="<?= url('inbox', ['f' => $k]) ?>"><?= e($l) ?></a><?php endforeach; ?>
    </div>
    <?php if (!$items): ?><?= empty_state('No replies', 'Replies to your campaigns from every connected account land here automatically.') ?><?php endif; ?>
    <?php foreach ($items as $r): ?>
      <a class="reply-item <?= $r['is_read'] ? '' : 'unread' ?> <?= $id === (int)$r['id'] ? 'sel' : '' ?>" href="<?= url('inbox', ['id' => $r['id'], 'f' => $filter]) ?>">
        <b><?= e($r['from_name'] ?: $r['from_email']) ?></b> <span class="muted small right"><?= e(time_ago($r['received_at'])) ?></span>
        <div class="small"><?= e($r['company_name'] ?: '') ?><?= $r['label'] ? ' <span class="badge b-info">' . e($labels[$r['label']] ?? '') . '</span>' : '' ?></div>
        <div class="snippet"><?= e(mb_substr((string)$r['body_text'], 0, 90)) ?></div>
      </a>
    <?php endforeach; ?>
    <?= pager_html($total, $lim, $page) ?>
  </div>
  <div class="card ub-view">
    <?php if (!$cur): ?>
      <?= empty_state('Select a conversation', 'Pick a reply on the left to read and answer it.') ?>
    <?php else:
        $contact = $cur['contact_id'] ? $db->row('SELECT * FROM contacts WHERE id = ?', [$cur['contact_id']]) : null;
        $thread = $db->all('SELECT * FROM replies WHERE account_id = ? AND (contact_id = ? OR from_email = ? OR to_email = ?) ORDER BY received_at', [$cur['account_id'], $cur['contact_id'] ?: 0, $cur['from_email'], $cur['from_email']]);
        $orig = $cur['message_ref_id'] ? $db->row('SELECT m.*, cp.name AS campaign FROM messages m LEFT JOIN campaigns cp ON cp.id = m.campaign_id WHERE m.id = ?', [$cur['message_ref_id']]) : null;
        ?>
      <div class="ub-head">
        <div><h2><?= e($cur['subject'] ?: '(no subject)') ?></h2>
          <div class="small muted">From <b><?= e($cur['from_name']) ?></b> &lt;<?= e($cur['from_email']) ?>&gt; to <?= e($cur['to_email']) ?><?php if ($orig): ?> · campaign <a href="<?= url('campaign_view', ['id' => $orig['campaign_id']]) ?>"><?= e($orig['campaign']) ?></a><?php endif; ?></div></div>
        <div class="nowrap">
          <form method="post" class="inline"><?= csrf_field() ?>
            <select name="label" onchange="this.form.submit()"><option value="">Label…</option><?php foreach ($labels as $k => $l): ?><option value="<?= $k ?>" <?= ($contact['label'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <input type="hidden" name="action" value="label"></form>
          <form method="post" class="inline"><?= csrf_field() ?><button class="btn btn-sm" name="action" value="unread">Mark unread</button><button class="btn btn-sm" name="action" value="unsubscribe" onclick="return confirm('Unsubscribe this carrier?')">Unsubscribe</button></form>
        </div>
      </div>
      <?php if ($contact): ?>
        <div class="contact-strip"><a href="<?= url('contact', ['id' => $contact['id']]) ?>"><?= e($contact['company_name'] ?: $contact['email']) ?></a>
          <?= $contact['mc_number'] ? ' · MC ' . e($contact['mc_number']) : '' ?><?= $contact['dot_number'] ? ' · DOT ' . e($contact['dot_number']) : '' ?><?= $contact['phone'] ? ' · ' . e($contact['phone']) : '' ?><?= $contact['state'] ? ' · ' . e($contact['state']) : '' ?><?= $contact['equipment_type'] ? ' · ' . e($contact['equipment_type']) : '' ?></div>
      <?php endif; ?>
      <div class="thread">
        <?php if ($orig): ?><div class="msg out"><div class="msg-meta">Your campaign email · <?= e(local_dt($orig['sent_at'])) ?></div><div class="msg-body"><b><?= e($orig['subject']) ?></b></div></div><?php endif; ?>
        <?php foreach ($thread as $t): ?>
          <div class="msg <?= $t['direction'] === 'in' ? 'in' : 'out' ?> <?= (int)$t['id'] === $id ? 'focus' : '' ?>">
            <div class="msg-meta"><?= e($t['direction'] === 'in' ? ($t['from_name'] ?: $t['from_email']) : 'You (' . $t['from_email'] . ')') ?> · <?= e(local_dt($t['received_at'])) ?><?= (int)$t['is_auto'] ? ' · auto-reply' : '' ?></div>
            <div class="msg-body"><?= nl2br(e((string)$t['body_text'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <form method="post" class="form reply-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="reply">
        <textarea name="body" rows="6" placeholder="Write your reply to <?= e($cur['from_name'] ?: $cur['from_email']) ?>…" required></textarea>
        <button class="btn btn-primary">Send reply</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php layout_end();
