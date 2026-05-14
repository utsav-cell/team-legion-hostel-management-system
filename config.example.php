<?php
// ─────────────────────────────────────────────────
// config.example.php — Template for config.php
//   Copy this file to config.php and fill in your real credentials.
//   config.php is gitignored so secrets never end up in version control.
//   See README.md for the full list of optional keys (SMTP, payment gateways, etc.).
// ─────────────────────────────────────────────────
return [
    'DB_HOST'    => '127.0.0.1',
    'DB_USER'    => 'root',
    'DB_PASS'    => '',
    'DB_NAME'    => 'hostel2',
    'JWT_SECRET' => 'change-this-secret-key',
];
