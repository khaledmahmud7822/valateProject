<?php
require __DIR__ . '/../lib/bootstrap.php';

/* ------------------------------------------------------------------
 * cron/backup.php — weekly automatic backup.
 *
 * Token-guarded: web => ?token= must equal setting('cron_token');
 * CLI  => php cron/backup.php <token>   or   --token=<token>.
 *
 * Schedule (hPanel cron): every Sunday 02:00 America/Detroit:
 *   curl -s "https://YOURDOMAIN/cron/backup.php?token=TOKEN"
 *
 * The script double-checks Detroit time is Sunday (unless ?force=1).
 * Steps: create -> verify -> email -> cloud upload -> prune.
 * Any failure triggers an immediate alert email to the admin.
 * ------------------------------------------------------------------ */

$is_cli = (php_sapi_name() === 'cli');
$token = '';
$force = false;

if ($is_cli) {
    $args = isset($argv) && is_array($argv) ? $argv : array();
    array_shift($args);
    foreach ($args as $a) {
        $a = (string) $a;
        if (strpos($a, '--token=<redacted>') === 0) {
            $token = substr($a, 8);
        } elseif ($a === '--force' || $a === 'force') {
            $force = true;
        } elseif ($token === '' && $a !== '' && $a[0] !== '-') {
            $token = $a;
        }
    }
} else {
    $token = (string) ($_GET['token'] ?? '');
    $force = isset($_GET['force']) && $_GET['force'] === '1';
}

$expected = (string) setting('cron_token', '');
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo "Forbidden.\n";
    exit(1);
}

require __DIR__ . '/../lib/backup.php';

$detroit = new DateTime('now', new DateTimeZone('America/Detroit'));
if (!$force && $detroit->format('w') !== '0') {
    // Not Sunday in Detroit — skip quietly (protects against a
    // misconfigured cron schedule creating off-day backups).
    echo 'Skip: Detroit time is ' . $detroit->format('l H:i') . ', not Sunday.' . "\n";
    exit(0);
}

$log = [];
$fail = function (string $msg) use (&$log) {
    $log[] = 'FAIL: ' . $msg;
    mv_backup_send_alert('weekly backup', implode("\n", $log));
    try {
        audit('backup.failed', 'backup', null, ['log' => implode(' | ', $log)]);
    } catch (Throwable $e) {
    }
    echo implode("\n", $log) . "\n";
    exit(1);
};

try {
    $meta = mv_backup_create('weekly');
} catch (Throwable $e) {
    $fail('create: ' . $e->getMessage());
}
$log[] = 'Created ' . $meta['filename'] . ' (' . round($meta['size'] / 1048576, 2) . ' MB).';

if (!$meta['verified']) {
    $fail('verify: ' . $meta['verify_note']);
}
$log[] = 'Verified: ' . $meta['verify_note'];
mv_backup_mark((int) $meta['id'], ['status' => 'ok']);

// Email (attach or expiring link).
try {
    $em = mv_backup_send_email($meta);
    mv_backup_mark((int) $meta['id'], ['emailed' => $em['sent'] ? 1 : 0]);
    $log[] = 'Email: ' . ($em['sent'] ? 'sent (' . $em['note'] . ')' : 'NOT sent (' . $em['note'] . ')');
    if (!$em['sent']) {
        mv_backup_send_alert('weekly backup email', 'Backup created OK but the weekly email failed: ' . $em['note']);
    }
} catch (Throwable $e) {
    $log[] = 'Email error: ' . $e->getMessage();
}

// Cloud upload (if configured).
if (mv_backup_cloud_configured()) {
    try {
        $key = mv_backup_cloud_upload($meta['path'], $meta['filename']);
        mv_backup_mark((int) $meta['id'], ['cloud' => 1]);
        $log[] = 'Cloud: uploaded (' . $key . ')';
    } catch (Throwable $e) {
        $log[] = 'Cloud FAILED: ' . $e->getMessage();
        mv_backup_send_alert('weekly backup cloud upload', $e->getMessage());
    }
} else {
    $log[] = 'Cloud: not configured, skipped.';
}

// Prune old.
try {
    $pruned = mv_backup_prune(MV_BACKUP_KEEP);
    $log[] = 'Prune: removed ' . $pruned . ' old backup(s), keeping ' . MV_BACKUP_KEEP . '.';
} catch (Throwable $e) {
    $log[] = 'Prune error: ' . $e->getMessage();
}

try {
    audit('backup.weekly', 'backup', (int) $meta['id'], ['file' => $meta['filename'], 'size' => $meta['size']]);
} catch (Throwable $e) {
}

echo implode("\n", $log) . "\n";
echo "Done.\n";
