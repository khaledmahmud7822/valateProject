<?php
// admin/backup.php — Backup & Restore (minimal, functional).
// Gated by settings.edit (lives within admin settings area).
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Backup & Restore';
$nav_section = 'settings';
$required_perm = 'settings.edit';
require __DIR__ . '/_guard.php';
require __DIR__ . '/../lib/backup.php';

$db = db();
$passphrase = (string) setting('backup_passphrase', '');
$hasPhrase = $passphrase !== '';

$step = (string) ($_GET['step'] ?? '');
$backupId = (int) ($_GET['id'] ?? 0);

function mv_bk_row(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM backup_logs WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = (string) ($_POST['op'] ?? '');

    if ($op === 'backup_now') {
        if (!$hasPhrase) {
            flash('Set a backup passphrase in Settings → Backups first.', 'error');
        } else {
            try {
                set_time_limit(600);
                $meta = mv_backup_create('manual');
                if ($meta['verified']) {
                    try {
                        $em = mv_backup_send_email($meta);
                        mv_backup_mark((int) $meta['id'], ['emailed' => $em['sent'] ? 1 : 0]);
                    } catch (Throwable $e) {
                    }
                    if (mv_backup_cloud_configured()) {
                        try {
                            mv_backup_cloud_upload($meta['path'], $meta['filename']);
                            mv_backup_mark((int) $meta['id'], ['cloud' => 1]);
                        } catch (Throwable $e) {
                            flash('Backup created, but cloud upload failed: ' . $e->getMessage(), 'warning');
                        }
                    }
                    mv_backup_prune(MV_BACKUP_KEEP);
                    audit('backup.manual', 'backup', (int) $meta['id'], ['file' => $meta['filename']]);
                    flash('Backup created and verified: ' . $meta['filename'], 'success');
                } else {
                    flash('Backup created but verification failed: ' . $meta['verify_note'], 'error');
                }
            } catch (Throwable $e) {
                flash('Backup failed: ' . $e->getMessage(), 'error');
            }
        }
        redirect(site_url('admin/backup.php'));
    }

    if ($op === 'backup_download') {
        if (!$hasPhrase) {
            flash('Set a backup passphrase in Settings → Backups first.', 'error');
            redirect(site_url('admin/backup.php'));
        }
        try {
            set_time_limit(600);
            $meta = mv_backup_create('manual');
            if (!$meta['verified']) {
                flash('Backup created but verification failed: ' . $meta['verify_note'], 'error');
                redirect(site_url('admin/backup.php'));
            }
            audit('backup.manual_download', 'backup', (int) $meta['id'], ['file' => $meta['filename']]);
            // Stream it straight to the browser — no history click needed.
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $meta['filename'] . '"');
            header('Content-Length: ' . $meta['size']);
            header('Cache-Control: no-store');
            $fh = fopen($meta['path'], 'rb');
            while (!feof($fh)) {
                echo fread($fh, 8192);
                flush();
            }
            fclose($fh);
            exit;
        } catch (Throwable $e) {
            flash('Backup failed: ' . $e->getMessage(), 'error');
            redirect(site_url('admin/backup.php'));
        }
    }

    if ($op === 'delete') {
        $row = mv_bk_row((int) ($_POST['id'] ?? 0));
        if ($row) {
            @unlink(MV_BACKUP_DIR . '/' . $row['filename']);
            $db->prepare('DELETE FROM backup_logs WHERE id = ?')->execute([(int) $row['id']]);
            audit('backup.deleted', 'backup', (int) $row['id'], ['file' => $row['filename']]);
            flash('Backup deleted.', 'success');
        }
        redirect(site_url('admin/backup.php'));
    }

    if ($op === 'upload') {
        if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            flash('Upload failed — choose a .mvbak file.', 'error');
            redirect(site_url('admin/backup.php'));
        }
        $fn = (string) $_FILES['backup_file']['name'];
        if (!str_ends_with(strtolower($fn), '.mvbak')) {
            flash('Only .mvbak backup files are accepted.', 'error');
            redirect(site_url('admin/backup.php'));
        }
        if ($_FILES['backup_file']['size'] > 500 * 1048576) {
            flash('File too large (500 MB limit).', 'error');
            redirect(site_url('admin/backup.php'));
        }
        $dest = MV_BACKUP_DIR . '/uploaded-' . date('Y-m-d-His') . '.mvbak';
        mv_backup_ensure_dir();
        if (!move_uploaded_file($_FILES['backup_file']['tmp_name'], $dest)) {
            flash('Could not store the uploaded file.', 'error');
            redirect(site_url('admin/backup.php'));
        }
        // Validate immediately so a bad file is rejected up front.
        try {
            $pp = (string) ($_POST['passphrase'] ?? '');
            if ($pp === '') {
                $pp = $passphrase;
            }
            $manifest = mv_backup_preview($dest, $pp);
            $id = mv_backup_log([
                'filename' => basename($dest), 'size_bytes' => filesize($dest),
                'sha256' => hash_file('sha256', $dest), 'type' => 'uploaded',
                'status' => 'ok', 'verified' => 1, 'verify_note' => 'uploaded, manifest OK',
                'manifest' => json_encode($manifest),
            ]);
            flash('Backup uploaded and validated.', 'success');
            redirect(site_url('admin/backup.php?step=preview&id=' . $id));
        } catch (Throwable $e) {
            @unlink($dest);
            flash('Uploaded file is not a valid backup: ' . $e->getMessage(), 'error');
            redirect(site_url('admin/backup.php'));
        }
    }

    if ($op === 'restore') {
        $row = mv_bk_row((int) ($_POST['id'] ?? 0));
        $confirm = trim((string) ($_POST['confirm'] ?? ''));
        $pp = (string) ($_POST['passphrase'] ?? '');
        if ($pp === '') {
            $pp = $passphrase;
        }
        if (!$row) {
            flash('Backup not found.', 'error');
        } elseif ($confirm !== 'RESTORE') {
            flash('Type RESTORE to confirm.', 'error');
        } else {
            $path = MV_BACKUP_DIR . '/' . $row['filename'];
            if (!file_exists($path)) {
                flash('Backup file missing from disk.', 'error');
            } else {
                try {
                    set_time_limit(600);
                    $mode = ($_POST['mode'] ?? 'data') === 'full' ? 'full' : 'data';
                    $res = mv_backup_restore($path, $pp, $mode);
                    if ($res['verified']) {
                        flash('Restore completed and verified. Safety backup: ' . $res['safety_backup'], 'success');
                    } else {
                        flash('Restore finished but verification reported a problem: ' . $res['verify_note']
                            . ' Your pre-restore safety backup is: ' . $res['safety_backup'], 'warning');
                    }
                } catch (Throwable $e) {
                    flash('Restore failed: ' . $e->getMessage() . ' Your current data was NOT modified.', 'error');
                }
            }
        }
        redirect(site_url('admin/backup.php'));
    }
}

/* ---------- Data for display ---------- */
$history = $db->query('SELECT * FROM backup_logs ORDER BY id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
$lastOk = null;
foreach ($history as $h) {
    if ($h['status'] === 'ok' && in_array($h['type'], ['weekly', 'manual'], true)) {
        $lastOk = $h;
        break;
    }
}
$previewRow = null;
$previewManifest = null;
if ($step === 'preview' && $backupId > 0) {
    $previewRow = mv_bk_row($backupId);
    if ($previewRow) {
        $m = json_decode((string) ($previewRow['manifest'] ?? ''), true);
        if (is_array($m) && isset($m['created_at'])) {
            $previewManifest = $m;
        } else {
            // Manifest not cached — decrypt fresh (asks nothing, uses saved passphrase).
            try {
                $pp = $hasPhrase ? $passphrase : '';
                $previewManifest = mv_backup_preview(MV_BACKUP_DIR . '/' . $previewRow['filename'], $pp);
            } catch (Throwable $e) {
                flash('Cannot preview: ' . $e->getMessage(), 'error');
            }
        }
    }
}

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1>Backup &amp; Restore</h1>
    <p class="muted">Weekly automatic backups run Sundays at 2:00 AM (Detroit time). The last <?= (int) setting('backup_keep', (string) MV_BACKUP_KEEP) ?> weekly/manual backups are kept (change in Settings &rarr; Backups).</p>
</div>

<?php if (!$hasPhrase): ?>
    <div class="flash flash-warning" role="alert">
        Set a <strong>backup passphrase</strong> in Settings → Backups before creating backups. Without it, backups cannot run, and you will need it to restore.
    </div>
<?php endif; ?>

<?php if ($previewRow && $previewManifest): ?>
    <div class="card">
        <h2>Restore preview — <?= e($previewRow['filename']) ?></h2>
        <?php
        $pm = $previewManifest;
        $tables = $pm['db_tables'] ?? [];
        $totalRows = array_sum(array_map('intval', $tables));
        ?>
        <dl class="dl">
            <dt>Backup date</dt><dd><?= e($pm['created_at'] ?? '?') ?></dd>
            <dt>Type</dt><dd><?= e($pm['type'] ?? '?') ?></dd>
            <dt>Database</dt>
            <dd><?= count($tables) ?> tables, <?= number_format($totalRows) ?> rows total
                <details><summary>Table breakdown</summary>
                    <ul>
                        <?php foreach ($tables as $t => $c): ?>
                            <li><span class="mono"><?= e($t) ?></span>: <?= number_format((int) $c) ?> rows</li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </dd>
            <dt>Files</dt><dd><?= number_format((int) ($pm['files']['total'] ?? 0)) ?> files in archive</dd>
        </dl>
        <div class="flash flash-warning" role="alert">
            Restoring <strong>replaces</strong> the current database and uploads.
            A safety backup of the current state is created automatically first.
            Stripe payments are never re-charged and reminders are never re-sent by a restore.
        </div>
        <form method="post" data-confirm="Restore this backup? Current data will be replaced (a safety backup is kept).">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="restore">
            <input type="hidden" name="id" value="<?= (int) $previewRow['id'] ?>">
            <div class="field">
                <label for="r_pass">Backup passphrase</label>
                <input type="password" id="r_pass" name="passphrase" autocomplete="off"
                       placeholder="<?= $hasPhrase ? 'Leave blank to use the saved passphrase' : 'Enter the backup passphrase' ?>">
            </div>
            <div class="field">
                <label>What to restore</label>
                <label class="check-line"><input type="radio" name="mode" value="data" checked> <strong>Data only</strong> — database + uploads. Your app files and updates stay untouched. (Recommended)</label>
                <label class="check-line"><input type="radio" name="mode" value="full"> <strong>Full restore</strong> — database + uploads + app files. Use for disaster recovery or a fresh server.</label>
            </div>
            <div class="field">
                <label for="r_confirm">Type <span class="mono">RESTORE</span> to confirm</label>
                <input type="text" id="r_confirm" name="confirm" autocomplete="off" required>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-danger">Restore backup</button>
                <a class="btn btn-ghost" href="<?= e(site_url('admin/backup.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <h2>Manual backup</h2>
        <p class="muted">Creates a full encrypted backup right now (database, uploads, app files).</p>
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="backup_now">
            <button type="submit" class="btn btn-primary" <?= $hasPhrase ? '' : 'disabled' ?>>Backup Now</button>
        </form>
        <form method="post" style="display:inline; margin-left:8px">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="backup_download">
            <button type="submit" class="btn btn-outline" <?= $hasPhrase ? '' : 'disabled' ?>>Backup &amp; Download</button>
        </form>
        <?php if ($lastOk): ?>
            <p class="muted small" style="margin-top:10px">Last good backup:
                <span class="mono"><?= e($lastOk['filename']) ?></span><br>
                <?= e($lastOk['created_at']) ?> · <?= round($lastOk['size_bytes'] / 1048576, 2) ?> MB
            </p>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Upload backup file</h2>
        <p class="muted">Upload a <span class="mono">.mvbak</span> file to validate and restore it.</p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="upload">
            <div class="field">
                <label for="up_file">Backup file</label>
                <input type="file" id="up_file" name="backup_file" accept=".mvbak" required>
            </div>
            <div class="field">
                <label for="up_pass">Passphrase (if different from saved)</label>
                <input type="password" id="up_pass" name="passphrase" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-outline">Upload &amp; validate</button>
        </form>
    </div>
</div>

<div class="card">
    <h2>Backup history</h2>
    <?php if ($history === []): ?>
        <div class="empty"><strong>No backups yet</strong>Run your first backup with “Backup Now”.</div>
    <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr>
                <th>Date</th><th>File</th><th>Size</th><th>Type</th><th>Status</th>
                <th>Verified</th><th>Emailed</th><th>Cloud</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td class="nowrap"><?= e($h['created_at']) ?></td>
                    <td class="mono small"><?= e($h['filename']) ?></td>
                    <td><?= round($h['size_bytes'] / 1048576, 2) ?> MB</td>
                    <td><?= e($h['type']) ?></td>
                    <td><?= e($h['status']) ?></td>
                    <td><?= ((int) $h['verified'] === 1) ? 'Yes' : '—' ?></td>
                    <td><?= ((int) $h['emailed'] === 1) ? 'Yes' : '—' ?></td>
                    <td><?= ((int) $h['cloud'] === 1) ? 'Yes' : '—' ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/backup_download.php?id=' . (int) $h['id'])) ?>">Download</a>
                            <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/backup.php?step=preview&id=' . (int) $h['id'])) ?>">Restore</a>
                            <form method="post" class="inline-form" data-confirm="Delete this backup file? This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="op" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-xs">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Automatic schedule</h2>
    <p class="muted">Add this cron job in hPanel → Cron Jobs (once per week, Sunday 02:00):</p>
    <p><code class="mono">curl -s "<?= e(site_url('cron/backup.php')) ?>?token=<?= e(setting('cron_token', '')) ?>"</code></p>
    <p class="muted small">The script verifies it is Sunday in America/Detroit before running. Failure alerts go to the backup email automatically.</p>
    <p><a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/settings.php')) ?>">← Back to Settings</a></p>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
