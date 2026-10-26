<?php
declare(strict_types=1);

/**
 * IMAP processing for every connected account:
 *  - warmup: record inbox/spam placement, rescue from spam, mark read/important, reply, archive to a label
 *  - placement tests: record where test emails landed
 *  - campaign replies (incl. auto-replies + "unsubscribe" replies) -> unified inbox, stop sequences
 *  - bounces (mailer-daemon) -> mark bounced, suppress address
 */
final class Mailbox
{
    public const LABEL = 'Runnex-Warmup';
    private const FIELDS = ['FROM', 'TO', 'SUBJECT', 'MESSAGE-ID', 'IN-REPLY-TO', 'REFERENCES', 'X-RUNNEX-WARMUP', 'X-RUNNEX-TEST', 'AUTO-SUBMITTED', 'X-AUTOREPLY', 'X-FAILED-RECIPIENTS', 'DATE'];

    /** Verify SMTP auth + IMAP login. Returns ['ok'=>bool, 'smtp'=>string, 'imap'=>string]. */
    public static function testConnection(array $acc): array
    {
        $res = ['ok' => true, 'smtp' => 'OK', 'imap' => 'OK'];
        try {
            $smtp = Mailer::smtpFor($acc);
            $smtp->quit();
        } catch (Throwable $e) {
            $res['ok'] = false;
            $msg = $e->getMessage();
            if (str_contains($msg, '535') || stripos($msg, 'Username and Password not accepted') !== false) {
                $msg = 'Login rejected. Use a Google App Password (not your normal password) and make sure 2-Step Verification is on.';
            }
            $res['smtp'] = $msg;
        }
        try {
            $imap = new ImapClient();
            $imap->connect($acc['imap_host'], (int)$acc['imap_port']);
            $imap->login($acc['username'], Crypto::decrypt($acc['password_enc']));
            $imap->logout();
        } catch (Throwable $e) {
            $res['ok'] = false;
            $res['imap'] = $e->getMessage() . (stripos($e->getMessage(), 'login') !== false ? ' (check that IMAP is enabled in Gmail settings → Forwarding and POP/IMAP)' : '');
        }
        return $res;
    }

    public static function run(int $deadline): int
    {
        $db = db();
        $due = $db->all("SELECT * FROM email_accounts WHERE status IN ('active','paused') AND (imap_next_at IS NULL OR imap_next_at <= ?) ORDER BY imap_next_at", [now()]);
        $n = 0;
        foreach ($due as $acc) {
            if (time() > $deadline - 8) break;
            try {
                self::check($acc);
            } catch (Throwable $e) {
                app_log('IMAP ' . $acc['email'] . ': ' . $e->getMessage());
                $msg = $e->getMessage();
                $upd = ['imap_next_at' => now(900), 'last_error' => 'IMAP: ' . mb_substr($msg, 0, 400)];
                if (stripos($msg, 'login failed') !== false) $upd['status'] = 'error';
                $db->update('email_accounts', $upd, 'id = ?', [$acc['id']]);
            }
            $n++;
        }
        return $n;
    }

    public static function check(array $acc): array
    {
        $db = db();
        $imap = new ImapClient();
        $imap->connect($acc['imap_host'], (int)$acc['imap_port']);
        $imap->login($acc['username'], Crypto::decrypt($acc['password_enc']));
        $state = json_decode((string)$acc['imap_state'], true) ?: [];
        if (empty($state['spam_folder'])) {
            $state['spam_folder'] = $imap->findSpecial('\\junk') ?: '[Gmail]/Spam';
        }
        if (empty($state['label_ok'])) {
            $imap->createFolder(self::LABEL);
            $state['label_ok'] = 1;
        }
        $summary = ['spam' => 0, 'inbox' => 0, 'replies' => 0, 'bounces' => 0];
        try {
            self::processFolder($imap, $acc, $state['spam_folder'], 'spam', $state, $summary);
        } catch (Throwable $e) {
            app_log('IMAP spam folder ' . $acc['email'] . ': ' . $e->getMessage());
        }
        self::processFolder($imap, $acc, 'INBOX', 'inbox', $state, $summary);
        // Gmail "All Mail": catches replies that were already read & archived (or filtered past the inbox)
        if (!array_key_exists('all_folder', $state)) {
            $state['all_folder'] = $imap->findSpecial('\\all');
        }
        if (!empty($state['all_folder'])) {
            try {
                self::processFolder($imap, $acc, $state['all_folder'], 'all', $state, $summary);
            } catch (Throwable $e) {
                app_log('IMAP all-mail ' . $acc['email'] . ': ' . $e->getMessage());
            }
        }
        $imap->logout();

        $seed = (int)$db->val("SELECT COUNT(*) FROM placement_results r JOIN placement_tests t ON t.id = r.test_id WHERE r.seed_account_id = ? AND r.landed = 'pending' AND t.status = 'running'", [$acc['id']]);
        $interval = $seed ? 120 : (int)setting('imap_interval', 600);
        $upd = ['imap_state' => json_encode($state), 'imap_checked_at' => now(), 'imap_next_at' => now($interval)];
        if ($acc['last_error'] && str_starts_with((string)$acc['last_error'], 'IMAP:')) $upd['last_error'] = null;
        $db->update('email_accounts', $upd, 'id = ?', [$acc['id']]);
        return $summary;
    }

    private static function processFolder(ImapClient $imap, array $acc, string $folder, string $kind, array &$state, array &$summary): void
    {
        $info = $imap->select($folder);
        $key = 'f_' . $kind;
        $st = $state[$key] ?? null;
        if (!$st || (int)$st['v'] !== $info['uidvalidity']) {
            $uids = $imap->uidSearch('SINCE ' . date('d-M-Y', time() - 3 * 86400));
            $last = 0;
        } else {
            $last = (int)$st['u'];
            $uids = array_values(array_filter($imap->uidSearch('UID ' . ($last + 1) . ':*'), fn($u) => $u > $last));
        }
        $uids = array_slice($uids, -150);
        $state[$key] = ['v' => $info['uidvalidity'], 'u' => $uids ? max($last, max($uids)) : $last];
        if (!$uids) return;

        foreach (array_chunk($uids, 50) as $chunk) {
            foreach ($imap->fetchHeaders($chunk, self::FIELDS) as $uid => $raw) {
                try {
                    self::handle($imap, $acc, $folder, $kind, (int)$uid, MailParser::headers($raw), $summary);
                } catch (Throwable $e) {
                    app_log("IMAP handle {$acc['email']} uid $uid: " . $e->getMessage());
                }
            }
        }
    }

    private static function handle(ImapClient $imap, array $acc, string $folder, string $kind, int $uid, array $h, array &$summary): void
    {
        $db = db();
        [$fromEmail, $fromName] = MailParser::address($h['from'] ?? '');

        // All Mail pass only looks for campaign replies + bounces; warmup/tests are handled in Inbox/Spam
        if ($kind === 'all' && (!empty($h['x-runnex-warmup']) || !empty($h['x-runnex-test']))) return;

        // warmup
        if (!empty($h['x-runnex-warmup'])) {
            $wm = $db->row('SELECT * FROM warmup_messages WHERE token = ?', [trim($h['x-runnex-warmup'])]);
            if (!$wm || (int)$wm['to_account_id'] !== (int)$acc['id']) return;
            if ($wm['landed'] === null) {
                $db->update('warmup_messages', ['landed' => $kind, 'checked_at' => now()], 'id = ?', [$wm['id']]);
                bump_account_daily((int)$wm['from_account_id'], $kind === 'spam' ? 'warmup_spam' : 'warmup_inbox');
                $summary[$kind]++;
            }
            if ($kind === 'spam') {
                // "Not spam": moving it to the inbox is a strong positive reputation signal
                $imap->move($uid, 'INBOX');
                return;
            }
            $imap->addFlags([$uid], mt_rand(1, 100) <= 20 ? '\\Seen \\Flagged' : '\\Seen');
            $imap->addGmailLabel($uid, '\\Important');
            if (!(int)$wm['is_reply'] && !(int)$wm['replied'] && mt_rand(1, 100) <= (int)$acc['warmup_reply_rate'] && $acc['status'] === 'active') {
                try {
                    Warmup::reply($acc, ['from_email' => $fromEmail, 'from_name' => $fromName, 'from_account_id' => $wm['from_account_id']], $h);
                    $db->update('warmup_messages', ['replied' => 1], 'id = ?', [$wm['id']]);
                } catch (SmtpException $e) {
                    Warmup::handleSendError($acc, $e);
                }
            }
            $imap->move($uid, self::LABEL);
            return;
        }

        // inbox placement test
        if (!empty($h['x-runnex-test'])) {
            $t = $db->row('SELECT * FROM placement_tests WHERE token = ?', [trim($h['x-runnex-test'])]);
            if ($t) {
                $db->exec("UPDATE placement_results SET landed = ?, checked_at = ? WHERE test_id = ? AND seed_account_id = ? AND landed = 'pending'", [$kind, now(), $t['id'], $acc['id']]);
                if ($kind === 'spam') $imap->move($uid, 'INBOX');
                else {
                    $imap->addFlags([$uid], '\\Seen');
                    $imap->move($uid, self::LABEL);
                }
            }
            return;
        }

        if ($fromEmail === strtolower($acc['email'])) return;

        // bounces
        $subj = strtolower($h['subject'] ?? '');
        if (preg_match('/^(mailer-daemon|postmaster|mail-daemon)@/', $fromEmail) || preg_match('/(delivery status notification|undeliverable|undelivered mail|mail delivery (failed|subsystem)|returned mail|delivery failure)/', $subj)) {
            if (self::handleBounce($imap, $acc, $uid, $h)) $summary['bounces']++;
            return;
        }

        // campaign replies
        $ids = array_unique(array_merge(MailParser::messageIds($h['in-reply-to'] ?? ''), MailParser::messageIds($h['references'] ?? '')));
        $msg = null;
        if ($ids) {
            $msg = $db->row('SELECT * FROM messages WHERE account_id = ? AND message_id IN (' . DB::in($ids) . ') ORDER BY sent_at DESC LIMIT 1', array_merge([$acc['id']], $ids));
        }
        if (!$msg && $fromEmail) {
            $msg = $db->row("SELECT * FROM messages WHERE account_id = ? AND to_email = ? AND kind = 'campaign' AND sent_at >= ? ORDER BY sent_at DESC LIMIT 1", [$acc['id'], $fromEmail, now(-60 * 86400)]);
        }
        if (!$msg && $fromEmail) {
            // carrier wrote to a different one of our accounts – still counts as a reply to the campaign
            $msg = $db->row("SELECT * FROM messages WHERE to_email = ? AND company_id = ? AND kind = 'campaign' AND sent_at >= ? ORDER BY sent_at DESC LIMIT 1", [$fromEmail, $acc['company_id'], now(-60 * 86400)]);
        }
        if (!$msg) return;

        $mid = trim($h['message-id'] ?? '');
        if ($mid && $db->val('SELECT COUNT(*) FROM replies WHERE message_id = ?', [$mid])) return;

        $parsed = MailParser::parse($imap->fetchRaw($uid));
        $body = MailParser::stripQuoted($parsed['text']);
        $auto = (isset($h['auto-submitted']) && strtolower($h['auto-submitted']) !== 'no') || isset($h['x-autoreply'])
            || preg_match('/(out of (the )?office|automatic reply|auto(-| )?reply|autoreply|away from|on vacation)/i', $h['subject'] ?? '');
        self::recordReply($acc, $msg, [
            'from_email' => $fromEmail, 'from_name' => $fromName, 'subject' => $h['subject'] ?? '',
            'body' => $body !== '' ? $body : $parsed['text'], 'message_id' => $mid,
            'in_reply_to' => $ids[0] ?? null, 'auto' => (bool)$auto,
        ]);
        if ($kind === 'spam') $imap->move($uid, 'INBOX');
        $summary['replies']++;
    }

    public static function recordReply(array $acc, array $msg, array $r): void
    {
        $db = db();
        $db->insert('replies', [
            'company_id' => $msg['company_id'], 'account_id' => $acc['id'], 'user_id' => $acc['user_id'],
            'contact_id' => $msg['contact_id'], 'campaign_id' => $msg['campaign_id'], 'message_ref_id' => $msg['id'],
            'direction' => 'in', 'is_auto' => $r['auto'] ? 1 : 0,
            'from_email' => $r['from_email'], 'from_name' => mb_substr((string)$r['from_name'], 0, 190), 'to_email' => $acc['email'],
            'subject' => mb_substr((string)$r['subject'], 0, 250), 'body_text' => mb_substr((string)$r['body'], 0, 60000),
            'message_id' => $r['message_id'] ?: null, 'in_reply_to' => $r['in_reply_to'], 'received_at' => now(), 'is_read' => 0,
        ]);
        if ($r['auto']) {
            // out-of-office: don't count as a reply, just give the carrier a few extra days
            if ($msg['lead_id']) $db->exec("UPDATE campaign_leads SET next_send_at = ? WHERE id = ? AND status = 'active'", [now(3 * 86400), $msg['lead_id']]);
            return;
        }

        // auto-label the lead from the reply text (never overwrites a label set by a person)
        if ($msg['contact_id']) {
            $label = self::classify((string)$r['body']);
            if ($label) $db->exec('UPDATE contacts SET label = ? WHERE id = ? AND label IS NULL', [$label, $msg['contact_id']]);
        }

        $lp = local_parts();
        if (!$msg['replied_at']) {
            $db->update('messages', ['replied_at' => now()], 'id = ?', [$msg['id']]);
            $db->insert('events', ['message_id' => $msg['id'], 'campaign_id' => $msg['campaign_id'], 'account_id' => $msg['account_id'], 'company_id' => $msg['company_id'], 'user_id' => $msg['user_id'], 'type' => 'reply', 'created_at' => now(), 'day' => $lp['day'], 'hour' => $lp['hour'], 'dow' => $lp['dow']]);
            bump_account_daily((int)$acc['id'], 'replies');
        }
        if ($msg['contact_id']) {
            // stop every active sequence to this carrier in this company
            $db->exec("UPDATE campaign_leads SET status = 'replied', next_send_at = NULL WHERE contact_id = ? AND status IN ('pending','active','sending')", [$msg['contact_id']]);
            if ($msg['lead_id']) $db->exec("UPDATE campaign_leads SET status = 'replied', next_send_at = NULL WHERE id = ?", [$msg['lead_id']]);
            $first = strtolower(trim(strtok(trim((string)$r['body']), "\n") ?: ''));
            if (preg_match('/^(unsubscribe|remove( me)?|stop|take me off|opt out|do not (email|contact))\b/', $first)) {
                $db->exec("UPDATE contacts SET status = 'unsubscribed' WHERE id = ?", [$msg['contact_id']]);
                suppress($r['from_email'], (int)$msg['company_id'], 'unsubscribe');
                $db->update('messages', ['unsubscribed_at' => now()], 'id = ? AND unsubscribed_at IS NULL', [$msg['id']]);
            }
        }
    }

    /** Keyword-based reply classifier → lead label (or null when unsure). */
    public static function classify(string $text): ?string
    {
        $t = strtolower(MailParser::stripQuoted($text));
        $t = mb_substr(preg_replace('/\s+/', ' ', $t), 0, 600);
        if ($t === '') return null;
        $rules = [
            'wrong_contact' => '/\b(wrong (person|contact|email)|not the (owner|right person)|no longer (with|work)|left the company|i\'?m not the|contact (my|the) (boss|owner|office))\b/',
            'not_interested' => '/\b(not interested|no thanks?|no thank you|we(\'re| are) (good|all set|fine)|already have (a )?dispatch(er)?|don\'?t need|do not need|not looking|not at this time|pass on this|stop (emailing|sending))\b/',
            'signed_up' => '/\b(send (me )?(the )?(agreement|contract|paperwork|setup|onboarding)|let\'?s (get started|do it|start)|sign (me )?up|ready to (start|go))\b/',
            'call_back' => '/(\bcall me\b|\bgive me a call\b|\bcall back\b|\breach me\b|\bmy (cell|number|phone)\b|\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4})/',
            'interested' => '/\b(interested|sounds good|tell me more|more info(rmation)?|how much|what (is|are|\'s) your (rate|rates|fee|fees|percentage|percent)|what percent|how does (it|this) work|let\'?s talk|send (me )?(the )?(details|info|rates)|yes)\b/',
        ];
        foreach ($rules as $label => $re) {
            if (preg_match($re, $t)) return $label;
        }
        return null;
    }

    private static function handleBounce(ImapClient $imap, array $acc, int $uid, array $h): bool
    {
        $db = db();
        $raw = $imap->fetchRaw($uid, 120000);
        $imap->addFlags([$uid], '\\Seen');
        $recipient = '';
        if (!empty($h['x-failed-recipients'])) $recipient = strtolower(trim(explode(',', $h['x-failed-recipients'])[0]));
        if (!$recipient && preg_match('/Final-Recipient:\s*rfc822;\s*<?([^\s>]+@[^\s>]+)/i', $raw, $m)) $recipient = strtolower($m[1]);
        if (!$recipient && preg_match('/(?:wasn\'t delivered to|could not be delivered to|delivery to)\s+<?([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $raw, $m)) $recipient = strtolower($m[1]);

        preg_match_all('/Message-ID:\s*(<[^>]+>)/i', $raw, $mm);
        $msg = null;
        if ($mm[1]) {
            $msg = $db->row('SELECT * FROM messages WHERE account_id = ? AND message_id IN (' . DB::in($mm[1]) . ') LIMIT 1', array_merge([$acc['id']], $mm[1]));
        }
        if (!$msg && $recipient) {
            $msg = $db->row('SELECT * FROM messages WHERE account_id = ? AND to_email = ? ORDER BY sent_at DESC LIMIT 1', [$acc['id'], $recipient]);
        }
        if (!$msg || $msg['bounced_at']) return false;

        $soft = preg_match('/Status:\s*4\.\d+\.\d+/i', $raw) && !preg_match('/Status:\s*5\.\d+\.\d+/i', $raw);
        if ($soft || preg_match('/(mailbox (is )?full|over quota|temporarily|try again later)/i', $raw) && !preg_match('/Status:\s*5\./i', $raw)) {
            return false; // soft bounce – do not suppress
        }
        $lp = local_parts();
        $db->update('messages', ['bounced_at' => now(), 'status' => 'bounced'], 'id = ?', [$msg['id']]);
        $db->insert('events', ['message_id' => $msg['id'], 'campaign_id' => $msg['campaign_id'], 'account_id' => $msg['account_id'], 'company_id' => $msg['company_id'], 'user_id' => $msg['user_id'], 'type' => 'bounce', 'created_at' => now(), 'day' => $lp['day'], 'hour' => $lp['hour'], 'dow' => $lp['dow']]);
        if ($msg['lead_id']) $db->exec("UPDATE campaign_leads SET status = 'bounced', next_send_at = NULL WHERE id = ?", [$msg['lead_id']]);
        if ($msg['contact_id']) {
            $db->exec("UPDATE contacts SET status = 'bounced', verify_status = 'invalid', verify_reason = 'Hard bounce – address does not exist', verified_at = ? WHERE id = ?", [now(), $msg['contact_id']]);
            $db->exec("UPDATE campaign_leads SET status = 'bounced', next_send_at = NULL WHERE contact_id = ? AND status IN ('pending','active')", [$msg['contact_id']]);
        }
        suppress($msg['to_email'], (int)$msg['company_id'], 'bounce');
        bump_account_daily((int)$acc['id'], 'bounces');
        return true;
    }
}
