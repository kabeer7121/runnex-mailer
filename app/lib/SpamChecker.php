<?php
declare(strict_types=1);

/**
 * Content analyser: scores subject + body for spam-filter risk and gives concrete fixes.
 * Score is 0-100 where 100 = clean.
 */
final class SpamChecker
{
    private const WORDS_HIGH = [
        'free money', 'act now', 'risk free', 'risk-free', '100% free', '100% guaranteed', 'guaranteed income', 'make money', 'earn money',
        'cash bonus', 'click here', 'click below', 'buy now', 'order now', 'limited time', 'urgent', 'winner', 'you have been selected',
        'congratulations', 'no credit check', 'double your', 'extra income', 'million dollars', 'work from home', 'call now', 'apply now',
        '$$$', 'no cost', 'no fees', 'no obligation', 'once in a lifetime', 'this is not spam', 'not junk', 'viagra', 'casino', 'crypto',
        'bitcoin', 'lowest price', 'best price', 'cheap', 'special promotion', 'exclusive deal', 'dear friend', 'increase sales', 'unsecured',
    ];
    private const WORDS_MED = [
        'free', 'guarantee', 'guaranteed', 'offer', 'discount', 'deal', 'promotion', 'save big', 'earn', 'income', 'profit', 'cash', 'bonus',
        'opportunity', 'act fast', 'hurry', 'instant', 'amazing', 'incredible', 'unbelievable', 'miracle', 'best rates', 'top dollar',
        'highest paying', 'high paying loads', 'dedicated lanes guaranteed', 'sign up today', 'limited spots', 'don\'t miss', 'last chance',
        'expires', 'trial', 'credit', 'loan', 'investment', 'refund', 'prize', 'winner', 'unlimited', '100%', 'percent off', 'subscribe',
    ];
    private const SHORTENERS = ['bit.ly', 'tinyurl.com', 'goo.gl', 't.co', 'ow.ly', 'is.gd', 'buff.ly', 'rebrand.ly', 'cutt.ly', 'shorturl.at', 'tiny.cc'];

    public static function analyze(string $subject, string $html): array
    {
        $issues = [];
        $score = 100;
        $text = Mailer::htmlToText(Template::spin($html));
        $subject = Template::spin($subject);
        $all = strtolower($subject . ' ' . $text);
        $add = function (string $level, string $msg, int $penalty) use (&$issues, &$score) {
            $issues[] = ['level' => $level, 'msg' => $msg];
            $score -= $penalty;
        };

        // trigger words
        $found = [];
        foreach (self::WORDS_HIGH as $w) {
            if (self::has($all, $w)) $found[$w] = 'high';
        }
        foreach (self::WORDS_MED as $w) {
            if (!isset($found[$w]) && self::has($all, $w)) $found[$w] = 'med';
        }
        $hi = array_keys(array_filter($found, fn($l) => $l === 'high'));
        $med = array_keys(array_filter($found, fn($l) => $l === 'med'));
        if ($hi) $add('high', 'Strong spam trigger words: "' . implode('", "', $hi) . '". Rephrase them in plain, conversational language.', min(30, 8 * count($hi)));
        if ($med) $add('med', 'Salesy words that filters watch: "' . implode('", "', array_slice($med, 0, 8)) . '". Use sparingly.', min(15, 3 * count($med)));

        // subject
        $sl = mb_strlen($subject);
        if ($sl === 0) $add('high', 'Subject line is empty.', 15);
        elseif ($sl > 60) $add('med', "Subject is long ($sl chars). Keep it under 50 characters – short subjects look personal.", 5);
        if ($sl > 3 && preg_match_all('/[A-Z]/', $subject) / max(1, preg_match_all('/[a-zA-Z]/', $subject)) > 0.5) $add('high', 'Subject is mostly CAPITAL LETTERS.', 12);
        if (substr_count($subject, '!') > 0) $add('med', 'Avoid exclamation marks in the subject.', 5);
        if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $subject)) $add('low', 'Emojis in the subject can trigger the Promotions tab.', 3);
        if (preg_match('/\$\d|\d+%/', $subject)) $add('med', 'Prices / percentages in the subject look promotional.', 5);
        if (preg_match('/^(re|fwd?):/i', $subject)) $add('high', 'Fake "Re:/Fwd:" in a first email is a known spam tactic.', 12);

        // body
        $words = str_word_count($text);
        if ($words < 25) $add('low', "Body is very short ($words words). Add a little context so it reads like a real email.", 3);
        if ($words > 250) $add('med', "Body is long ($words words). Cold emails of 50–150 words get better inbox placement and replies.", 5);
        $caps = preg_match_all('/\b[A-Z]{4,}\b/', $text);
        if ($caps > 2) $add('med', "$caps words in ALL CAPS. Use normal casing.", 5);
        $ex = substr_count($text, '!');
        if ($ex > 2) $add('med', "$ex exclamation marks. Keep to 1 or none.", 4);
        if (preg_match('/\${2,}|€{2,}/', $text)) $add('high', 'Repeated currency symbols ($$).', 10);

        // links
        preg_match_all('#href=["\']([^"\']+)#i', $html, $hm);
        preg_match_all('#https?://[^\s<>"\']+#i', $text, $tm);
        $links = array_unique(array_merge($hm[1], $tm[0]));
        $links = array_filter($links, fn($l) => stripos($l, 'mailto:') !== 0 && stripos($l, 'tel:') !== 0);
        $lc = count($links);
        if ($lc > 3) $add('high', "$lc links in the email. Use 0–2 links in cold emails (the first email ideally has none).", 12);
        elseif ($lc > 1) $add('low', "$lc links. Fewer links = better deliverability for cold outreach.", 3);
        foreach ($links as $l) {
            $host = strtolower((string)parse_url($l, PHP_URL_HOST));
            if (in_array($host, self::SHORTENERS, true)) {
                $add('high', "Link shortener ($host) detected – these are heavily filtered. Use your full domain link.", 15);
                break;
            }
        }
        foreach ($links as $l) {
            if (preg_match('#^https?://\d+\.\d+\.\d+\.\d+#', $l)) {
                $add('high', 'Link points to a raw IP address.', 15);
                break;
            }
        }

        // images / html weight
        $imgs = preg_match_all('#<img#i', $html);
        if ($imgs > 1) $add('med', "$imgs images. Image-heavy emails land in Promotions/Spam; use text.", 6);
        if ($imgs > 0 && $words < 60) $add('high', 'Low text-to-image ratio.', 10);
        if (preg_match('#<(table|style|font)#i', $html)) $add('low', 'Heavy HTML formatting (tables/fonts/styles). Plain emails look personal.', 3);
        if (preg_match('~(color\s*:\s*(#fff\b|#ffffff|white))|font-size\s*:\s*[01]px~i', $html)) $add('high', 'Hidden or invisible text detected.', 15);

        // personalisation
        if (!preg_match('/\{\{\s*(first_name|company_name|carrier_name|mc_number|full_name)/i', $subject . $html)) {
            $add('med', 'No personalisation. Add {{first_name}} or {{company_name}} – personalised emails are far less likely to be filtered.', 6);
        }
        if (!preg_match('/\{[^{}]*\|[^{}]*\}/', preg_replace('/\{\{[^}]*\}\}/', '', $subject . $html))) {
            $add('low', 'No spintax. Use {Hi|Hello|Hey} style variations so every email is unique.', 3);
        }

        $score = max(0, min(100, $score));
        $grade = $score >= 85 ? 'Great' : ($score >= 70 ? 'Good' : ($score >= 50 ? 'Risky' : 'High spam risk'));
        if (!$issues) $issues[] = ['level' => 'ok', 'msg' => 'No problems found. This email looks clean and personal.'];
        return ['score' => $score, 'grade' => $grade, 'issues' => $issues, 'words' => $words, 'links' => $lc, 'triggers' => array_keys($found)];
    }

    private static function has(string $hay, string $word): bool
    {
        if (preg_match('/^[a-z0-9 \'\-]+$/', $word)) {
            return (bool)preg_match('/\b' . preg_quote($word, '/') . '\b/', $hay);
        }
        return str_contains($hay, $word);
    }
}
