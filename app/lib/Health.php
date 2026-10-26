<?php
declare(strict_types=1);

/**
 * Account health / reputation scoring and safe sending-limit suggestions.
 *
 * Score (0-100):
 *   45  inbox placement of this account's warmup + placement-test emails (last 7 days)
 *   25  campaign bounce rate (last 14 days)
 *   15  connection (SMTP/IMAP working)
 *   15  maturity (days warmed up)
 *  -10  penalty for high unsubscribe rate
 */
final class Health
{
    public static function compute(array $acc): array
    {
        $db = db();
        $id = (int)$acc['id'];
        $since7 = now(-7 * 86400);
        $since14 = now(-14 * 86400);

        $w = $db->row("SELECT SUM(CASE WHEN landed = 'inbox' THEN 1 ELSE 0 END) AS inbox, SUM(CASE WHEN landed = 'spam' THEN 1 ELSE 0 END) AS spam
            FROM warmup_messages WHERE from_account_id = ? AND sent_at >= ?", [$id, $since7]);
        $p = $db->row("SELECT SUM(CASE WHEN r.landed = 'inbox' THEN 1 ELSE 0 END) AS inbox, SUM(CASE WHEN r.landed = 'spam' THEN 1 ELSE 0 END) AS spam
            FROM placement_results r JOIN placement_tests t ON t.id = r.test_id WHERE t.account_id = ? AND t.created_at >= ?", [$id, $since7]);
        $inbox = (int)$w['inbox'] + (int)$p['inbox'];
        $spam = (int)$w['spam'] + (int)$p['spam'];
        $samples = $inbox + $spam;
        $inboxRate = $samples ? $inbox / $samples : null;

        $m = $db->row("SELECT COUNT(*) AS sent, SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) AS bounced,
            SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubs, SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) AS replies
            FROM messages WHERE account_id = ? AND kind = 'campaign' AND sent_at >= ?", [$id, $since14]);
        $sent = (int)$m['sent'];
        $bounceRate = $sent ? (int)$m['bounced'] / $sent : 0.0;
        $unsubRate = $sent ? (int)$m['unsubs'] / $sent : 0.0;

        $placementPts = $samples >= 5 ? 45 * $inboxRate : 35;
        $bouncePts = $sent >= 10 ? max(0, 25 - $bounceRate * 100 * 3.125) : 25;
        $connPts = $acc['status'] === 'error' ? 0 : 15;
        $days = self::warmDays($acc);
        $maturityPts = min(15, $days * 15 / 21);
        $penalty = ($sent >= 30 && $unsubRate > 0.02) ? 10 : 0;
        $score = (int)round(max(0, min(100, $placementPts + $bouncePts + $connPts + $maturityPts - $penalty)));

        $spamRate = $samples ? $spam / $samples : 0.0;
        if ($acc['status'] === 'error') $status = 'error';
        elseif ($samples >= 5 && $spamRate >= 0.25) $status = 'critical';
        elseif ($score >= 80) $status = 'excellent';
        elseif ($score >= 65) $status = 'good';
        elseif ($score >= 45) $status = 'at_risk';
        else $status = 'critical';
        if ($status !== 'critical' && $status !== 'error' && $samples < 5 && $days < 7) $status = 'new';
        if ($samples >= 5 && $spamRate >= 0.10 && in_array($status, ['excellent', 'good'], true)) $status = 'at_risk';
        if ($sent >= 20 && $bounceRate >= 0.08) $status = 'critical';
        // keep the number consistent with the status shown to the user
        if ($status === 'critical') $score = min($score, 40);
        elseif ($status === 'at_risk') $score = min($score, 64);

        $tips = [];
        if ($samples >= 5 && $spamRate >= 0.10) $tips[] = sprintf('%.0f%% of this account\'s emails landed in spam this week. Lower volume, keep warmup on and simplify your copy (fewer links, no images).', $spamRate * 100);
        if ($sent >= 10 && $bounceRate >= 0.03) $tips[] = sprintf('Bounce rate is %.1f%%. Clean your list – remove invalid/old carrier emails (keep under 2%%).', $bounceRate * 100);
        if ($days < 14) $tips[] = 'Account is still warming up (' . $days . ' days). Keep campaign volume low until day 14+.';
        if ($acc['status'] === 'error') $tips[] = 'Connection error: ' . ($acc['last_error'] ?: 'check the app password.');
        if ($sent >= 30 && $unsubRate > 0.02) $tips[] = 'High unsubscribe rate – tighten targeting and personalise more.';
        if (!$tips) $tips[] = 'Healthy. Keep sending steady, consistent volume.';

        $data = [
            'inbox' => $inbox, 'spam' => $spam, 'samples' => $samples,
            'inbox_rate' => $inboxRate !== null ? round($inboxRate * 100, 1) : null,
            'spam_rate' => round($spamRate * 100, 1),
            'sent14' => $sent, 'bounce_rate' => round($bounceRate * 100, 2), 'unsub_rate' => round($unsubRate * 100, 2),
            'reply_rate' => $sent ? round((int)$m['replies'] / $sent * 100, 1) : 0,
            'days' => $days, 'tips' => $tips,
            'parts' => ['placement' => round($placementPts, 1), 'bounce' => round($bouncePts, 1), 'connection' => $connPts, 'maturity' => round($maturityPts, 1), 'penalty' => $penalty],
        ];
        $data['suggested_limit'] = self::suggestedLimit($acc, $status, $days);

        // automatic protection
        $pauseReason = null;
        if ($status === 'critical') {
            $pauseReason = $sent >= 20 && $bounceRate >= 0.08
                ? sprintf('Auto-paused: bounce rate %.1f%% (limit 8%%)', $bounceRate * 100)
                : sprintf('Auto-paused: %.0f%% of emails landing in spam. Warmup continues to recover reputation.', $spamRate * 100);
        }
        $db->update('email_accounts', [
            'health_score' => $score,
            'health_status' => $status,
            'health_data' => json_encode($data),
            'send_paused_reason' => $pauseReason,
        ], 'id = ?', [$id]);
        if ($pauseReason && !$acc['send_paused_reason']) {
            app_log("Account {$acc['email']} $pauseReason");
        }
        return ['score' => $score, 'status' => $status] + $data;
    }

    public static function warmDays(array $acc): int
    {
        $start = $acc['warmup_started_at'] ?: $acc['created_at'];
        return max(0, (int)floor((time() - strtotime($start . ' UTC')) / 86400));
    }

    /** Safe daily campaign volume for this account. */
    public static function suggestedLimit(array $acc, string $status, int $days): int
    {
        $workspace = !is_free_gmail($acc['email']);
        if ($days < 7) $base = 5;
        elseif ($days < 14) $base = 15;
        elseif ($days < 21) $base = 25;
        elseif ($days < 30) $base = 35;
        else $base = $workspace ? 50 : 40;
        $mult = ['excellent' => 1.0, 'good' => 0.8, 'new' => 1.0, 'at_risk' => 0.5, 'critical' => 0.0, 'error' => 0.0][$status] ?? 0.8;
        return (int)floor($base * $mult);
    }

    /** Limit actually enforced by the sender. */
    public static function effectiveLimit(array $acc): int
    {
        if ($acc['send_paused_reason'] || $acc['status'] !== 'active') return 0;
        $limit = (int)$acc['daily_limit'];
        if ((int)$acc['auto_limit'] === 1) {
            $data = json_decode((string)$acc['health_data'], true) ?: [];
            $sug = $data['suggested_limit'] ?? self::suggestedLimit($acc, $acc['health_status'] ?: 'new', self::warmDays($acc));
            $limit = min($limit, (int)$sug);
        }
        $hard = is_free_gmail($acc['email']) ? 400 : 1500; // stay well under Google's 500 / 2000 caps
        return max(0, min($limit, $hard - (int)$acc['warmup_sent_today']));
    }

    public static function warmupTarget(array $acc): int
    {
        if (!(int)$acc['warmup_enabled']) return 0;
        $days = self::warmDays($acc);
        $target = (int)min((int)$acc['warmup_max'], (int)$acc['warmup_start'] + $days * (int)$acc['warmup_increase']);
        // real business inboxes are quieter on weekends
        if ((int)(new DateTime('now', app_tz()))->format('N') >= 6) $target = max(2, (int)ceil($target * 0.6));
        return $target;
    }
}
