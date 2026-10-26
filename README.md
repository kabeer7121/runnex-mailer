# Runnex Mailer

Self-hosted cold-email platform built in plain PHP and MySQL. It connects many Gmail / Google Workspace mailboxes, warms them up, runs multi-step outreach sequences with tracking, collects every reply into one inbox and keeps sender reputation healthy. It runs on cheap shared hosting (built and used on Hostinger) with no Composer or Node build step.

## Features

- Multi-account sending over SMTP with rotation, per-account daily limits and human-like delays
- Built-in warmup network between connected accounts, inbox placement tests and health scores with auto-pause
- Campaigns with follow-up sequences, spintax and `{{variables}}`, sending windows and timezones
- Open / click / unsubscribe tracking, RFC 8058 one-click unsubscribe, suppression list
- Unified inbox over IMAP with reply, out-of-office and bounce detection
- CSV / XLSX / Google Sheets import with email verification (built-in checks plus Verifalia, MillionVerifier, ZeroBounce, NeverBounce)
- Live spam-score content checker and SPF / DKIM / DMARC domain checks
- Multiple companies, admin and agent roles, reports with CSV export

## Tech stack

PHP 8.1+ (no framework, no dependencies), MySQL / MariaDB (SQLite supported for local dev), vanilla JS and CSS, Apache `.htaccess` rewrites, a per-minute cron worker.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `openssl`, `mbstring`, `iconv`, `zip`
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` (or equivalent rules), HTTPS
- Cron, run every minute

## Installation

1. Clone into your web root:
   ```bash
   git clone https://github.com/<you>/runnex-mailer.git
   ```
2. Create a MySQL database and user.
3. Configure the app, using **one** of these:
   - `cp config.example.php config.local.php` and edit it, or
   - set the environment variables listed in `.env.example`.

   Generate `secret` (64 hex) and `cron_key` (32 hex) with `php -r "echo bin2hex(random_bytes(32));"`. Keep `secret` stable: it encrypts stored mailbox app passwords.
4. Load the schema: `mysql -u USER -p DBNAME < app/schema.sql` (later changes are applied by built-in migrations).
5. Create the first admin:
   ```sql
   INSERT INTO users (name, email, password_hash, role, status, created_at)
   VALUES ('Admin', 'admin@example.com', '<output of php -r "echo password_hash(\'yourpass\', PASSWORD_DEFAULT);">', 'admin', 'active', UTC_TIMESTAMP());
   ```
6. Add the cron job:
   ```
   * * * * * /usr/bin/php /path/to/app/cron.php
   ```
   or call `https://your-domain/cron.php?key=CRON_KEY` from an external cron service.
7. Make sure `storage/` is writable and that `/app/`, `/storage/` and `/config.php` return 403.

`SETUP-GUIDE.txt` has a longer Hostinger walkthrough, Gmail app-password setup and deliverability advice.

## Usage

Sign in, connect at least two Gmail accounts (with app passwords and IMAP enabled), let warmup run for 2–3 weeks, import a contact list, then create a campaign, send a test and launch with a small list.

## License

[MIT](LICENSE) © 2026 Kabeer Ahmed
