<?php
declare(strict_types=1);

/**
 * Warmup network: connected accounts email each other with natural, varied conversations.
 * Receivers (via IMAP, see Mailbox) rescue them from spam, mark read/important and reply,
 * which builds sender reputation with Gmail. Placement results feed the health score.
 */
final class Warmup
{
    private const SUBJECTS = [
        'Quick question about {next week|tomorrow|Friday}', 'Following up on our {call|chat|conversation}', 'Lane update for {Monday|Tuesday|Wednesday|Thursday}',
        '{Schedule|Plan} for the {week|next few days}', 'Re: rate confirmation', 'Paperwork for {the|this} load', 'Checking in', 'Thoughts on the {new|updated} route?',
        'Notes from {today|this morning}', '{Quick|Small} update', 'Pickup time {confirmed|update}', 'Can we talk {tomorrow|later today}?', 'Invoice {question|follow up}',
        'Delivery window', 'Driver availability {this week|next week}', '{Meeting|Call} {recap|notes}', 'Insurance certificate', 'About the {Dallas|Atlanta|Chicago|Phoenix|Denver} lane',
        'Weekend coverage', 'Fuel surcharge {question|update}', 'Thanks for {today|the help}', 'New {broker|shipper} setup', 'Lunch {next week|Thursday}?', 'Document request',
    ];
    private const OPENERS = ['Hi {there|team}', 'Hello', 'Hey', 'Good {morning|afternoon}', 'Hi again'];
    private const LINES = [
        'Just wanted to {check in|follow up} on {the schedule|what we discussed} {yesterday|earlier this week}.',
        'Can you {confirm|double check} the {pickup|delivery} time {when you get a chance|today}?',
        'I {updated|went through} the {notes|numbers} and {everything looks good|it all lines up}.',
        'The driver {should|will} be there {around|by} {8|9|10|11} {AM|in the morning}.',
        'Let me know if {anything changes|you need anything else} on your end.',
        'We {have|might have} {two|a couple of|three} trucks {open|available} {next week|on Thursday}.',
        'I {sent over|attached a note about} the {paperwork|documents} {earlier|this morning}, {please take a look|let me know if it came through}.',
        'Thanks again for {the quick turnaround|your help on this|getting back to me}.',
        'Are you {free|available} for a {quick|short} call {tomorrow|later this week}?',
        'The {rate|quote} we talked about {still works|works} for us.',
        'Traffic {was|has been} {heavy|rough} {near the yard|on the highway} {today|this week}, so plan for a little extra time.',
        'I will {circle back|follow up} {once I hear back|after I talk to dispatch}.',
        '{Weather|The forecast} {looks|seems} {clear|fine} for the {route|trip} {this week|on Wednesday}.',
        'We {finished|wrapped up} the {setup|onboarding} {for the new lane|yesterday}.',
        'Could you {share|send} the {updated|latest} {contact list|schedule|numbers} when {ready|you can}?',
    ];
    private const CLOSERS = ['Thanks', 'Thanks!', 'Best', 'Regards', 'Talk soon', 'Appreciate it', 'Cheers'];
    private const REPLIES = [
        '{Thanks|Thank you}, {got it|received}. {I will take a look|Will review} {today|shortly}.',
        '{Sounds good|Perfect|Great}, {that works for me|let\'s do that}.',
        '{Confirmed|All set} on my side. {Thanks for the heads up|Appreciate the update}.',
        '{Yes|Sure}, {tomorrow|Thursday} {works|is fine}. {I will call you around noon|Let\'s talk in the morning}.',
        '{Thanks for checking in|Good to hear from you}. {Everything looks good here|No changes on our side}.',
        '{Got it|Noted}, I will {pass this along|let the team know}.',
    ];

    public static function compose(array $from): array
    {
        $first = explode(' ', trim((string)$from['from_name']))[0] ?: 'Mike';
        $subject = Template::spin(self::SUBJECTS[array_rand(self::SUBJECTS)]);
        $lines = self::LINES;
        shuffle($lines);
        $body = Template::spin(self::OPENERS[array_rand(self::OPENERS)]) . ",\n\n";
        $body .= Template::spin(implode(' ', array_slice($lines, 0, random_int(2, 4)))) . "\n\n";
        $body .= self::CLOSERS[array_rand(self::CLOSERS)] . ",\n" . $first;
        return [$subject, $body];
    }

    public static function composeReply(array $from): string
    {
        $first = explode(' ', trim((string)$from['from_name']))[0] ?: '';
        return Template::spin(self::REPLIES[array_rand(self::REPLIES)]) . "\n\n" . self::CLOSERS[array_rand(self::CLOSERS)] . ($first ? ",\n" . $first : '');
    }

    /** Send due warmup emails. Returns number sent. */
    public static function run(int $deadline): int
    {
        if (setting('warmup_paused') === '1') return 0;
        $db = db();
        $pool = $db->all("SELECT * FROM email_accounts WHERE warmup_enabled = 1 AND status = 'active'");
        if (count($pool) < 2) return 0;
        $tz = app_tz();
        $nowLocal = new DateTime('now', $tz);
        $hour = (int)$nowLocal->format('G');
        $startH = (int)setting('warmup_start_hour', 7);
        $endH = (int)setting('warmup_end_hour', 20);
        if ($hour < $startH || $hour >= $endH) return 0;
        $endOfWindow = (clone $nowLocal)->setTime($endH, 0)->getTimestamp();

        $sent = 0;
        shuffle($pool);
        foreach ($pool as $acc) {
            if (time() > $deadline) break;
            $target = Health::warmupTarget($acc);
            $done = (int)$acc['warmup_sent_today'];
            if ($done >= $target) continue;
            if ($acc['warmup_next_at'] && strtotime($acc['warmup_next_at'] . ' UTC') > time()) continue;

            $peers = array_values(array_filter($pool, fn($p) => (int)$p['id'] !== (int)$acc['id']));
            // prefer peers on other domains / companies to look natural
            usort($peers, fn($a, $b) => (email_domain($a['email']) === email_domain($acc['email'])) <=> (email_domain($b['email']) === email_domain($acc['email'])));
            $peer = $peers[random_int(0, max(0, min(count($peers), 6) - 1))];

            [$subject, $body] = self::compose($acc);
            $token = random_token(20);
            try {
                $mid = Mailer::sendFromAccount($acc, [
                    'to_email' => $peer['email'], 'to_name' => $peer['from_name'],
                    'subject' => $subject, 'text' => $body, 'html' => null,
                    'headers' => ['X-Runnex-Warmup' => $token],
                ]);
                $db->insert('warmup_messages', [
                    'from_account_id' => $acc['id'], 'to_account_id' => $peer['id'], 'token' => $token,
                    'message_id' => $mid, 'subject' => $subject, 'is_reply' => 0, 'sent_at' => now(),
                ]);
                $remaining = max(1, $target - $done - 1);
                $gap = max(300, (int)(($endOfWindow - time()) / ($remaining + 1)));
                $next = (int)($gap * (mt_rand(60, 140) / 100));
                $db->exec('UPDATE email_accounts SET warmup_sent_today = warmup_sent_today + 1, warmup_next_at = ? WHERE id = ?', [now($next), $acc['id']]);
                bump_account_daily((int)$acc['id'], 'warmup_sent');
                $sent++;
            } catch (SmtpException $e) {
                self::handleSendError($acc, $e);
            } catch (Throwable $e) {
                app_log('Warmup error ' . $acc['email'] . ': ' . $e->getMessage());
            }
        }
        return $sent;
    }

    public static function reply(array $acc, array $original, array $headers): void
    {
        $token = random_token(20);
        $subject = 'Re: ' . preg_replace('/^(re:\s*)+/i', '', $headers['subject'] ?? '');
        $mid = Mailer::sendFromAccount($acc, [
            'to_email' => $original['from_email'], 'to_name' => $original['from_name'],
            'subject' => $subject, 'text' => self::composeReply($acc), 'html' => null,
            'headers' => ['X-Runnex-Warmup' => $token],
            'in_reply_to' => $headers['message-id'] ?? null,
            'references' => $headers['message-id'] ?? null,
        ]);
        db()->insert('warmup_messages', [
            'from_account_id' => $acc['id'], 'to_account_id' => $original['from_account_id'], 'token' => $token,
            'message_id' => $mid, 'subject' => $subject, 'is_reply' => 1, 'sent_at' => now(),
        ]);
        db()->exec('UPDATE email_accounts SET warmup_sent_today = warmup_sent_today + 1 WHERE id = ?', [$acc['id']]);
        bump_account_daily((int)$acc['id'], 'warmup_sent');
    }

    public static function handleSendError(array $acc, SmtpException $e): void
    {
        $db = db();
        if (in_array($e->kind, ['auth', 'connect'], true)) {
            $db->update('email_accounts', ['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()], 'id = ?', [$acc['id']]);
        } elseif ($e->kind === 'quota') {
            $db->update('email_accounts', ['next_send_at' => now(86400), 'warmup_next_at' => now(86400), 'last_error' => 'Google sending limit reached: ' . mb_substr($e->getMessage(), 0, 300)], 'id = ?', [$acc['id']]);
        } else {
            $db->update('email_accounts', ['warmup_next_at' => now(900), 'last_error' => mb_substr($e->getMessage(), 0, 500)], 'id = ?', [$acc['id']]);
        }
        app_log('SMTP ' . $e->kind . ' ' . $acc['email'] . ': ' . $e->getMessage());
    }
}
