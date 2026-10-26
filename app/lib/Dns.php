<?php
declare(strict_types=1);

/** Domain authentication + blacklist checks. */
final class Dns
{
    public static function check(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $res = ['domain' => $domain, 'managed' => false, 'checks' => []];
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $res['managed'] = true;
            $res['checks'][] = ['name' => 'SPF / DKIM / DMARC', 'ok' => true, 'info' => 'Managed by Google for @gmail.com addresses. Nothing to configure.'];
            return $res;
        }

        $txt = self::txt($domain);
        $spf = array_values(array_filter($txt, fn($t) => stripos($t, 'v=spf1') === 0));
        if (!$spf) {
            $res['checks'][] = ['name' => 'SPF', 'ok' => false, 'info' => 'No SPF record. Add TXT @ "v=spf1 include:_spf.google.com ~all"'];
        } elseif (count($spf) > 1) {
            $res['checks'][] = ['name' => 'SPF', 'ok' => false, 'info' => 'Multiple SPF records found – merge them into one.'];
        } else {
            $google = stripos($spf[0], '_spf.google.com') !== false;
            $res['checks'][] = ['name' => 'SPF', 'ok' => $google, 'info' => $spf[0] . ($google ? '' : ' — missing include:_spf.google.com')];
        }

        $dkim = self::txt('google._domainkey.' . $domain);
        $dk = array_values(array_filter($dkim, fn($t) => stripos($t, 'v=DKIM1') !== false || stripos($t, 'p=') !== false));
        $res['checks'][] = ['name' => 'DKIM (google selector)', 'ok' => (bool)$dk, 'info' => $dk ? 'DKIM key published.' : 'Not found. Google Admin → Apps → Gmail → Authenticate email → Generate & publish DKIM, then click "Start authentication".'];

        $dm = array_values(array_filter(self::txt('_dmarc.' . $domain), fn($t) => stripos($t, 'v=DMARC1') === 0));
        if ($dm) {
            preg_match('/p=(\w+)/i', $dm[0], $m);
            $res['checks'][] = ['name' => 'DMARC', 'ok' => true, 'info' => $dm[0] . (strtolower($m[1] ?? '') === 'none' ? ' (policy "none" is fine to start)' : '')];
        } else {
            $res['checks'][] = ['name' => 'DMARC', 'ok' => false, 'info' => 'No DMARC record. Add TXT _dmarc "v=DMARC1; p=none; rua=mailto:dmarc@' . $domain . '"'];
        }

        $mx = @dns_get_record($domain, DNS_MX) ?: [];
        $res['checks'][] = ['name' => 'MX', 'ok' => (bool)$mx, 'info' => $mx ? implode(', ', array_map(fn($r) => $r['target'], array_slice($mx, 0, 3))) : 'No MX records – replies cannot be received.'];

        foreach (['dbl.spamhaus.org' => 'Spamhaus DBL', 'multi.uribl.com' => 'URIBL', 'multi.surbl.org' => 'SURBL'] as $zone => $label) {
            $hit = @gethostbyname($domain . '.' . $zone);
            $listed = $hit !== $domain . '.' . $zone && str_starts_with($hit, '127.') && !str_starts_with($hit, '127.255.255.') && $hit !== '127.0.0.1';
            $res['checks'][] = ['name' => 'Blacklist: ' . $label, 'ok' => !$listed, 'info' => $listed ? 'LISTED (' . $hit . ') – request delisting before sending.' : 'Not listed'];
        }
        return $res;
    }

    private static function txt(string $host): array
    {
        $recs = @dns_get_record($host, DNS_TXT) ?: [];
        return array_map(fn($r) => $r['txt'] ?? implode('', $r['entries'] ?? []), $recs);
    }

    /** Cached MX check for import validation. */
    public static function hasMx(string $domain): bool
    {
        static $cache = [];
        if (!isset($cache[$domain])) {
            $cache[$domain] = @checkdnsrr($domain, 'MX') || @checkdnsrr($domain, 'A');
        }
        return $cache[$domain];
    }
}
