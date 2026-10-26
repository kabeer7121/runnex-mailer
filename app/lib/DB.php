<?php
declare(strict_types=1);

/**
 * Thin PDO wrapper. Supports MySQL (production / Hostinger) and SQLite (local dev).
 * All SQL in the app avoids engine-specific functions so both drivers behave the same.
 */
final class DB
{
    public PDO $pdo;
    public string $driver;

    public function __construct(array $c)
    {
        $this->driver = $c['driver'] ?? 'mysql';
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($this->driver === 'sqlite') {
            $this->pdo = new PDO('sqlite:' . $c['path'], null, null, $opts);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA busy_timeout=5000');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
            $this->pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
            $this->pdo->exec("SET time_zone = '+00:00'");
        }
    }

    public function q(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($params));
        return $st;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->q($sql, $params)->fetchAll();
    }

    public function row(string $sql, array $params = []): ?array
    {
        $r = $this->q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public function val(string $sql, array $params = [])
    {
        $r = $this->q($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public function col(string $sql, array $params = []): array
    {
        return $this->q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function exec(string $sql, array $params = []): int
    {
        return $this->q($sql, $params)->rowCount();
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->q($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    /** Insert, silently skipping unique-key conflicts. Returns true if a row was inserted. */
    public function insertIgnore(string $table, array $data): bool
    {
        $cols = array_keys($data);
        $verb = $this->driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $sql = $verb . ' INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        return $this->q($sql, array_values($data))->rowCount() > 0;
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
        return $this->exec("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $params));
    }

    public function tx(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Build "?, ?, ?" for an IN() list. */
    public static function in(array $values): string
    {
        return $values ? implode(',', array_fill(0, count($values), '?')) : 'NULL';
    }

    /** Run schema.sql, converting for SQLite when needed. */
    public function runSchema(string $file): void
    {
        $sql = file_get_contents($file);
        $sql = preg_replace('/^--.*$/m', '', $sql);
        if ($this->driver === 'sqlite') {
            $sql = str_replace('AUTO_INCREMENT', 'AUTOINCREMENT', $sql);
            $sql = preg_replace('/\)\s*ENGINE=[^;]+;/', ');', $sql);
        }
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $this->pdo->exec($stmt);
        }
    }
}
