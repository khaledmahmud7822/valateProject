<?php
// admin/backup_download.php — serve backup files.
// Logged-in (settings.edit) via ?id=, or emailed expiring link via ?token=.
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/backup.php';

$db = db();
$backupId = 0;

if (isset($_GET['token']) && $_GET['token'] !== '') {
    $hash = hash('sha256', (string) $_GET['token']);
    $st = $db->prepare('SELECT * FROM backup_tokens WHERE token_hash = ? AND used = 0 LIMIT 1');
    $st->execute([$hash]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t || strtotime($t['expires_at']) < time()) {
        http_response_code(403);
        echo 'This download link is invalid or expired.';
        exit;
    }
    $backupId = (int) $t['backup_id'];
    // Single-use.
    $db->prepare('UPDATE backup_tokens SET used = 1 WHERE id = ?')->execute([(int) $t['id']]);
} else {
    require __DIR__ . '/_guard.php';
    if (!can('settings.edit')) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
    $backupId = (int) ($_GET['id'] ?? 0);
}

$st = $db->prepare('SELECT filename FROM backup_logs WHERE id = ? LIMIT 1');
$st->execute([$backupId]);
$filename = $st->fetchColumn();
if (!$filename) {
    http_response_code(404);
    echo 'Backup not found.';
    exit;
}
$filename = basename((string) $filename);
$path = MV_BACKUP_DIR . '/' . $filename;
if (!file_exists($path)) {
    http_response_code(404);
    echo 'Backup file no longer on disk.';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
$fh = fopen($path, 'rb');
while (!feof($fh)) {
    echo fread($fh, 8192);
    flush();
}
fclose($fh);
