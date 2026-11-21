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
];
