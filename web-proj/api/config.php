<?php
// Central config for the API. Reads environment variables when available.
// For local dev you can set these in your shell or a .env loader (do NOT commit secrets).

return [
    // Admin secret for protected endpoints
    'admin_secret' => getenv('EVENTS_ADMIN_SECRET') ?: 'change-me-to-a-secure-value',

    // Database connection settings. If DB_DRIVER is 'mysql' the app will use PDO MySQL.
    'db' => [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: (getenv('DB_DRIVER') === 'mysql' ? '3306' : ''),
      'name' => getenv('DB_NAME') ?: 'eventfinder',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        // For SQLite the file path is relative to project and set in db.php
    ],
];
