<?php
declare(strict_types=1);

/** Aggregations over the messages / events tables. $where always starts with " AND ..." and targets alias m / e. */
final class Stats
{
    public static function summary(string $where, array $params): array
    {
        $r = db()->row("SELECT COUNT(*) AS sent,
            SUM(CASE WHEN m.bounced_at IS NOT NULL THEN 1 ELSE 0 END) AS bounced,
            SUM(CASE WHEN m.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
            SUM(m.open_count) AS opens_total,
            SUM(CASE WHEN m.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
            SUM(CASE WHEN m.replied_at IS NOT NULL THEN 1 ELSE 0 END) AS replied,
            SUM(CASE WHEN m.unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsub
            FROM messages m WHERE m.kind = 'campaign' $where", $params);
        $s = array_map('intval', $r ?: []);
        $s['delivered'] = max(0, $s['sent'] - $s['bounced']);
        $d = $s['delivered'];
        $s['open_rate'] = pct($s['opened'], $d);
        $s['click_rate'] = pct($s['clicked'], $d);
        $s['reply_rate'] = pct($s['replied'], $d);
        $s['bounce_rate'] = pct($s['bounced'], $s['sent']);
        $s['unsub_rate'] = pct($s['unsub'], $d);
        $s['delivery_rate'] = pct($d, $s['sent']);
        $s['click_to_open'] = pct($s['clicked'], $s['opened']);
        $s['avg_opens'] = $s['opened'] ? round($s['opens_total'] / $s['opened'], 1) : 0;
        return $s;
    }

    /** Daily series keyed by local day between $from and $to (Y-m-d). */
    public static function daily(string $where, array $params, string $from, string $to): array
    {
        $rows = db()->all("SELECT m.sent_day AS d, COUNT(*) AS sent,
            SUM(CASE WHEN m.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
            SUM(CASE WHEN m.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
            SUM(CASE WHEN m.replied_at IS NOT NULL THEN 1 ELSE 0 END) AS replied,
            SUM(CASE WHEN m.bounced_at IS NOT NULL THEN 1 ELSE 0 END) AS bounced
            FROM messages m WHERE m.kind = 'campaign' AND m.sent_day >= ? AND m.sent_day <= ? $where GROUP BY m.sent_day", array_merge([$from, $to], $params));
        $by = [];
        foreach ($rows as $r) $by[$r['d']] = $r;
        $out = ['labels' => [], 'sent' => [], 'opened' => [], 'clicked' => [], 'replied' => [], 'bounced' => []];
        $d = new DateTime($from);
        $end = new DateTime($to);
        $guard = 0;
        while ($d <= $end && $guard++ < 400) {
            $k = $d->format('Y-m-d');
            $out['labels'][] = $d->format('M j');
            foreach (['sent', 'opened', 'clicked', 'replied', 'bounced'] as $f) $out[$f][] = (int)($by[$k][$f] ?? 0);
            $d->modify('+1 day');
        }
        return $out;
    }

    /** Breakdown grouped by a messages column (campaign_id, account_id, user_id, company_id, step_no). */
    public static function grouped(string $col, string $where, array $params): array
    {
        $rows = db()->all("SELECT m.$col AS k, COUNT(*) AS sent,
            SUM(CASE WHEN m.bounced_at IS NOT NULL THEN 1 ELSE 0 END) AS bounced,
            SUM(CASE WHEN m.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
            SUM(CASE WHEN m.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
            SUM(CASE WHEN m.replied_at IS NOT NULL THEN 1 ELSE 0 END) AS replied,
            SUM(CASE WHEN m.unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsub
            FROM messages m WHERE m.kind = 'campaign' $where GROUP BY m.$col ORDER BY sent DESC", $params);
        foreach ($rows as &$r) {
            $d = max(0, (int)$r['sent'] - (int)$r['bounced']);
            $r['open_rate'] = pct($r['opened'], $d);
            $r['click_rate'] = pct($r['clicked'], $d);
            $r['reply_rate'] = pct($r['replied'], $d);
            $r['bounce_rate'] = pct($r['bounced'], $r['sent']);
        }
        return $rows;
    }

    /** 7x24 matrix of event counts (dow 1-7, hour 0-23). */
    public static function heatmap(string $type, string $where, array $params): array
    {
        $grid = array_fill(1, 7, array_fill(0, 24, 0));
        foreach (db()->all("SELECT e.dow, e.hour, COUNT(*) AS n FROM events e WHERE e.type = ? $where GROUP BY e.dow, e.hour", array_merge([$type], $params)) as $r) {
            $grid[(int)$r['dow']][(int)$r['hour']] = (int)$r['n'];
        }
        return $grid;
    }

    public static function heatmapHtml(array $grid): string
    {
        $max = 1;
        foreach ($grid as $row) $max = max($max, max($row));
        $days = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $h = '<div class="heatmap"><div class="hm-row hm-head"><span></span>';
        for ($i = 0; $i < 24; $i++) $h .= '<span>' . ($i % 3 === 0 ? date('ga', mktime($i, 0, 0, 1, 1, 2020)) : '') . '</span>';
        $h .= '</div>';
        foreach ($grid as $dow => $row) {
            $h .= '<div class="hm-row"><span class="hm-day">' . $days[$dow] . '</span>';
            foreach ($row as $hour => $n) {
                $a = $n ? 0.12 + 0.88 * ($n / $max) : 0;
                $h .= '<span class="hm-cell" style="--a:' . round($a, 2) . '" title="' . $days[$dow] . ' ' . date('ga', mktime($hour, 0, 0, 1, 1, 2020)) . ': ' . $n . '"></span>';
            }
            $h .= '</div>';
        }
        return $h . '</div>';
    }
}
