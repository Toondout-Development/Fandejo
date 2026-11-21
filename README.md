# Fandejo

*fahn-DAY-oh* — **Fandejo** (Esperanto for *"foundry"*) is a fast, modern web workbench for MariaDB, MySQL, and SQLite3.

> *Cast, inspect, and query your databases in one workspace — lightweight, multi-engine database administration without the bloat.*

## Requirements
- **PHP 8.1+** (built and tested for **PHP 8.5+**)
- Extensions: `PDO`, `sodium`, and `pdo_mysql` and/or `pdo_sqlite`

## Quick Start (Local Development)

```bash
php -S localhost:8090 -t public
```

Open http://localhost:8090 and sign in to your MariaDB/MySQL server or open an SQLite3 file.

## Features
- **Foundry Dashboard:** At-a-glance server KPIs (databases, tables, storage size, uptime), engine & PHP 8.5 runtime diagnostics, account password/privilege shortcuts, default engine/collation preferences, and built-in documentation modals.
- **Browse & Inline Editing:** Full-table search across all columns, single-click column sorting, pagination, custom calendar/time popover picker, and one-click CSV export of filtered results.
- **Schema & Structure Designer:**
  - Create and alter columns with live `ALTER TABLE` SQL previews, date/time default presets (`CURRENT_TIMESTAMP`, `ON UPDATE CURRENT_TIMESTAMP`), and collapsible per-column collation override.
  - Manage composite, unique, primary, and fulltext indexes.
  - Visual foreign key editor with automatic column loading and referential action rules.
  - Database-wide and table-level charset/collation conversion (including automatic foreign-key guarding and binary re-labeling for legacy Mojibake cleanup).
- **SQL Scratchpad:** Multi-statement execution (`Ctrl+Enter`), partial selection execution, quick table snippets, clickable column chips, saved query bookmarks (`★ Saved`), and 100-entry deduplicated query history (`⏱ History`).
- **CSV & SQL Import / Export:** Streaming unbuffered CSV exports and an interactive CSV Import Wizard with automatic delimiter & column-type detection, column mapping, duplicate handling (`INSERT IGNORE` / `REPLACE`), and transactional batch progress.
- **User Accounts & Grants Manager:** Visual privilege editor grouped into Data, Structure, and Administration with 1-click role presets (*Read only*, *Read / write*, *Developer*, *Everything*) and live `GRANT`/`REVOKE` diff preview.
- **Dark & Light Foundry Themes:** High-contrast *Obsidian & Molten Amber* dark theme and *Daylight Foundry* light theme.

## Security Architecture
- **Libsodium Split-Key Session Encryption:** Database passwords are encrypted with `XSalsa20-Poly1305` (`sodium_crypto_secretbox`). The server session file stores only ciphertext, while the random 256-bit key lives only in an `HttpOnly`, `SameSite=Strict` browser cookie.
- **SQLite3 Authentication Gate & Directory Sandbox (`config.php`):**
  - SQLite mode requires an authenticated username and Argon2id password hash stored in `config.php` (copy from [config.example.php](file:///media/brian/LinuxData/Projects/Fandejo/config.example.php) or initialize automatically on first SQLite login).
  - SQLite access is strictly sandboxed to the configured `sqlite_dirs` catalog directories (defaulting to `./data`). Arbitrary filesystem paths, symlinks, and `../` traversal are blocked, and every `.sqlite` / `.sqlite3` / `.db` file in the sandbox appears as a first-class database in the sidebar and dashboard.
- **CSRF & Parameterization:** Per-session `X-CSRF` header verification on all state-changing actions, strict identifier quoting, and prepared statements for row data.
- Do not expose to the public internet without HTTPS (TLS) and appropriate network/access restrictions.
