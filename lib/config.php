<?php
/**
 * Metro Valet Parking — application configuration.
 *
 * No side effects beyond constants: defines path constants and the database
 * location. Loaded FIRST by lib/bootstrap.php. Never require bootstrap.php
 * from here (bootstrap requires this file).
 */

// Absolute filesystem paths, derived from this file's location.
define('APP_ROOT', dirname(__DIR__));          // .../app
define('LIB_DIR', __DIR__);                    // .../app/lib
define('DATA_DIR', APP_ROOT . '/data');        // SQLite file + ratelimit store
define('UPLOADS_DIR', APP_ROOT . '/uploads');  // user uploads (PHP execution denied)

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
// Default: local SQLite file. Zero-config, works on Hostinger shared hosting.
$DB_PATH = DATA_DIR . '/metrovalet.sqlite';

// --- MySQL switch ------------------------------------------------------------
// To use MySQL instead of SQLite, comment out the $DB_PATH line above and
// uncomment the three lines below (then create the schema manually — see
// INSTALL.md — and delete install.php):
//
// $DB_PATH = 'mysql:host=localhost;dbname=metrovalet;charset=utf8mb4';
// $DB_USER = 'your_mysql_user';
// $DB_PASS = 'your_mysql_password';

// ---------------------------------------------------------------------------
// Application constants
// ---------------------------------------------------------------------------
define('APP_NAME', 'Metro Valet Parking'); // fallback; real name lives in settings
define('APP_TIMEZONE', 'America/Detroit');  // the one timezone used everywhere
define('APP_VERSION', '1.0.0');
define('SESSION_NAME', 'mv_session');       // custom session name (shared-hosting safe)
