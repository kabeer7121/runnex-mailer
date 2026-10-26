<?php
declare(strict_types=1);

/** Incremental database upgrades. Runs automatically; safe to run repeatedly. */
final class Migrations
{
    private const STEPS = [
        2 => [
            'CREATE INDEX ix_messages_lead ON messages(lead_id)',
            'ALTER TABLE campaigns ADD COLUMN auto_note VARCHAR(255) NULL',
            'ALTER TABLE campaigns ADD COLUMN sequence_key VARCHAR(40) NULL',
            'ALTER TABLE email_accounts ADD COLUMN retest_at DATETIME NULL',
        ],
        3 => [
            'ALTER TABLE contacts ADD COLUMN verify_status VARCHAR(12) NULL',
            'ALTER TABLE contacts ADD COLUMN verify_reason VARCHAR(190) NULL',
            'ALTER TABLE contacts ADD COLUMN verify_suggestion VARCHAR(191) NULL',
            'ALTER TABLE contacts ADD COLUMN verified_at DATETIME NULL',
            'CREATE INDEX ix_contacts_verify ON contacts(company_id, verify_status)',
            'CREATE INDEX ix_contacts_verified_at ON contacts(verified_at)',
            'CREATE TABLE verify_domains (domain VARCHAR(191) NOT NULL PRIMARY KEY, has_mx TINYINT NOT NULL DEFAULT 0, mx_host VARCHAR(191) NULL, null_mx TINYINT NOT NULL DEFAULT 0, exists_dns TINYINT NOT NULL DEFAULT 0, catch_all TINYINT NULL, checked_at DATETIME NOT NULL)',
        ],
    ];

    public static function latest(): int
    {
        return max(array_keys(self::STEPS));
    }

    public static function run(): void
    {
        $current = (int)setting('schema_version', 1);
        if ($current >= self::latest()) return;
        $db = db();
        foreach (self::STEPS as $v => $stmts) {
            if ($v <= $current) continue;
            foreach ($stmts as $sql) {
                try {
                    $db->pdo->exec($sql);
                } catch (Throwable $e) {
                    // already applied (duplicate column / index) – ignore
                }
            }
            set_setting('schema_version', $v);
        }
    }
}
