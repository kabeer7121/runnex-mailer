<?php
declare(strict_types=1);

final class Crypto
{
    private static function key(): string
    {
        return hash('sha256', 'runnex|' . cfg('secret'), true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $c);
    }

    public static function decrypt(string $enc): string
    {
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) < 29) return '';
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? '' : $p;
    }

    public static function sign(string $data): string
    {
        return substr(hash_hmac('sha256', $data, self::key()), 0, 20);
    }
}

final class Auth
{
    private static ?array $user = null;

    public static function attempt(string $email, string $password): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $db = db();
        $recent = (int)$db->val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?', [$ip, now(-900)]);
        if ($recent >= 10) {
            throw new RuntimeException('Too many login attempts. Please wait 15 minutes.');
        }
        $u = $db->row('SELECT * FROM users WHERE email = ?', [strtolower($email)]);
        if (!$u || $u['status'] !== 'active' || !password_verify($password, $u['password_hash'])) {
            $db->insert('login_attempts', ['ip' => $ip, 'created_at' => now()]);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $db->exec('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $u['id']]);
        $db->exec('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['uid'])) {
            $u = db()->row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['uid']]);
            self::$user = $u ?: null;
        }
        return self::$user;
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function requireLogin(): void
    {
        if (!self::user()) {
            redirect('login');
        }
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            http_response_code(403);
            exit('Admins only.');
        }
    }

    /** Company IDs this user may access. */
    public static function companyIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = self::isAdmin()
                ? array_map('intval', db()->col('SELECT id FROM companies ORDER BY name'))
                : array_map('intval', db()->col('SELECT company_id FROM company_users WHERE user_id = ?', [self::id()]));
        }
        return $ids;
    }

    public static function companies(): array
    {
        $ids = self::companyIds();
        if (!$ids) return [];
        return db()->all('SELECT * FROM companies WHERE id IN (' . DB::in($ids) . ') ORDER BY name', $ids);
    }

    /** Selected company (0 = all accessible companies). */
    public static function currentCompany(): int
    {
        $ids = self::companyIds();
        $cur = (int)($_SESSION['company_id'] ?? 0);
        if ($cur && in_array($cur, $ids, true)) return $cur;
        if (count($ids) === 1) return $ids[0];
        return 0;
    }

    /** Company ids the current view covers. */
    public static function scopeIds(): array
    {
        $cur = self::currentCompany();
        return $cur ? [$cur] : self::companyIds();
    }

    public static function canCompany(int $companyId): bool
    {
        return in_array($companyId, self::companyIds(), true);
    }

    /** Returns [sql, params] restricting rows to the visible companies, and for agents to their own rows. */
    public static function scope(string $companyCol = 'company_id', ?string $ownerCol = 'user_id'): array
    {
        $ids = self::scopeIds();
        if (!$ids) return [' AND 1=0', []];
        $sql = " AND $companyCol IN (" . DB::in($ids) . ')';
        $params = $ids;
        if ($ownerCol && !self::isAdmin()) {
            $sql .= " AND $ownerCol = ?";
            $params[] = self::id();
        }
        return [$sql, $params];
    }

    /** Can the current user view/edit a row with this company + owner. */
    public static function canRow(array $row, string $ownerCol = 'user_id'): bool
    {
        if (!self::canCompany((int)$row['company_id'])) return false;
        return self::isAdmin() || (int)($row[$ownerCol] ?? 0) === self::id();
    }
}
