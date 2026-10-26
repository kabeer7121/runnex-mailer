<?php
declare(strict_types=1);

/** Background worker – called every minute by cron.php. */
final class Engine
{
    public static function tick(int $deadline): array
    {
        $out = [];
        set_setting('cron_last_run', now());
        self::resetDaily();
        self::activateScheduled();
        $out['campaign_sent'] = self::sendCampaigns($deadline - 35);
        $out['warmup_sent'] = Warmup::run($deadline - 30);
        $out['mailboxes_checked'] = Mailbox::run($deadline - 12);
        if (time() < $deadline - 5) $out['campaign_sent'] += self::sendCampaigns($deadline - 3);
        self::finishPlacementTests();
        self::campaignGuard();
        self::completeCampaigns();
        if (time() < $deadline) self::retestErrorAccounts($deadline);
        if ((int)setting('autosync_last_ts', 0) < time() - 3600) {
            foreach (db()->all("SELECT * FROM campaigns WHERE status IN ('running','scheduled')") as $c) {
                try {
                    self::syncLeads($c);
                } catch (Throwable $e) {
                    app_log('Auto-sync campaign ' . $c['id'] . ': ' . $e->getMessage());
                }
            }
            set_setting('autosync_last_ts', time());
        }
        // background list verification (new imports get verified automatically)
        if (time() < $deadline) {
            try {
                $out['verified'] = Verifier::runBatch($deadline, [], 300);
            } catch (Throwable $e) {
                app_log('Verify batch: ' . $e->getMessage());
            }
        }
        if ((int)setting('health_last_run_ts', 0) < time() - 1200) {
            foreach (db()->all('SELECT * FROM email_accounts') as $acc) {
                try {
                    Health::compute($acc);
                } catch (Throwable $e) {
                    app_log('Health ' . $acc['email'] . ': ' . $e->getMessage());
                }
            }
            set_setting('health_last_run_ts', time());
        }
        if ((int)setting('cleanup_last_ts', 0) < time() - 86400) {
            self::cleanup();
            set_setting('cleanup_last_ts', time());
        }
        return $out;
    }

    private static function resetDaily(): void
    {
        $today = local_today();
        db()->exec('UPDATE email_accounts SET sent_today = 0, warmup_sent_today = 0, counters_day = ? WHERE counters_day IS NULL OR counters_day <> ?', [$today, $today]);
    }

    private static function activateScheduled(): void
    {
        db()->exec("UPDATE campaigns SET status = 'running', started_at = ? WHERE status = 'scheduled' AND (scheduled_at IS NULL OR scheduled_at <= ?)", [now(), now()]);
    }

    /** Is this campaign inside its sending window right now? */
    public static function inWindow(array $c): bool
    {
        try {
            $tz = new DateTimeZone($c['timezone']);
        } catch (Throwable $e) {
            $tz = app_tz();
        }
        $d = new DateTime('now', $tz);
        $days = array_map('intval', explode(',', (string)$c['send_days']));
        if (!in_array((int)$d->format('N'), $days, true)) return false;
        $hm = $d->format('H:i');
        return $hm >= $c['window_start'] && $hm < $c['window_end'];
    }

    public static function sendCampaigns(int $deadline): int
    {
        $db = db();
        $campaigns = [];
        foreach ($db->all("SELECT * FROM campaigns WHERE status = 'running'") as $c) {
            if (self::inWindow($c)) $campaigns[(int)$c['id']] = $c;
        }
        if (!$campaigns) return 0;
        $cids = array_keys($campaigns);

        $accounts = $db->all("SELECT a.* FROM email_accounts a WHERE a.status = 'active' AND a.send_paused_reason IS NULL
            AND (a.next_send_at IS NULL OR a.next_send_at <= ?)
            AND a.id IN (SELECT account_id FROM campaign_accounts WHERE campaign_id IN (" . DB::in($cids) . '))', array_merge([now()], $cids));
        shuffle($accounts);
        $sent = 0;
        $todayDay = local_today();
        $campaignSentToday = [];

        foreach ($accounts as $acc) {
            if (time() > $deadline) break;
            if ((int)$acc['sent_today'] >= Health::effectiveLimit($acc)) continue;

            $accCampaigns = array_values(array_intersect($cids, array_map('intval', $db->col('SELECT campaign_id FROM campaign_accounts WHERE account_id = ?', [$acc['id']]))));
            if (!$accCampaigns) continue;
            // respect per-campaign daily caps
            $accCampaigns = array_values(array_filter($accCampaigns, function ($cid) use ($campaigns, &$campaignSentToday, $db, $todayDay) {
                $cap = (int)$campaigns[$cid]['daily_limit'];
                if ($cap <= 0) return true;
                if (!isset($campaignSentToday[$cid])) {
                    $campaignSentToday[$cid] = (int)$db->val("SELECT COUNT(*) FROM messages WHERE campaign_id = ? AND sent_day = ? AND kind = 'campaign'", [$cid, $todayDay]);
                }
                return $campaignSentToday[$cid] < $cap;
            }));
            if (!$accCampaigns) continue;

            $in = DB::in($accCampaigns);
            // follow-ups owned by this account first, then fresh leads
            $lead = $db->row("SELECT * FROM campaign_leads WHERE campaign_id IN ($in) AND account_id = ? AND status = 'active' AND next_send_at <= ? ORDER BY next_send_at LIMIT 1", array_merge($accCampaigns, [$acc['id'], now()]));
            if (!$lead) {
                $lead = $db->row("SELECT * FROM campaign_leads WHERE campaign_id IN ($in) AND status = 'pending' AND (account_id IS NULL OR account_id = ?) AND (next_send_at IS NULL OR next_send_at <= ?) ORDER BY id LIMIT 1", array_merge($accCampaigns, [$acc['id'], now()]));
            }
            if (!$lead) continue;

            // claim atomically so overlapping workers never double-send
            $claimed = $db->exec("UPDATE campaign_leads SET status = 'sending', account_id = ? WHERE id = ? AND status = ?", [$acc['id'], $lead['id'], $lead['status']]);
            if (!$claimed) continue;
            $lead['account_id'] = $acc['id'];
            $campaign = $campaigns[(int)$lead['campaign_id']];
            $result = self::sendLead($campaign, $lead, $acc);
            if ($result === 'sent') {
                $sent++;
                $campaignSentToday[(int)$campaign['id']] = ($campaignSentToday[(int)$campaign['id']] ?? 0) + 1;
            }
        }
        return $sent;
    }

    /** Returns sent | skipped | failed | account_error */
    public static function sendLead(array $campaign, array $lead, array $acc): string
    {
        $db = db();
        $restore = $lead['status'];
        $contact = $db->row('SELECT * FROM contacts WHERE id = ?', [$lead['contact_id']]);
        if (!$contact || $contact['status'] !== 'active' || is_suppressed($contact['email'], (int)$campaign['company_id'])) {
            $db->exec("UPDATE campaign_leads SET status = 'skipped', note = 'Unsubscribed, bounced or suppressed', next_send_at = NULL WHERE id = ?", [$lead['id']]);
            return 'skipped';
        }
        // email verification gate – never send to an invalid address (verifies on the spot if needed)
        try {
            [$allowed, $why] = Verifier::allowSend($contact);
        } catch (Throwable $e) {
            [$allowed, $why] = [true, ''];
            app_log('Verify before send ' . $contact['email'] . ': ' . $e->getMessage());
        }
        if (!$allowed) {
            $db->exec("UPDATE campaign_leads SET status = 'skipped', note = ?, next_send_at = NULL WHERE id = ?", [mb_substr($why, 0, 250), $lead['id']]);
            return 'skipped';
        }
        $stepNo = (int)$lead['current_step'];
        $step = $db->row('SELECT * FROM campaign_steps WHERE campaign_id = ? AND step_no = ?', [$campaign['id'], $stepNo]);
        if (!$step) {
            $db->exec("UPDATE campaign_leads SET status = 'completed', next_send_at = NULL WHERE id = ?", [$lead['id']]);
            return 'skipped';
        }
        if ($stepNo === 0 && (int)$campaign['skip_recent_days'] > 0 && $contact['last_contacted_at']
            && strtotime($contact['last_contacted_at'] . ' UTC') > time() - (int)$campaign['skip_recent_days'] * 86400) {
            $db->exec("UPDATE campaign_leads SET status = 'skipped', note = 'Contacted recently by another campaign', account_id = NULL, next_send_at = NULL WHERE id = ?", [$lead['id']]);
            return 'skipped';
        }
        // follow-up safety: never send a follow-up to someone who already replied
        if ($stepNo > 0) {
            $stop = self::replyGuard($campaign, $lead, $contact, $acc);
            if ($stop !== null) return $stop;
        }

        // never email the same address from two campaigns at once
        $other = $db->val("SELECT COUNT(*) FROM campaign_leads WHERE contact_id = ? AND id <> ? AND status = 'sending'", [$contact['id'], $lead['id']]);
        if ($other) {
            $db->exec('UPDATE campaign_leads SET status = ?, next_send_at = ? WHERE id = ?', [$restore, now(600), $lead['id']]);
            return 'skipped';
        }

        $company = $db->row('SELECT * FROM companies WHERE id = ?', [$campaign['company_id']]) ?: [];
        $token = random_token(24);
        $mail = Mailer::composeCampaign($campaign, $step, $lead, $contact, $acc, $company, $token);

        try {
            $mid = Mailer::sendFromAccount($acc, [
                'to_email' => $contact['email'],
                'to_name' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
                'subject' => $mail['subject'], 'html' => $mail['html'], 'text' => $mail['text'],
                'headers' => $mail['headers'], 'in_reply_to' => $mail['in_reply_to'], 'references' => $mail['references'],
            ]);
        } catch (SmtpException $e) {
            return self::handleFailure($campaign, $lead, $acc, $contact, $e, $restore);
        }

        $lp = local_parts();
        $db->insert('messages', [
            'company_id' => $campaign['company_id'], 'campaign_id' => $campaign['id'], 'step_no' => $stepNo, 'lead_id' => $lead['id'],
            'contact_id' => $contact['id'], 'account_id' => $acc['id'], 'user_id' => $campaign['user_id'], 'kind' => 'campaign',
            'to_email' => $contact['email'], 'subject' => mb_substr($mail['subject'], 0, 250), 'message_id' => $mid, 'token' => $token,
            'status' => 'sent', 'sent_at' => now(), 'sent_day' => $lp['day'],
        ]);
        $nextStep = $db->row('SELECT * FROM campaign_steps WHERE campaign_id = ? AND step_no = ?', [$campaign['id'], $stepNo + 1]);
        $db->update('campaign_leads', [
            'status' => $nextStep ? 'active' : 'completed',
            'current_step' => $stepNo + 1,
            'next_send_at' => $nextStep ? now(max(1, (int)$nextStep['delay_days']) * 86400 + random_int(0, 3600)) : null,
            'last_sent_at' => now(),
            'attempts' => 0,
            'note' => null,
            'thread_subject' => $stepNo === 0 ? mb_substr($mail['subject'], 0, 250) : ($lead['thread_subject'] ?: $mail['subject']),
            'last_message_id' => $mid,
        ], 'id = ?', [$lead['id']]);

        $delay = random_int(max(30, (int)$campaign['min_delay']), max(max(30, (int)$campaign['min_delay']), (int)$campaign['max_delay']));
        $db->exec('UPDATE email_accounts SET sent_today = sent_today + 1, last_sent_at = ?, next_send_at = ? WHERE id = ?', [now(), now($delay), $acc['id']]);
        $db->exec('UPDATE contacts SET last_contacted_at = ? WHERE id = ?', [now(), $contact['id']]);
        bump_account_daily((int)$acc['id'], 'sent');
        return 'sent';
    }

    /**
     * Checks done right before every follow-up. Returns null when it's safe to send,
     * otherwise a result string (the lead has been stopped or postponed).
     */
    private static function replyGuard(array $campaign, array $lead, array $contact, array $acc): ?string
    {
        $db = db();
        $hasReply = function () use ($db, $campaign, $lead, $contact): bool {
            // any real reply from this carrier to ANY account of this company since the sequence started
            return (bool)$db->val("SELECT COUNT(*) FROM replies WHERE company_id = ? AND direction = 'in' AND is_auto = 0 AND from_email = ? AND received_at >= ?",
                [$campaign['company_id'], strtolower($contact['email']), $lead['created_at']])
                || (bool)$db->val('SELECT COUNT(*) FROM messages WHERE contact_id = ? AND replied_at IS NOT NULL AND sent_at >= ?', [$contact['id'], $lead['created_at']]);
        };
        $stopReplied = function () use ($db, $lead, $contact): string {
            $db->exec("UPDATE campaign_leads SET status = 'replied', next_send_at = NULL, note = 'Replied – follow-ups stopped' WHERE id = ?", [$lead['id']]);
            $db->exec("UPDATE campaign_leads SET status = 'replied', next_send_at = NULL WHERE contact_id = ? AND status IN ('pending','active')", [$contact['id']]);
            return 'skipped';
        };
        if ($hasReply()) return $stopReplied();

        // make sure this inbox was checked very recently, so a reply that just arrived is seen first
        $fresh = $acc['imap_checked_at'] && strtotime($acc['imap_checked_at'] . ' UTC') > time() - 15 * 60;
        if (!$fresh) {
            try {
                Mailbox::check($db->row('SELECT * FROM email_accounts WHERE id = ?', [$acc['id']]) ?: $acc);
            } catch (Throwable $e) {
                // can't read the inbox → don't risk a follow-up; try again in 30 minutes
                app_log("Follow-up to {$contact['email']} postponed: inbox of {$acc['email']} could not be checked (" . $e->getMessage() . ')');
                $db->exec("UPDATE campaign_leads SET status = 'active', next_send_at = ?, note = 'Waiting: inbox could not be checked for replies' WHERE id = ?", [now(1800), $lead['id']]);
                $db->exec('UPDATE email_accounts SET imap_next_at = ? WHERE id = ?', [now(900), $acc['id']]);
                return 'skipped';
            }
            $status = $db->val('SELECT status FROM campaign_leads WHERE id = ?', [$lead['id']]);
            if ($status !== 'sending') return 'skipped'; // reply / bounce / unsubscribe found during the check
            if ($hasReply()) return $stopReplied();
        }
        return null;
    }

    private static function handleFailure(array $campaign, array $lead, array $acc, array $contact, SmtpException $e, string $restore): string
    {
        $db = db();
        $msg = mb_substr($e->getMessage(), 0, 500);
        app_log("Send fail [{$e->kind}] {$acc['email']} -> {$contact['email']}: $msg");
        $releaseAccount = (int)$lead['current_step'] === 0 ? null : $acc['id'];

        switch ($e->kind) {
            case 'auth':
            case 'connect':
                $db->update('email_accounts', ['status' => 'error', 'last_error' => $msg, 'updated_at' => now()], 'id = ?', [$acc['id']]);
                $db->update('campaign_leads', ['status' => $restore, 'account_id' => $releaseAccount], 'id = ?', [$lead['id']]);
                return 'account_error';
            case 'quota':
                $db->update('email_accounts', ['next_send_at' => now(86400), 'last_error' => 'Google sending limit reached – paused 24h. ' . $msg], 'id = ?', [$acc['id']]);
                $db->update('campaign_leads', ['status' => $restore, 'account_id' => $releaseAccount], 'id = ?', [$lead['id']]);
                return 'account_error';
            case 'recipient':
                $lp = local_parts();
                $mid = $db->insert('messages', [
                    'company_id' => $campaign['company_id'], 'campaign_id' => $campaign['id'], 'step_no' => $lead['current_step'], 'lead_id' => $lead['id'],
                    'contact_id' => $contact['id'], 'account_id' => $acc['id'], 'user_id' => $campaign['user_id'], 'kind' => 'campaign',
                    'to_email' => $contact['email'], 'subject' => null, 'token' => random_token(24), 'status' => 'bounced', 'error' => $msg,
                    'sent_at' => now(), 'sent_day' => $lp['day'], 'bounced_at' => now(),
                ]);
                $db->insert('events', ['message_id' => $mid, 'campaign_id' => $campaign['id'], 'account_id' => $acc['id'], 'company_id' => $campaign['company_id'], 'user_id' => $campaign['user_id'], 'type' => 'bounce', 'created_at' => now(), 'day' => $lp['day'], 'hour' => $lp['hour'], 'dow' => $lp['dow']]);
                $db->update('campaign_leads', ['status' => 'bounced', 'note' => mb_substr($msg, 0, 250), 'next_send_at' => null], 'id = ?', [$lead['id']]);
                $db->exec("UPDATE contacts SET status = 'bounced', verify_status = 'invalid', verify_reason = 'Hard bounce – address does not exist', verified_at = ? WHERE id = ?", [now(), $contact['id']]);
                suppress($contact['email'], (int)$campaign['company_id'], 'bounce');
                bump_account_daily((int)$acc['id'], 'bounces');
                $db->exec('UPDATE email_accounts SET sent_today = sent_today + 1, next_send_at = ? WHERE id = ?', [now(60), $acc['id']]);
                return 'failed';
            default: // transient / rejected
                $attempts = (int)$lead['attempts'] + 1;
                if ($attempts >= 3) {
                    $db->update('campaign_leads', ['status' => 'failed', 'note' => mb_substr($msg, 0, 250), 'attempts' => $attempts, 'next_send_at' => null], 'id = ?', [$lead['id']]);
                } else {
                    $db->update('campaign_leads', ['status' => $restore, 'account_id' => $releaseAccount, 'attempts' => $attempts, 'next_send_at' => now(900 * $attempts)], 'id = ?', [$lead['id']]);
                }
                $db->update('email_accounts', ['next_send_at' => now(300), 'last_error' => $msg], 'id = ?', [$acc['id']]);
                return 'failed';
        }
    }

    /** Add active, non-suppressed list members to the campaign queue. Returns number added. */
    public static function syncLeads(array $c): int
    {
        if (!$c['list_id']) return 0;
        $db = db();
        $rows = $db->all("SELECT c.id, c.email FROM list_contacts lc JOIN contacts c ON c.id = lc.contact_id
            WHERE lc.list_id = ? AND c.company_id = ? AND c.status = 'active'
            AND c.id NOT IN (SELECT contact_id FROM campaign_leads WHERE campaign_id = ?)", [$c['list_id'], $c['company_id'], $c['id']]);
        $added = 0;
        $db->pdo->beginTransaction();
        foreach ($rows as $r) {
            if (is_suppressed($r['email'], (int)$c['company_id'])) continue;
            $added += $db->insertIgnore('campaign_leads', ['campaign_id' => $c['id'], 'contact_id' => $r['id'], 'status' => 'pending', 'created_at' => now()]) ? 1 : 0;
        }
        $db->pdo->commit();
        return $added;
    }

    /** Pause a running campaign whose list is bouncing badly – protects every account in it. */
    private static function campaignGuard(): void
    {
        $db = db();
        foreach ($db->all("SELECT * FROM campaigns WHERE status = 'running'") as $c) {
            $r = $db->row("SELECT COUNT(*) AS sent, SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) AS b FROM messages WHERE campaign_id = ? AND kind = 'campaign' AND sent_at >= ?", [$c['id'], now(-7 * 86400)]);
            $sent = (int)$r['sent'];
            if ($sent >= 40 && (int)$r['b'] / $sent >= 0.05) {
                $note = sprintf('Auto-paused %s: %.1f%% of emails bounced in the last 7 days. Clean the list (remove old/invalid emails), then resume.', local_dt(now(), 'M j'), (int)$r['b'] / $sent * 100);
                $db->update('campaigns', ['status' => 'paused', 'auto_note' => $note], 'id = ?', [$c['id']]);
                app_log("Campaign {$c['id']} $note");
            }
        }
    }

    /** Accounts in "error" are re-tested every 3 hours and resume automatically once fixed. */
    private static function retestErrorAccounts(int $deadline): void
    {
        $db = db();
        foreach ($db->all("SELECT * FROM email_accounts WHERE status = 'error' AND (retest_at IS NULL OR retest_at <= ?) LIMIT 3", [now()]) as $acc) {
            if (time() > $deadline) break;
            $r = Mailbox::testConnection($acc);
            if ($r['ok']) {
                $db->update('email_accounts', ['status' => 'active', 'last_error' => null, 'retest_at' => null, 'next_send_at' => null], 'id = ?', [$acc['id']]);
                app_log("Account {$acc['email']} reconnected automatically");
            } else {
                $db->update('email_accounts', ['retest_at' => now(3 * 3600)], 'id = ?', [$acc['id']]);
            }
        }
    }

    private static function completeCampaigns(): void
    {
        $db = db();
        foreach ($db->all("SELECT id FROM campaigns WHERE status = 'running'") as $c) {
            $open = (int)$db->val("SELECT COUNT(*) FROM campaign_leads WHERE campaign_id = ? AND status IN ('pending','active','sending')", [$c['id']]);
            $total = (int)$db->val('SELECT COUNT(*) FROM campaign_leads WHERE campaign_id = ?', [$c['id']]);
            if ($total > 0 && $open === 0) {
                $db->update('campaigns', ['status' => 'completed', 'completed_at' => now()], 'id = ?', [$c['id']]);
            }
        }
        // leads stuck in "sending" (worker crashed) go back to the queue
        $db->exec("UPDATE campaign_leads SET status = CASE WHEN current_step = 0 THEN 'pending' ELSE 'active' END WHERE status = 'sending' AND (last_sent_at IS NULL OR last_sent_at < ?) AND (next_send_at IS NULL OR next_send_at < ?)", [now(-900), now(-900)]);
    }

    private static function finishPlacementTests(): void
    {
        $db = db();
        foreach ($db->all("SELECT * FROM placement_tests WHERE status = 'running'") as $t) {
            $pending = (int)$db->val("SELECT COUNT(*) FROM placement_results WHERE test_id = ? AND landed = 'pending'", [$t['id']]);
            if ($pending === 0 || strtotime($t['created_at'] . ' UTC') < time() - 3600) {
                $db->exec("UPDATE placement_results SET landed = 'missing' WHERE test_id = ? AND landed = 'pending'", [$t['id']]);
                $db->update('placement_tests', ['status' => 'done'], 'id = ?', [$t['id']]);
            }
        }
    }

    private static function cleanup(): void
    {
        $db = db();
        $db->exec('DELETE FROM warmup_messages WHERE sent_at < ?', [now(-60 * 86400)]);
        $db->exec('DELETE FROM login_attempts WHERE created_at < ?', [now(-86400)]);
        $db->exec('DELETE FROM activity_log WHERE created_at < ?', [now(-180 * 86400)]);
        foreach (glob(RX_ROOT . '/storage/imports/*') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) @unlink($f);
        }
        foreach (glob(RX_ROOT . '/storage/logs/*.log') ?: [] as $f) {
            if (filemtime($f) < time() - 30 * 86400) @unlink($f);
        }
    }
}
