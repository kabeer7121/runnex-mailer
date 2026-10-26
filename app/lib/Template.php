<?php
declare(strict_types=1);

/**
 * Personalisation + spintax.
 *   {{first_name}}            variable
 *   {{first_name|there}}      variable with fallback
 *   {Hi|Hello|Hey}            spintax – one option picked at random per email
 */
final class Template
{
    public static function render(string $text, array $vars): string
    {
        // protect {{var}} tokens from the spintax pass
        $slots = [];
        $text = preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/', function ($m) use (&$slots) {
            $slots[] = $m[1];
            return "\x01" . (count($slots) - 1) . "\x02";
        }, $text);

        $text = self::spin($text);

        $lower = array_change_key_case($vars, CASE_LOWER);
        return preg_replace_callback("/\x01(\d+)\x02/", function ($m) use ($slots, $lower) {
            $expr = $slots[(int)$m[1]];
            $parts = explode('|', $expr, 2);
            $key = strtolower(trim($parts[0]));
            $fallback = isset($parts[1]) ? trim($parts[1]) : '';
            $val = isset($lower[$key]) ? trim((string)$lower[$key]) : '';
            return $val !== '' ? $val : $fallback;
        }, $text);
    }

    public static function spin(string $text): string
    {
        $guard = 0;
        while (preg_match('/\{([^{}]*\|[^{}]*)\}/', $text) && $guard++ < 200) {
            $text = preg_replace_callback('/\{([^{}]*\|[^{}]*)\}/', function ($m) {
                $opts = explode('|', $m[1]);
                return $opts[array_rand($opts)];
            }, $text);
        }
        return $text;
    }

    /** Variables available to templates for a contact. */
    public static function vars(array $contact, array $account = [], array $company = []): array
    {
        $first = trim((string)($contact['first_name'] ?? ''));
        $last = trim((string)($contact['last_name'] ?? ''));
        $v = [
            'first_name' => $first !== '' ? self::niceName($first) : '',
            'last_name' => $last !== '' ? self::niceName($last) : '',
            'full_name' => trim(self::niceName($first) . ' ' . self::niceName($last)),
            'email' => $contact['email'] ?? '',
            'company_name' => $contact['company_name'] ?? '',
            'carrier_name' => $contact['company_name'] ?? '',
            'mc_number' => $contact['mc_number'] ?? '',
            'dot_number' => $contact['dot_number'] ?? '',
            'phone' => $contact['phone'] ?? '',
            'city' => $contact['city'] ?? '',
            'state' => $contact['state'] ?? '',
            'equipment_type' => $contact['equipment_type'] ?? '',
            'truck_count' => $contact['truck_count'] ?? '',
            'sender_name' => $account['from_name'] ?? '',
            'sender_first_name' => explode(' ', trim((string)($account['from_name'] ?? '')))[0] ?? '',
            'sender_email' => $account['email'] ?? '',
            'my_company' => $company['name'] ?? '',
            'my_website' => $company['website'] ?? '',
            'my_phone' => $company['phone'] ?? '',
            'signature' => ($account['signature_html'] ?? '') !== '' ? $account['signature_html'] : ($company['signature_html'] ?? ''),
            'day_of_week' => (new DateTime('now', app_tz()))->format('l'),
            'greeting' => self::greeting(),
        ];
        if (!empty($contact['custom'])) {
            $custom = json_decode((string)$contact['custom'], true) ?: [];
            foreach ($custom as $k => $val) {
                $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string)$k));
                if (!isset($v[$key])) $v[$key] = $val;
            }
        }
        return $v;
    }

    private static function niceName(string $n): string
    {
        // "JOHN" -> "John" (leave mixed-case names like "McDonald" alone)
        return ($n === strtoupper($n) || $n === strtolower($n)) ? ucwords(strtolower($n)) : $n;
    }

    private static function greeting(): string
    {
        $h = (int)(new DateTime('now', app_tz()))->format('G');
        return $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
    }

    public static function variableList(): array
    {
        return ['first_name', 'last_name', 'full_name', 'company_name', 'mc_number', 'dot_number', 'phone', 'city', 'state', 'equipment_type', 'truck_count', 'sender_name', 'sender_first_name', 'my_company', 'my_phone', 'my_website', 'signature', 'greeting', 'day_of_week'];
    }
}
