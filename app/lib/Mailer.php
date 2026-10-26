<?php
declare(strict_types=1);

final class Mailer
{
    /**
     * Build a raw MIME message.
     * $o: from_email, from_name, to_email, to_name, subject, html (optional), text, headers (assoc), in_reply_to, references
     * Returns [raw, messageId].
     */
    public static function build(array $o): array
    {
        $domain = email_domain($o['from_email']) ?: 'localhost';
        $messageId = '<' . bin2hex(random_bytes(10)) . '.' . time() . '@' . $domain . '>';
        $h = [];
        $h[] = 'From: ' . self::addr($o['from_email'], $o['from_name'] ?? '');
        $h[] = 'To: ' . self::addr($o['to_email'], $o['to_name'] ?? '');
        $h[] = 'Subject: ' . self::encodeHeader($o['subject'] ?? '');
        $h[] = 'Date: ' . date('r');
        $h[] = 'Message-ID: ' . $messageId;
        if (!empty($o['in_reply_to'])) {
            $h[] = 'In-Reply-To: ' . $o['in_reply_to'];
            $h[] = 'References: ' . trim(($o['references'] ?? '') ?: $o['in_reply_to']);
        }
        foreach (($o['headers'] ?? []) as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $h[] = 'MIME-Version: 1.0';

        $text = $o['text'] ?? self::htmlToText($o['html'] ?? '');
        $html = $o['html'] ?? null;

        if ($html === null || $html === '') {
            $h[] = 'Content-Type: text/plain; charset=UTF-8';
            $h[] = 'Content-Transfer-Encoding: quoted-printable';
            $body = self::qp($text);
        } else {
            $b = 'b' . bin2hex(random_bytes(12));
            $h[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
            $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . self::qp($text)
                . "\r\n--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . self::qp(self::wrapHtml($html))
                . "\r\n--$b--\r\n";
        }
        return [implode("\r\n", $h) . "\r\n\r\n" . $body, $messageId];
    }

    private static function qp(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return quoted_printable_encode(str_replace("\n", "\r\n", $s));
    }

    private static function wrapHtml(string $html): string
    {
        if (stripos($html, '<html') !== false) return $html;
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222">' . $html . '</div></body></html>';
    }

    public static function addr(string $email, string $name = ''): string
    {
        $name = trim(str_replace(["\r", "\n", '"'], '', $name));
        if ($name === '') return '<' . $email . '>';
        if (preg_match('/[^\x20-\x7E]/', $name)) return self::encodeHeader($name) . ' <' . $email . '>';
        return '"' . $name . '" <' . $email . '>';
    }

    public static function encodeHeader(string $s): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        if (!preg_match('/[^\x20-\x7E]/', $s)) return $s;
        return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
    }

    public static function htmlToText(string $html): string
    {
        $t = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        $t = preg_replace_callback('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(strip_tags($m[2]));
            $href = $m[1];
            if (stripos($href, 'mailto:') === 0) return $label;
            return ($label === '' || $label === $href) ? $href : "$label ($href)";
        }, $t);
        $t = preg_replace('#<br\s*/?>#i', "\n", $t);
        $t = preg_replace('#</(p|div|h[1-6]|tr|table|ul|ol)>#i', "\n\n", $t);
        $t = preg_replace('#<li[^>]*>#i', "\n• ", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
        $t = preg_replace("/ *\n */", "\n", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t);
    }

    public static function textToHtml(string $text): string
    {
        $h = e($text);
        $h = preg_replace('#(https?://[^\s<]+)#', '<a href="$1">$1</a>', $h);
        return nl2br($h);
    }

    /** Open an authenticated SMTP session for an account. */
    public static function smtpFor(array $acc): SmtpClient
    {
        $smtp = new SmtpClient($acc['smtp_host'], (int)$acc['smtp_port'], $acc['smtp_secure']);
        $smtp->connect();
        $smtp->auth($acc['username'], Crypto::decrypt($acc['password_enc']));
        return $smtp;
    }

    /** Build + send through the account. Returns the Message-ID. Throws SmtpException. */
    public static function sendFromAccount(array $acc, array $o): string
    {
        $o['from_email'] = $acc['email'];
        $o['from_name'] = $o['from_name'] ?? ($acc['from_name'] ?: '');
        [$raw, $mid] = self::build($o);
        $smtp = self::smtpFor($acc);
        try {
            $smtp->send($acc['email'], [$o['to_email']], $raw);
        } finally {
            $smtp->quit();
        }
        return $mid;
    }

    /**
     * Inbox placement test: send the real email content from $acc to up to 12 other connected
     * accounts (seeds). Their IMAP check records inbox vs spam. Returns the test id.
     */
    public static function placementTest(array $acc, string $subject, string $html, ?int $campaignId): int
    {
        $db = db();
        $seeds = $db->all("SELECT * FROM email_accounts WHERE status IN ('active','paused') AND id <> ? ORDER BY health_score DESC LIMIT 12", [$acc['id']]);
        if (!$seeds) throw new RuntimeException('Connect at least one more email account to use as a test inbox.');
        $token = random_token(20);
        $testId = $db->insert('placement_tests', [
            'company_id' => $acc['company_id'], 'user_id' => Auth::id() ?: $acc['user_id'], 'account_id' => $acc['id'],
            'campaign_id' => $campaignId, 'token' => $token, 'subject' => mb_substr($subject, 0, 250), 'status' => 'running', 'created_at' => now(),
        ]);
        $smtp = self::smtpFor($acc);
        try {
            foreach ($seeds as $s) {
                [$raw] = self::build([
                    'from_email' => $acc['email'], 'from_name' => $acc['from_name'], 'to_email' => $s['email'], 'to_name' => $s['from_name'],
                    'subject' => $subject, 'html' => $html, 'headers' => ['X-Runnex-Test' => $token],
                ]);
                $smtp->send($acc['email'], [$s['email']], $raw);
                $db->insert('placement_results', ['test_id' => $testId, 'seed_account_id' => $s['id'], 'landed' => 'pending']);
                $db->exec('UPDATE email_accounts SET imap_next_at = ? WHERE id = ?', [now(90), $s['id']]);
            }
        } finally {
            $smtp->quit();
        }
        return $testId;
    }

    /**
     * Compose a campaign email for one lead/step.
     * Returns ['subject','html'|null,'text','headers','in_reply_to','references'].
     */
    public static function composeCampaign(array $campaign, array $step, array $lead, array $contact, array $acc, array $company, string $token): array
    {
        $vars = Template::vars($contact, $acc, $company);
        $stepNo = (int)$step['step_no'];
        $base = base_url();

        $threaded = $stepNo > 0 && trim((string)$step['subject']) === '' && !empty($lead['thread_subject']);
        $subject = $threaded ? 'Re: ' . preg_replace('/^(re:\s*)+/i', '', $lead['thread_subject']) : Template::render((string)$step['subject'], $vars);
        if ($subject === '') $subject = 'Quick question';

        $body = Template::render((string)$step['body_html'], $vars);
        $unsubUrl = $base ? tracking_url('u', $token) : '';

        $headers = [];
        if ($unsubUrl) {
            $headers['List-Unsubscribe'] = '<' . $unsubUrl . '>, <mailto:' . $acc['email'] . '?subject=unsubscribe>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        } else {
            $headers['List-Unsubscribe'] = '<mailto:' . $acc['email'] . '?subject=unsubscribe>';
        }

        $footerText = trim((string)($company['footer_text'] ?? ''));
        if ($footerText === '') {
            $footerText = "Not the right person or don't want these emails? {unsubscribe}";
        }
        $address = trim((string)($company['address'] ?? ''));

        if ((int)$campaign['plain_text'] === 1) {
            $text = self::htmlToText($body);
            if ((int)$campaign['include_unsub'] === 1) {
                $line = str_replace('{unsubscribe}', $unsubUrl ? 'Unsubscribe: ' . $unsubUrl : 'Reply "unsubscribe" to opt out.', $footerText);
                $text .= "\n\n--\n" . $line . ($address ? "\n" . $address : '');
            }
            $html = null;
        } else {
            $html = $body;
            if ((int)$campaign['track_clicks'] === 1 && $base) {
                $html = preg_replace_callback('#(<a\s[^>]*href=)(["\'])(https?://[^"\']+)\2#i', function ($m) use ($base, $token) {
                    $u = html_entity_decode($m[3], ENT_QUOTES);
                    $enc = rtrim(strtr(base64_encode($u), '+/', '-_'), '=');
                    $link = tracking_url('c', $token) . (pretty_urls() ? '?' : '&') . 'l=' . $enc . '&s=' . Crypto::sign($token . '|' . $u);
                    return $m[1] . $m[2] . e($link) . $m[2];
                }, $html);
            }
            if ((int)$campaign['include_unsub'] === 1) {
                $link = $unsubUrl ? '<a href="' . e($unsubUrl) . '" style="color:#999">unsubscribe</a>' : 'reply "unsubscribe"';
                $html .= '<br><br><div style="font-size:11px;color:#999;line-height:1.4">' . str_replace('{unsubscribe}', $link, e($footerText))
                    . ($address ? '<br>' . e($address) : '') . '</div>';
            }
            if ((int)$campaign['track_opens'] === 1 && $base) {
                $html .= '<img src="' . e(tracking_url('o', $token)) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px">';
            }
            $text = self::htmlToText(preg_replace('#<img[^>]+>#i', '', $html));
        }

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'headers' => $headers,
            'in_reply_to' => $stepNo > 0 ? ($lead['last_message_id'] ?: null) : null,
            'references' => $stepNo > 0 ? ($lead['last_message_id'] ?: null) : null,
        ];
    }
}
