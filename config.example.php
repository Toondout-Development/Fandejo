<?php
/**
 * Fandejo Configuration Example
 *
 * Copy this file to `config.php` in the project root (next to `public/`)
 * to configure SQLite3 authentication and directory sandboxing.
 *
 * Alternatively, you can log in to SQLite mode through the UI on first run
 * to automatically generate `config.php` with an Argon2id password hash.
 */
return [
    // Allowed directories for SQLite database files (.sqlite, .sqlite3, .db).
    // Fandejo treats these directories as a database catalog—only files directly
    // inside these directories can be listed, created, queried, or dropped.
    'sqlite_dirs' => [
        __DIR__ . '/data',
    ],

    // SQLite accounts: map of username => Argon2id/Bcrypt password_hash().
    // Generate a hash on the CLI with:
    //   php -r 'echo password_hash("your-secret-password", PASSWORD_ARGON2ID) . PHP_EOL;'
    'sqlite_users' => [
        // 'admin' => '$argon2id$v=19$m=65536,t=4,p=1$...',
    ],

    // Authentication brute-force protection & session hardening:
    'max_login_attempts'    => 5,      // Hard lockout after N consecutive failed attempts (per account + IP)
    'admin_lockout_seconds' => 900,    // Self-lockout recovery window (15m): lets a full admin sign in with a valid password after cooldown
    'ip_max_attempts'       => 12,     // Temporary IP rate-limit threshold across accounts
    'ip_lockout_seconds'    => 900,    // Temporary IP cooldown window (15 minutes)
    'session_idle_ttl'      => 3600,   // Auto-expire idle sessions after N seconds (1 hour)
];
