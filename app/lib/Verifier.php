<?php
declare(strict_types=1);

/**
 * Email list verification.
 *
 * Result status:
 *   valid   – safe to send
 *   risky   – may bounce (catch-all domain, role address, mailbox could not be confirmed)
 *   invalid – never sent to (bad syntax, typo domain, disposable, no mail server, mailbox does not exist, bounced)
 *
 * Checks, cheapest first: syntax → placeholder → domain typo → disposable → DNS/MX → role → mailbox
 * (mailbox via a verification API if configured, otherwise a direct SMTP "RCPT TO" probe when port 25 is open).
 */
final class Verifier
{
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamailblock.com', 'sharklasers.com', 'grr.la', '10minutemail.com', '10minutemail.net',
        'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'tempmailo.com', 'tempail.com', 'tempr.email', 'yopmail.com', 'yopmail.net', 'yopmail.fr', 'trashmail.com',
        'trashmail.de', 'getnada.com', 'nada.email', 'dispostable.com', 'maildrop.cc', 'mailnesia.com', 'mintemail.com', 'mohmal.com', 'emailondeck.com', 'fakeinbox.com',
        'throwawaymail.com', 'getairmail.com', 'spamgourmet.com', 'mytemp.email', 'tempinbox.com', 'burnermail.io', 'mailcatch.com', 'inboxkitten.com', 'mailpoof.com',
        'moakt.com', 'spambox.us', 'trbvm.com', 'discard.email', 'emailfake.com', 'fakemail.net', 'mailtemp.net', 'tmpmail.org', 'tmpmail.net', 'jetable.org',
        'mail-temp.com', 'mvrht.com', 'linshiyouxiang.net', '33mail.com', 'spam4.me', 'mailsac.com', 'harakirimail.com', 'tempmailaddress.com', 'emailtemporanea.net',
        'anonbox.net', 'byom.de', 'trash-mail.com', 'wegwerfmail.de', 'e4ward.com', 'mailexpire.com', 'incognitomail.org', 'deadaddress.com', 'mt2015.com', 'spamex.com',
    ];
    private const ROLE = [
        'admin', 'administrator', 'support', 'sales', 'contact', 'office', 'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'postmaster', 'abuse', 'webmaster',
        'hostmaster', 'billing', 'accounting', 'accounts', 'hr', 'jobs', 'careers', 'marketing', 'team', 'hello', 'help', 'mail', 'enquiries', 'inquiries', 'info',
        'privacy', 'legal', 'security', 'newsletter', 'feedback', 'service', 'customerservice', 'reception',
    ];
    private const PLACEHOLDER_LOCAL = ['test', 'testing', 'none', 'noemail', 'no-email', 'no_email', 'nomail', 'na', 'n/a', 'null', 'nobody', 'sample', 'example', 'unknown', 'xxx', 'asdf', 'fake', 'dummy', 'notavailable', 'noreply1'];
    private const PLACEHOLDER_DOMAIN = ['example.com', 'example.org', 'example.net', 'test.com', 'domain.com', 'noemail.com', 'nomail.com', 'none.com', 'xxx.com', 'email.test', 'invalid.com', 'fake.com'];
    private const TYPOS = [
        'gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmail.co' => 'gmail.com',
        'gmail.cm' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gmail.om' => 'gmail.com', 'gmeil.com' => 'gmail.com', 'gmail.comm' => 'gmail.com',
        'gmali.com' => 'gmail.com', 'gimail.com' => 'gmail.com', 'gemail.com' => 'gmail.com', 'g-mail.com' => 'gmail.com', 'gmail.cpm' => 'gmail.com', 'gmail.vom' => 'gmail.com',
        'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yahoo.co' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'yhoo.com' => 'yahoo.com', 'yahho.com' => 'yahoo.com', 'yahoo.cm' => 'yahoo.com', 'tahoo.com' => 'yahoo.com',
        'hotmial.com' => 'hotmail.com', 'hotmai.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com', 'hotmail.con' => 'hotmail.com', 'hotmil.com' => 'hotmail.com', 'hotamil.com' => 'hotmail.com', 'homail.com' => 'hotmail.com',
        'outlok.com' => 'outlook.com', 'outllok.com' => 'outlook.com', 'outlook.co' => 'outlook.com', 'outlook.con' => 'outlook.com', 'outloo.com' => 'outlook.com',
        'aol.co' => 'aol.com', 'aoll.com' => 'aol.com', 'aol.con' => 'aol.com', 'icloud.co' => 'icloud.com', 'iclod.com' => 'icloud.com', 'icoud.com' => 'icloud.com',
        'comcast.com' => 'comcast.net', 'sbcglobal.com' => 'sbcglobal.net', 'att.com' => 'att.net', 'verizon.com' => 'verizon.net', 'bellsouth.com' => 'bellsouth.net',
    ];
    private const POPULAR = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com', 'live.com', 'msn.com', 'comcast.net', 'att.net', 'sbcglobal.net', 'ymail.com', 'verizon.net', 'bellsouth.net', 'me.com'];
    /** Providers whose servers accept every RCPT – mailbox cannot be confirmed by SMTP. */
    private const ACCEPT_ALL = ['yahoo.com', 'ymail.com', 'aol.com', 'aim.com', 'rocketmail.com', 'yahoo.co.uk', 'att.net', 'sbcglobal.net', 'bellsouth.net', 'verizon.net'];

    private static array $domainCache = [];

    public static function statuses(): array
    {
        return ['valid' => 'Valid', 'risky' => 'Risky', 'invalid' => 'Invalid', 'unverified' => 'Not verified', 'queued' => 'Verifying…'];
    }

    /** Verify one address. Returns ['status','reason','suggestion','method']. */
    public static function check(string $email, bool $allowNetwork = true, bool $useApi = true): array
    {
        $email = strtolower(trim($email));
        $r = fn(string $status, string $reason, ?string $suggestion = null, string $method = 'local') => compact('status', 'reason', 'suggestion', 'method');

        // 1. syntax
        if (!preg_match('/^[a-z0-9!#$%&\'*+\/=?^_`{|}~.\-]+@[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)+$/', $email)
            || strlen($email) > 191 || str_contains($email, '..') || preg_match('/(^\.|\.@)/', $email)
            || strlen(strstr($email, '@', true)) > 64 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $r('invalid', 'Bad email format');
        }
        [$local, $domain] = explode('@', $email, 2);
        $tld = substr(strrchr($domain, '.'), 1);
        if (strlen($tld) < 2 || ctype_digit($tld)) return $r('invalid', 'Domain has no valid ending (.com, .net …)');

        // 2. placeholders
        if (in_array($domain, self::PLACEHOLDER_DOMAIN, true) || in_array($local, self::PLACEHOLDER_LOCAL, true) || preg_match('/^(.)\1{3,}$/', $local)) {
            return $r('invalid', 'Placeholder / fake address');
        }

        // 3. typo domains
        $fix = self::typoFix($domain);
        if ($fix !== null && $fix !== $domain && (isset(self::TYPOS[$domain]) || !self::domainInfo($domain, $allowNetwork)['mx'])) {
            return $r('invalid', "Domain looks misspelled – did you mean $local@$fix?", "$local@$fix");
        }

        // 4. disposable
        if (in_array($domain, self::DISPOSABLE, true)) return $r('invalid', 'Disposable / temporary email');

        if (!$allowNetwork) return $r('unverified', 'Format OK – not verified yet');

        // 5. DNS
        $info = self::domainInfo($domain);
        if ($info['nullmx']) return $r('invalid', 'Domain does not accept email (null MX)');
        if (!$info['mx']) return $r('invalid', $info['exists'] ? 'Domain has no mail server' : 'Domain does not exist');

        $isRole = in_array(preg_replace('/[\d._\-+]+$/', '', $local), self::ROLE, true);

        // 6. mailbox – verification API (most accurate)
        $provider = (string)setting('verify_api_provider', 'none');
        if ($useApi && $provider !== 'none' && setting('verify_api_key') && ($provider !== 'verifalia' || self::verifaliaActive())) {
            try {
                $api = self::apiCheck($provider, Crypto::decrypt((string)setting('verify_api_key')), $email);
                if ($api['status'] === 'valid' && $isRole) $api = ['status' => 'risky', 'reason' => 'Role address (shared inbox) – mailbox exists'];
                return $r($api['status'], $api['reason'], $api['suggestion'] ?? null, $provider);
            } catch (Throwable $e) {
                app_log('Verify API error: ' . $e->getMessage());
                // fall through to SMTP / DNS result
            }
        }

        // 7. mailbox – direct SMTP probe
        if (in_array($domain, self::ACCEPT_ALL, true)) {
            return $isRole ? $r('risky', 'Role address (shared inbox)', null, 'dns') : $r('valid', 'Mail server OK (this provider does not allow mailbox checks)', null, 'dns');
        }
        $probe = self::smtpProbe($email, $domain, $info['mx_host']);
        if ($probe === null) {
            return $isRole ? $r('risky', 'Role address (shared inbox)', null, 'dns') : $r('valid', 'Mail server OK (mailbox check unavailable on this server)', null, 'dns');
        }
        if ($probe['result'] === 'invalid') return $r('invalid', 'Mailbox does not exist', null, 'smtp');
        if ($probe['result'] === 'catchall') return $r('risky', 'Domain accepts every address (catch-all) – mailbox not confirmed', null, 'smtp');
        if ($probe['result'] === 'unknown' && in_array($domain, ['outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'comcast.net', 'cox.net', 'charter.net'], true)) {
            // big consumer providers hide mailbox status from checks – the domain itself is fine
            return $isRole ? $r('risky', 'Role address (shared inbox)', null, 'dns') : $r('valid', 'Mail server OK (this provider does not allow mailbox checks)', null, 'dns');
        }
        if ($probe['result'] === 'unknown') return $r('risky', 'Mail server did not confirm the mailbox (' . $probe['detail'] . ')', null, 'smtp');
        return $isRole ? $r('risky', 'Role address (shared inbox) – mailbox exists', null, 'smtp') : $r('valid', 'Mailbox confirmed', null, 'smtp');
    }

    public static function typoFix(string $domain): ?string
    {
        if (isset(self::TYPOS[$domain])) return self::TYPOS[$domain];
        if (in_array($domain, self::POPULAR, true)) return null;
        foreach (['.con' => '.com', '.cmo' => '.com', '.comm' => '.com', '.cpm' => '.com', '.vom' => '.com', '.xom' => '.com', '.ner' => '.net', '.nte' => '.net'] as $bad => $good) {
            if (str_ends_with($domain, $bad)) return substr($domain, 0, -strlen($bad)) . $good;
        }
        foreach (self::POPULAR as $p) {
            if (abs(strlen($p) - strlen($domain)) <= 1 && levenshtein($domain, $p) === 1) return $p;
        }
        return null;
    }

    /** MX lookup with in-memory + DB cache (7 days). */
    public static function domainInfo(string $domain, bool $allowNetwork = true): array
    {
        if (isset(self::$domainCache[$domain])) return self::$domainCache[$domain];
        $db = db();
        $row = $db->row('SELECT * FROM verify_domains WHERE domain = ?', [$domain]);
        if ($row && strtotime($row['checked_at'] . ' UTC') > time() - 7 * 86400) {
            return self::$domainCache[$domain] = ['mx' => (bool)$row['has_mx'], 'mx_host' => $row['mx_host'], 'nullmx' => (bool)$row['null_mx'], 'exists' => (bool)$row['exists_dns'], 'catchall' => $row['catch_all'] === null ? null : (bool)$row['catch_all']];
        }
        if (!$allowNetwork) return ['mx' => true, 'mx_host' => null, 'nullmx' => false, 'exists' => true, 'catchall' => null];
        $mx = @dns_get_record($domain, DNS_MX) ?: [];
        usort($mx, fn($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
        $host = $mx ? rtrim((string)$mx[0]['target'], '.') : null;
        $nullMx = $mx && $host === '';
        $hasA = !$mx && (@checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA'));
        $info = ['mx' => ($mx && !$nullMx) || $hasA, 'mx_host' => $host ?: ($hasA ? $domain : null), 'nullmx' => $nullMx, 'exists' => $mx || $hasA || @checkdnsrr($domain, 'NS'), 'catchall' => null];
        try {
            $db->exec('DELETE FROM verify_domains WHERE domain = ?', [$domain]);
            $db->insert('verify_domains', ['domain' => $domain, 'has_mx' => $info['mx'] ? 1 : 0, 'mx_host' => $info['mx_host'], 'null_mx' => $nullMx ? 1 : 0, 'exists_dns' => $info['exists'] ? 1 : 0, 'checked_at' => now()]);
        } catch (Throwable $e) {
        }
        return self::$domainCache[$domain] = $info;
    }

    /** Can this server open outbound port 25? Tested once a day. */
    public static function port25Open(): bool
    {
        if (setting('verify_smtp', 'auto') === 'off') return false;
        $st = setting('port25_status');
        if ($st && ($p = explode('|', $st)) && (int)$p[1] > time() - 86400) return $p[0] === 'open';
        $s = @stream_socket_client('tcp://gmail-smtp-in.l.google.com:25', $errno, $errstr, 6);
        $open = false;
        if ($s) {
            stream_set_timeout($s, 6);
            $open = str_starts_with((string)fgets($s, 512), '220');
            @fwrite($s, "QUIT\r\n");
            fclose($s);
        }
        set_setting('port25_status', ($open ? 'open' : 'blocked') . '|' . time());
        return $open;
    }

    /** RCPT TO probe. Returns null when probing isn't possible, else ['result' => valid|invalid|catchall|unknown, 'detail']. */
    private static function smtpProbe(string $email, string $domain, ?string $mxHost): ?array
    {
        if (!$mxHost || !self::port25Open()) return null;
        $s = @stream_socket_client('tcp://' . $mxHost . ':25', $errno, $errstr, 8);
        if (!$s) return ['result' => 'unknown', 'detail' => 'mail server unreachable'];
        stream_set_timeout($s, 8);
        $read = function () use ($s): array {
            $txt = '';
            while (($line = fgets($s, 1024)) !== false) {
                $txt .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') break;
            }
            return [(int)substr($txt, 0, 3), trim($txt)];
        };
        $cmd = function (string $c) use ($s, $read): array {
            @fwrite($s, $c . "\r\n");
            return $read();
        };
        try {
            [$code] = $read();
            if ($code !== 220) return ['result' => 'unknown', 'detail' => 'server busy'];
            $host = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
            [$code] = $cmd('EHLO ' . $host);
            if ($code !== 250) [$code] = $cmd('HELO ' . $host);
            [$code, $t] = $cmd('MAIL FROM:<verify@' . $host . '>');
            if ($code !== 250) return ['result' => 'unknown', 'detail' => 'sender refused'];
            [$code, $text] = $cmd('RCPT TO:<' . $email . '>');
            $lower = strtolower($text);
            if ($code >= 500) {
                $userUnknown = preg_match('/5\.1\.[0-9]|user unknown|does not exist|no such user|unknown user|not found|invalid (recipient|mailbox|address)|recipient rejected|mailbox unavailable|address rejected|undeliverable|no mailbox|disabled|inactive/', $lower);
                $policy = preg_match('/5\.7\.|spam|block|policy|reputation|denied|blacklist|listed|access/', $lower) && !preg_match('/5\.1\./', $lower);
                if ($userUnknown && !$policy) return ['result' => 'invalid', 'detail' => $code];
                return ['result' => 'unknown', 'detail' => 'server refused the check'];
            }
            if ($code >= 400) return ['result' => 'unknown', 'detail' => 'temporarily unavailable'];
            if ($code !== 250 && $code !== 251) return ['result' => 'unknown', 'detail' => (string)$code];

            // catch-all test (cached per domain)
            $info = self::domainInfo($domain);
            $catch = $info['catchall'];
            if ($catch === null) {
                [$c2] = $cmd('RCPT TO:<rx' . bin2hex(random_bytes(6)) . 'zq@' . $domain . '>');
                $catch = $c2 === 250 || $c2 === 251;
                db()->exec('UPDATE verify_domains SET catch_all = ? WHERE domain = ?', [$catch ? 1 : 0, $domain]);
                self::$domainCache[$domain]['catchall'] = $catch;
            }
            return $catch ? ['result' => 'catchall', 'detail' => 'catch-all'] : ['result' => 'valid', 'detail' => 'ok'];
        } finally {
            @fwrite($s, "QUIT\r\n");
            @fclose($s);
        }
    }

    private static function http(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]));
            $code = 200;
        }
        if ($body === false || $code >= 400) throw new RuntimeException("Verification API HTTP $code");
        $j = json_decode((string)$body, true);
        if (!is_array($j)) throw new RuntimeException('Verification API returned an invalid response');
        return $j;
    }

    /** Third-party verification APIs. Returns ['status','reason','suggestion'?]. */
    public static function apiCheck(string $provider, string $key, string $email): array
    {
        if ($provider === 'verifalia') return self::verifaliaCheckOne($email);
        $e = rawurlencode($email);
        $k = rawurlencode($key);
        if ($provider === 'millionverifier') {
            $j = self::http("https://api.millionverifier.com/api/v3/?api=$k&email=$e&timeout=20");
            if (!empty($j['error'])) throw new RuntimeException('MillionVerifier: ' . $j['error']);
            $res = strtolower((string)($j['result'] ?? 'unknown'));
            return match ($res) {
                'ok' => ['status' => 'valid', 'reason' => 'Mailbox confirmed (MillionVerifier)'],
                'catch_all' => ['status' => 'risky', 'reason' => 'Catch-all domain – mailbox not confirmed (MillionVerifier)'],
                'invalid' => ['status' => 'invalid', 'reason' => 'Mailbox does not exist (MillionVerifier)'],
                'disposable' => ['status' => 'invalid', 'reason' => 'Disposable email (MillionVerifier)'],
                default => ['status' => 'risky', 'reason' => 'Could not be confirmed (MillionVerifier: ' . $res . ')'],
            };
        }
        if ($provider === 'zerobounce') {
            $j = self::http("https://api.zerobounce.net/v2/validate?api_key=$k&email=$e&ip_address=");
            if (!empty($j['error'])) throw new RuntimeException('ZeroBounce: ' . $j['error']);
            $st = strtolower((string)($j['status'] ?? 'unknown'));
            $sub = (string)($j['sub_status'] ?? '');
            $sug = !empty($j['did_you_mean']) ? (string)$j['did_you_mean'] : null;
            return match ($st) {
                'valid' => ['status' => 'valid', 'reason' => 'Mailbox confirmed (ZeroBounce)'],
                'invalid' => ['status' => 'invalid', 'reason' => 'Invalid' . ($sub ? " – $sub" : '') . ' (ZeroBounce)', 'suggestion' => $sug],
                'spamtrap' => ['status' => 'invalid', 'reason' => 'Spam trap – never email (ZeroBounce)'],
                'abuse' => ['status' => 'invalid', 'reason' => 'Known complainer (ZeroBounce)'],
                'do_not_mail' => ['status' => 'invalid', 'reason' => 'Do not mail' . ($sub ? " – $sub" : '') . ' (ZeroBounce)'],
                'catch-all' => ['status' => 'risky', 'reason' => 'Catch-all domain (ZeroBounce)'],
                default => ['status' => 'risky', 'reason' => 'Could not be confirmed (ZeroBounce: ' . $st . ')'],
            };
        }
        if ($provider === 'neverbounce') {
            $j = self::http("https://api.neverbounce.com/v4/single/check?key=$k&email=$e");
            if (($j['status'] ?? '') !== 'success') throw new RuntimeException('NeverBounce: ' . ($j['message'] ?? 'error'));
            $res = strtolower((string)($j['result'] ?? 'unknown'));
            $sug = !empty($j['suggested_correction']) ? (string)$j['suggested_correction'] : null;
            return match ($res) {
                'valid' => ['status' => 'valid', 'reason' => 'Mailbox confirmed (NeverBounce)'],
                'invalid' => ['status' => 'invalid', 'reason' => 'Mailbox does not exist (NeverBounce)', 'suggestion' => $sug],
                'disposable' => ['status' => 'invalid', 'reason' => 'Disposable email (NeverBounce)'],
                'catchall' => ['status' => 'risky', 'reason' => 'Catch-all domain (NeverBounce)'],
                default => ['status' => 'risky', 'reason' => 'Could not be confirmed (NeverBounce: ' . $res . ')'],
            };
        }
        throw new RuntimeException('Unknown verification provider');
    }

    // 
    // Verifalia (https://verifalia.com) – direct REST calls, no SDK/Composer needed.
    // Free plan: 25 free verifications per day; paid credit packs for more.
    // 
    private const VERIFALIA_HOSTS = ['https://api-1.verifalia.com/v2.5/', 'https://api-2.verifalia.com/v2.5/', 'https://api-3.verifalia.com/v2.5/'];

    public static function verifaliaActive(): bool
    {
        return setting('verify_api_provider') === 'verifalia' && setting('verify_api_key')
            && (int)setting('verifalia_paused_until', 0) < time();
    }

    /** @return array{0:int,1:mixed} [httpCode, decodedJson] */
    public static function verifaliaRequest(string $method, string $path, ?array $body = null, int $timeout = 45): array
    {
        $cred = Crypto::decrypt((string)setting('verify_api_key'));
        [$user, $pass] = array_pad(explode(':', $cred, 2), 2, '');
        $hosts = self::VERIFALIA_HOSTS;
        shuffle($hosts);
        $lastErr = 'no response';
        foreach ($hosts as $base) {
            $ch = curl_init($base . ltrim($path, '/'));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERPWD => $user . ':' . $pass, CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'User-Agent: Runnex-Email-Marketing/' . RX_VERSION],
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false || $code === 0 || $code >= 500) {
                $lastErr = $err ?: "HTTP $code";
                continue; // try the next Verifalia endpoint
            }
            if ($code === 401 || $code === 403) throw new RuntimeException('Verifalia rejected the username/password (use a Verifalia user with email-validation permission).');
            if ($code === 402) {
                set_setting('verifalia_paused_until', time() + 3600);
                throw new RuntimeException('Verifalia credits used up for now (free plan = 25/day). Built-in checks are used until credits are available.');
            }
            return [$code, json_decode((string)$raw)];
        }
        throw new RuntimeException('Verifalia unreachable: ' . $lastErr);
    }

    public static function verifaliaBalance(): ?array
    {
        try {
            [$code, $j] = self::verifaliaRequest('GET', 'credits/balance', null, 20);
            if ($code !== 200 || !$j) return null;
            return ['packs' => (float)($j->creditPacks ?? 0), 'free' => (float)($j->freeCredits ?? 0), 'reset' => (string)($j->freeCreditsResetIn ?? '')];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Submit a validation job. $entries: [contactId => email]. Returns [jobId, completedJson|null]. */
    public static function verifaliaSubmit(array $entries, int $waitMs = 0): array
    {
        $list = [];
        foreach ($entries as $cid => $email) $list[] = ['inputData' => $email, 'custom' => (string)$cid];
        [$code, $j] = self::verifaliaRequest('POST', 'email-validations?waitTime=' . $waitMs, [
            'entries' => $list, 'quality' => (string)setting('verifalia_quality', 'Standard'), 'deduplication' => 'Off',
            'name' => 'Runnex ' . gmdate('Y-m-d H:i'), 'retention' => '1.00:00:00',
        ], max(45, (int)ceil($waitMs / 1000) + 15));
        if (!in_array($code, [200, 202], true) || empty($j->overview->id)) throw new RuntimeException("Verifalia submit failed (HTTP $code)");
        return [(string)$j->overview->id, ($j->overview->status ?? '') === 'Completed' ? $j : null];
    }

    /** Poll a job; returns the completed job JSON or null while still running. Throws if the job is gone. */
    public static function verifaliaPoll(string $id, int $waitMs = 0): ?object
    {
        [$code, $j] = self::verifaliaRequest('GET', 'email-validations/' . rawurlencode($id) . '?waitTime=' . $waitMs, null, max(45, (int)ceil($waitMs / 1000) + 15));
        if ($code === 404 || $code === 410) throw new RuntimeException('Verifalia job expired');
        if ($code === 200 && ($j->overview->status ?? '') === 'Completed') return $j;
        return null;
    }

    /** All entries of a completed job (follows pagination). */
    private static function verifaliaEntries(object $job): array
    {
        $out = [];
        $seg = $job->entries ?? null;
        $guard = 0;
        while ($seg && $guard++ < 200) {
            $data = is_array($seg->data ?? null) ? $seg->data : (is_array($seg->data->data ?? null) ? $seg->data->data : []);
            foreach ($data as $e) $out[] = $e;
            $meta = $seg->meta ?? ($seg->data->meta ?? null);
            if (!$meta || empty($meta->isTruncated) || empty($meta->cursor)) break;
            [, $next] = self::verifaliaRequest('GET', 'email-validations/' . rawurlencode($job->overview->id) . '/entries?cursor=' . rawurlencode($meta->cursor));
            $seg = $next;
        }
        return $out;
    }

    /** Map a Verifalia entry to our status. */
    public static function verifaliaMap(object $e): array
    {
        $cls = (string)($e->classification ?? 'Unknown');
        $st = (string)($e->status ?? '');
        $human = trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $st));
        $sug = !empty($e->suggestions) && is_array($e->suggestions) ? (string)$e->suggestions[0] : null;
        if ($cls === 'Deliverable') {
            return !empty($e->isRoleAccount)
                ? ['status' => 'risky', 'reason' => 'Role address (shared inbox) – deliverable (Verifalia)', 'suggestion' => null]
                : ['status' => 'valid', 'reason' => 'Deliverable – mailbox confirmed (Verifalia)', 'suggestion' => null];
        }
        if ($cls === 'Undeliverable') return ['status' => 'invalid', 'reason' => ($human ?: 'Undeliverable') . ' (Verifalia)', 'suggestion' => $sug];
        if (!empty($e->isDisposableEmailAddress)) return ['status' => 'invalid', 'reason' => 'Disposable email (Verifalia)', 'suggestion' => null];
        return ['status' => 'risky', 'reason' => ($cls === 'Risky' ? 'Risky' : 'Unknown') . ($human ? ': ' . $human : '') . ' (Verifalia)', 'suggestion' => $sug];
    }

    /** Single address through Verifalia (used for on-the-spot checks before sending). */
    private static function verifaliaCheckOne(string $email): array
    {
        [$id, $done] = self::verifaliaSubmit([0 => $email], 25000);
        $job = $done ?: self::verifaliaPoll($id, 25000);
        if (!$job) throw new RuntimeException('Verifalia still processing');
        $entries = self::verifaliaEntries($job);
        if (!$entries) throw new RuntimeException('Verifalia returned no result');
        return self::verifaliaMap($entries[0]);
    }

    /** Apply a verification result to a contact (queues, lists, typo suggestions). */
    public static function applyResult(int $contactId, array $res): void
    {
        $db = db();
        $db->update('contacts', [
            'verify_status' => $res['status'], 'verify_reason' => mb_substr((string)$res['reason'], 0, 190),
            'verify_suggestion' => $res['suggestion'] ?? null, 'verified_at' => now(),
        ], 'id = ?', [$contactId]);
        $remove = $res['status'] === 'invalid' || ($res['status'] === 'risky' && setting('verify_block_risky') === '1');
        if ($remove) {
            $db->exec("UPDATE campaign_leads SET status = 'skipped', next_send_at = NULL, note = ? WHERE contact_id = ? AND status IN ('pending','active')", [($res['status'] === 'invalid' ? 'Invalid email: ' : 'Risky email skipped: ') . mb_substr((string)$res['reason'], 0, 200), $contactId]);
            // keep lists clean automatically (the contact stays on file, marked invalid, so it is never re-imported into a list)
            if (setting('verify_auto_remove', '1') === '1') $db->exec('DELETE FROM list_contacts WHERE contact_id = ?', [$contactId]);
        }
    }

    /** Verify a contact row and store the result. Invalid contacts are removed from every queue and list. */
    public static function verifyContact(array $c): array
    {
        $res = self::check((string)$c['email']);
        if ($res['status'] === 'unverified') return $res;
        self::applyResult((int)$c['id'], $res);
        return $res;
    }

    /** Submit unverified contacts to Verifalia in one job and/or collect finished jobs. Returns contacts resolved. */
    private static function verifaliaBatch(int $deadline, array $rows): int
    {
        $db = db();
        $done = 0;
        // 1) collect results of jobs submitted earlier
        $jobs = json_decode((string)setting('verifalia_jobs', '[]'), true) ?: [];
        foreach ($jobs as $id => $meta) {
            if (time() >= $deadline - 5) break;
            try {
                $job = self::verifaliaPoll($id, 0);
            } catch (Throwable $e) {
                // expired / failed – release its contacts so they are verified again
                $db->exec("UPDATE contacts SET verify_status = NULL WHERE verify_status = 'queued' AND id IN (" . DB::in($meta['ids'] ?: [0]) . ')', $meta['ids'] ?: [0]);
                unset($jobs[$id]);
                continue;
            }
            if (!$job) {
                if ((int)$meta['at'] < time() - 86400) unset($jobs[$id]);
                continue;
            }
            foreach (self::verifaliaEntries($job) as $e) {
                $cid = (int)($e->custom ?? 0);
                if ($cid > 0) {
                    self::applyResult($cid, self::verifaliaMap($e));
                    $done++;
                }
            }
            unset($jobs[$id]);
        }
        set_setting('verifalia_jobs', json_encode($jobs));

        // 2) local pre-checks first (free), only plausible addresses go to Verifalia
        $send = [];
        foreach ($rows as $c) {
            if (time() >= $deadline - 5) break;
            $local = self::check((string)$c['email'], false);
            if ($local['status'] === 'invalid') {
                self::applyResult((int)$c['id'], $local);
                $done++;
                continue;
            }
            $info = self::domainInfo(email_domain((string)$c['email']));
            if (!$info['mx']) {
                self::applyResult((int)$c['id'], ['status' => 'invalid', 'reason' => $info['exists'] ? 'Domain has no mail server' : 'Domain does not exist', 'suggestion' => null]);
                $done++;
                continue;
            }
            $send[(int)$c['id']] = strtolower((string)$c['email']);
        }
        if (!$send || time() >= $deadline - 8) return $done;

        // 3) one Verifalia job for the rest (waits briefly; big lists finish in the background)
        $send = array_slice($send, 0, 500, true);
        $wait = max(0, min(25000, ($deadline - time() - 10) * 1000));
        [$id, $completed] = self::verifaliaSubmit($send, $wait);
        if ($completed) {
            foreach (self::verifaliaEntries($completed) as $e) {
                $cid = (int)($e->custom ?? 0);
                if ($cid > 0) {
                    self::applyResult($cid, self::verifaliaMap($e));
                    $done++;
                }
            }
        } else {
            $ids = array_keys($send);
            $db->exec("UPDATE contacts SET verify_status = 'queued', verify_reason = 'Being verified by Verifalia' WHERE id IN (" . DB::in($ids) . ')', $ids);
            $jobs[$id] = ['ids' => $ids, 'at' => time()];
            set_setting('verifalia_jobs', json_encode($jobs));
        }
        return $done;
    }

    /** Background verification of unverified contacts. Returns number verified. */
    public static function runBatch(int $deadline, array $companyIds = [], int $max = 400): int
    {
        $db = db();
        if (self::verifaliaActive()) {
            $where = "verified_at IS NULL AND (verify_status IS NULL OR verify_status <> 'queued')";
            $params = [];
            if ($companyIds) {
                $where .= ' AND company_id IN (' . DB::in($companyIds) . ')';
                $params = $companyIds;
            }
            $rows = $db->all("SELECT * FROM contacts WHERE $where ORDER BY id DESC LIMIT " . min(500, $max), $params);
            try {
                return self::verifaliaBatch($deadline, $rows);
            } catch (Throwable $e) {
                app_log('Verifalia batch: ' . $e->getMessage());
                // fall back to built-in verification below
            }
        }
        $where = 'verified_at IS NULL';
        $params = [];
        if ($companyIds) {
            $where .= ' AND company_id IN (' . DB::in($companyIds) . ')';
            $params = $companyIds;
        }
        // contacts already queued in campaigns first, then everything else
        $rows = $db->all("SELECT * FROM contacts WHERE $where AND id IN (SELECT contact_id FROM campaign_leads WHERE status IN ('pending','active')) LIMIT $max", $params);
        if (count($rows) < $max) {
            $rows = array_merge($rows, $db->all("SELECT * FROM contacts WHERE $where ORDER BY id DESC LIMIT " . ($max - count($rows)), $params));
        }
        $done = 0;
        $seen = [];
        foreach ($rows as $c) {
            if (time() >= $deadline) break;
            if (isset($seen[$c['id']])) continue;
            $seen[$c['id']] = true;
            try {
                self::verifyContact($c);
                $done++;
            } catch (Throwable $e) {
                app_log('Verify ' . $c['email'] . ': ' . $e->getMessage());
            }
        }
        return $done;
    }

    /** Should a campaign email go to this contact? Verifies on the spot if never checked. */
    public static function allowSend(array $contact): array
    {
        if (empty($contact['verified_at']) && ($contact['verify_status'] ?? null) === 'queued') {
            // Verifalia result pending: gate on the free checks now, the full result arrives shortly
            $res = self::check((string)$contact['email'], true, false);
            $status = $res['status'];
            $reason = $res['reason'];
        } elseif (empty($contact['verified_at'])) {
            $res = self::verifyContact($contact);
            $status = $res['status'];
            $reason = $res['reason'];
        } else {
            $status = (string)$contact['verify_status'];
            $reason = (string)$contact['verify_reason'];
        }
        if ($status === 'invalid') return [false, 'Invalid email: ' . $reason];
        if ($status === 'risky' && setting('verify_block_risky') === '1') return [false, 'Risky email skipped: ' . $reason];
        return [true, ''];
    }

    public static function badge(?string $status, ?string $reason = null): string
    {
        $status = $status ?: 'unverified';
        $cls = ['valid' => 'ok', 'risky' => 'warn', 'invalid' => 'bad', 'unverified' => 'muted', 'queued' => 'info'][$status] ?? 'muted';
        return '<span class="badge b-' . $cls . '"' . ($reason ? ' title="' . e($reason) . '"' : '') . '>' . e(self::statuses()[$status] ?? ucfirst($status)) . '</span>';
    }
}
