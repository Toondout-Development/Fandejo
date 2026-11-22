# Fandejo

*fahn-DAY-oh* — **Fandejo** (Esperanto for *"foundry"*) is a fast, modern web workbench for MariaDB, MySQL, and SQLite3, developed and released by **[Toondout](https://toondout.com)**.

> *Cast, inspect, and query your databases in one workspace — lightweight, multi-engine database administration without the bloat.*

- **Canonical Repository:** https://github.com/Toondout-Development/Fandejo
- **Author:** [Toondout](https://toondout.com)
- **License:** [Fandejo Source-Available & Free-Use License](LICENSE) (free to download, install, and use; PRs welcome; public redistribution/rebranding prohibited)

## Requirements
- **PHP 8.1+** (built and tested for **PHP 8.5+**)
- Extensions: `PDO`, `sodium`, `zlib`, and `pdo_mysql` and/or `pdo_sqlite`

## Automated Server Installation (`/fandejo`)

Deploy Fandejo to `/usr/share/fandejo` and expose it at `http(s)://<your-server>/fandejo` on Apache, Nginx, or a custom webroot.

### Option 1: One-Line Remote Install (from GitHub Releases)
```bash
curl -fsSL https://raw.githubusercontent.com/Toondout-Development/Fandejo/main/install.sh | sudo bash
```

### Option 2: Install from Local Clone
```bash
git clone https://github.com/Toondout-Development/Fandejo.git
cd Fandejo
sudo ./install.sh
```

- **Apache (`apache2` / `httpd`):** Automatically writes and enables `/etc/apache2/conf-available/fandejo.conf` (or `/etc/httpd/conf.d/fandejo.conf`) with `Alias /fandejo /usr/share/fandejo/public` and reloads Apache.
- **Nginx:** Automatically detects your active PHP-FPM socket, writes `/etc/nginx/snippets/fandejo.conf`, includes it in your default server block, and reloads Nginx.
- **Updates, Version Pinning & Custom Options:**
  ```bash
  sudo /usr/share/fandejo/install.sh --check-update           # Check GitHub Releases for a newer version
  sudo /usr/share/fandejo/install.sh --update                 # Upgrade to the latest GitHub Release (preserves config.php & data/)
  sudo /usr/share/fandejo/install.sh --update --version=v1.0.0
  sudo ./install.sh --url-path=/fandejo --sqlite-user=admin --sqlite-pass='your-secret'
  sudo ./install.sh --webroot=/var/www/html
  sudo /usr/share/fandejo/install.sh --uninstall              # Remove web server config and /usr/share/fandejo
  ```

## Quick Start (Local Development)

```bash
php -S localhost:8090 -t public
```

Open http://localhost:8090 and sign in to your MariaDB/MySQL server or open an SQLite3 file.

## Features
- **Foundry Dashboard & Live Processlist:** At-a-glance server KPIs (databases, tables, storage size, uptime), engine & PHP 8.5 runtime diagnostics, live MySQL/MariaDB server processlist (`SHOW FULL PROCESSLIST` with `KILL QUERY` / `KILL`), account password/privilege shortcuts, and built-in documentation modals.
- **Browse, Column Filtering & Inline Editing:**
  - Index-friendly `WHERE` filter builder (`=`, `!=`, `CONTAINS`, `STARTS`, `ENDS`, `LIKE`, `>`, `<`, `IN`, `IS NULL`, etc.) plus full-table search and single-click column sorting.
  - Double-click any cell to edit inline (`Enter` to save, `Esc` to cancel, 1-click `NULL`), duplicate rows (`⧉`), or use the modal editor with custom calendar/time and searchable Foreign Key (`🔗 Pick`) popovers.
  - Clickable Foreign Key (`↗`) navigation across tables with a contextual `← Back to <table>` breadcrumb stack.
  - Bulk row selection (with `Shift`-click range support) to edit, delete, or export selected rows as CSV or SQL.
  - Safe 3-tier row identity resolution (`PRIMARY KEY` → `UNIQUE` key → full-row match with `LIMIT 1`) for tables without a primary key.
- **Schema, Views, Triggers & Routines Designer:**
  - Create and alter columns with live `ALTER TABLE` SQL previews, date/time default presets (`CURRENT_TIMESTAMP`, `ON UPDATE CURRENT_TIMESTAMP`), and collapsible per-column collation override.
  - Manage composite, unique, primary, and fulltext indexes, plus visual foreign keys.
  - Create and edit SQL `VIEW` definitions, table `TRIGGER`s (`BEFORE`/`AFTER`/`INSTEAD OF`), and MySQL/MariaDB `PROCEDURE`s & `FUNCTION`s.
  - Database-wide and table-level charset/collation conversion (including automatic foreign-key guarding and binary re-labeling for legacy Mojibake cleanup).
  - Table maintenance & diagnostics (`CHECK`, `ANALYZE`, `OPTIMIZE`, `REPAIR`, `VACUUM`, `PRAGMA integrity_check`, and `AUTO_INCREMENT` counter management).
- **SQL Scratchpad:** Multi-statement execution (`Ctrl+Enter`), partial selection execution, `🔍 Explain` (`EXPLAIN` / `EXPLAIN QUERY PLAN`), `✨ Format`, `Tab` / `Shift+Tab` indentation, capped result-set memory protection, quick table snippets, clickable column chips, saved query bookmarks (`★ Saved`), and 100-entry deduplicated query history (`⏱ History`).
- **CSV & SQL Import / Export (`.sql`, `.csv`, `.gz`, `.zip`):**
  - Full-database or per-table SQL dumps with batched multi-row `INSERT`s, hex-encoded `BLOB`s, views, triggers, routines, and optional `CREATE DATABASE IF NOT EXISTS`.
  - Pure-PHP Gzip (`.gz`) and PKZIP (`.zip`) compression and decompression for both SQL and CSV exports and imports.
  - Interactive CSV Import Wizard with automatic delimiter & column-type detection, column mapping, duplicate handling (`INSERT IGNORE` / `REPLACE`), and transactional batch progress.
- **User Accounts, Grants & Brute-Force Lockout Manager:** Visual privilege editor grouped into Data, Structure, and Administration with 1-click role presets (*Read only*, *Read / write*, *Developer*, *Everything*), live `GRANT`/`REVOKE` diff preview, SQLite multi-admin management, and per-(Account + IP) brute-force lockout tracking & unlocking.
- **URL Hash Routing & Themes:** Deep-linked `#db/table/tab` hash routing (preserving place across `F5` refresh and browser Back/Forward) and high-contrast *Obsidian & Molten Amber* dark and *Daylight Foundry* light themes.

## Security Architecture
- **Libsodium Split-Key Session Encryption & Subpath Cookie Isolation:** Database passwords are encrypted with `XSalsa20-Poly1305` (`sodium_crypto_secretbox`). The server session file stores only ciphertext, while the random 256-bit key lives only in an `HttpOnly`, `SameSite=Strict` browser cookie scoped to Fandejo's URL path (`/fandejo/`). Sessions are bound to client fingerprints and expire after inactivity (`session_idle_ttl`).
- **Brute-Force Protection & Per-(Account + IP) Hard Lockout:**
  - Consecutive failed logins trigger progressive tarpit delays and lock out the offending `(Account, Client IP)` after `max_login_attempts` (default `5`), while isolating legitimate admins on other IPs from denial-of-service.
  - Full-privilege administrators can unlock accounts/IPs in the **Users** tab, self-recover after `admin_lockout_seconds` (`15m`) with a valid admin password, or unlock immediately from the server CLI:
    ```bash
    php public/api.php --unlock
    ```
- **SQLite3 Authentication Gate & Directory Sandbox (`config.php`):**
  - SQLite mode requires an authenticated username and Argon2id password hash stored in `config.php` (copy from [config.example.php](config.example.php) or initialize automatically on first SQLite login).
  - SQLite access is strictly sandboxed to the configured `sqlite_dirs` catalog directories (defaulting to `./data`). Arbitrary filesystem paths, symlinks, and `../` traversal are blocked.
- **CSRF & Parameterization:** Per-session `X-CSRF` header verification on all state-changing actions, strict identifier quoting, and prepared statements for row data.
- Do not expose to the public internet without HTTPS (TLS) and appropriate network/access restrictions.

## Contributing & License
Copyright © 2026 **[Toondout](https://toondout.com)**. Released under the [Fandejo Source-Available & Free-Use License](LICENSE).
Pull Requests, bug reports, and feature contributions to [github.com/Toondout-Development/Fandejo](https://github.com/Toondout-Development/Fandejo) are welcome! Public redistribution of rebranded forks or third-party commercial hosting is not permitted without written permission from Toondout.
