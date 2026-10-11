<?php
/**
 * Metro Valet Parking — database access.
 *
 * Requires lib/config.php (for $DB_PATH / $DB_USER / $DB_PASS); loaded by
 * lib/bootstrap.php before this file. Defines functions only — no side
 * effects at require time.
 */

/**
 * Create a fresh PDO connection from the configured DSN.
 * SQLite: ERRMODE_EXCEPTION + foreign keys ON + busy timeout.
 */
function db_init(): PDO {
    $path = $GLOBALS['DB_PATH'] ?? (DATA_DIR . '/metrovalet.sqlite');

    if (is_string($path) && str_starts_with($path, 'mysql:')) {
        $pdo = new PDO($path, $GLOBALS['DB_USER'] ?? '', $GLOBALS['DB_PASS'] ?? '');
    } else {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . $path);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    return $pdo;
}

/**
 * Return the global PDO connection created by lib/bootstrap.php.
 * ALL queries in the app go through this handle with prepared statements.
 */
function db(): PDO {
    return $GLOBALS['db'];
}
