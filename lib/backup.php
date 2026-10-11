<?php
// lib/backup.php — weekly backup & one-click restore engine (part 1: ZIP + crypto).
//
// - Pure-PHP ZIP writer/reader (no ZipArchive dependency).
// - AES-256-CBC encryption, key from admin passphrase via PBKDF2.

if (!defined('MV_BACKUP_VERSION')) {
    define('MV_BACKUP_VERSION', 1);
}
if (!defined('MV_BACKUP_DIR')) {
    define('MV_BACKUP_DIR', dirname(__DIR__) . '/data/backups');
}
if (!defined('MV_BACKUP_KEEP')) {
    define('MV_BACKUP_KEEP', 12);
}

/* ================= ZIP writer / reader ================= */

function mvzip_dos_datetime(int $ts): array
{
    $d = getdate($ts);
    $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | ((int) ($d['seconds'] / 2));
    $date = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
    return [$time & 0xFFFF, $date & 0xFFFF];
}

function mvzip_open_write(string $path): array
{
    $fh = fopen($path, 'wb');
    if (!$fh) {
        throw new RuntimeException('Cannot write ZIP to ' . $path);
    }
    return ['fh' => $fh, 'path' => $path, 'central' => [], 'offset' => 0];
}

function mvzip_add(array &$w, string $name, $dataOrPath, bool $isPath = false, int $mtime = 0): void
{
    $name = str_replace('\\', '/', $name);
    if ($isPath) {
        $data = file_get_contents($dataOrPath);
        if ($data === false) {
            throw new RuntimeException('Cannot read ' . $dataOrPath);
        }
        if ($mtime === 0) {
            $mtime = (int) filemtime($dataOrPath);
        }
    } else {
        $data = (string) $dataOrPath;
        if ($mtime === 0) {
            $mtime = time();
        }
    }
    [$dosTime, $dosDate] = mvzip_dos_datetime($mtime);
    $crc = crc32($data);
    $uncomp = strlen($data);
    $deflated = gzdeflate($data, 6);
    if ($deflated === false || strlen($deflated) >= $uncomp) {
        $method = 0;
        $payload = $data;
    } else {
        $method = 8;
        $payload = $deflated;
    }
    $comp = strlen($payload);
    $flags = 1 << 11; // UTF-8 filenames

    $local = "PK\x03\x04"
        . pack('v', 20) . pack('v', $flags) . pack('v', $method)
        . pack('v', $dosTime) . pack('v', $dosDate)
        . pack('V', $crc) . pack('V', $comp) . pack('V', $uncomp)
        . pack('v', strlen($name)) . pack('v', 0)
        . $name . $payload;

    $localOffset = $w['offset'];
    fwrite($w['fh'], $local);
    $w['offset'] += strlen($local);

    $w['central'][] = "PK\x01\x02"
        . pack('v', 0x031E)
        . pack('v', 20) . pack('v', $flags) . pack('v', $method)
        . pack('v', $dosTime) . pack('v', $dosDate)
        . pack('V', $crc) . pack('V', $comp) . pack('V', $uncomp)
        . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0)
        . pack('v', 0) . pack('v', 0)
        . pack('V', 0100644 << 16)
        . pack('V', $localOffset)
        . $name;
}

function mvzip_close(array $w): void
{
    $cdOffset = $w['offset'];
    $cd = implode('', $w['central']);
    fwrite($w['fh'], $cd);
    $cdSize = strlen($cd);
    $count = count($w['central']);
    fwrite($w['fh'], "PK\x05\x06"
        . pack('v', 0) . pack('v', 0)
        . pack('v', $count) . pack('v', $count)
        . pack('V', $cdSize) . pack('V', $cdOffset)
        . pack('v', 0));
    fclose($w['fh']);
}

function mvzip_parse(string $data, string $path = ''): array
{
    $len = strlen($data);
    $eocdPos = -1;
    $searchFrom = max(0, $len - 65558);
    for ($i = $len - 22; $i >= $searchFrom; $i--) {
        if (substr($data, $i, 4) === "PK\x05\x06") {
            $eocdPos = $i;
            break;
        }
    }
    if ($eocdPos < 0) {
        throw new RuntimeException('Not a valid ZIP archive.');
    }
    $eocd = unpack('vcount_disk/vcount/Vcd_size/Vcd_offset', substr($data, $eocdPos + 8, 12));
    $files = [];
    $pos = $eocd['cd_offset'];
    for ($n = 0; $n < $eocd['count']; $n++) {
        if (substr($data, $pos, 4) !== "PK\x01\x02") {
            throw new RuntimeException('Corrupt ZIP central directory.');
        }
        $h = unpack('vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vuncomp/vnamelen/vextralen/vcommentlen', substr($data, $pos + 8, 30));
        $h2 = unpack('Vlocaloff', substr($data, $pos + 42, 4));
        $name = substr($data, $pos + 46, $h['namelen']);
        $h['localoff'] = $h2['localoff'];
        $files[$name] = $h;
        $pos += 46 + $h['namelen'] + $h['extralen'] + $h['commentlen'];
    }
    return ['files' => $files, 'data' => $data, 'path' => $path];
}

function mvzip_open(string $path): array
{
    $data = file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Cannot read ZIP ' . $path);
    }
    return mvzip_parse($data, $path);
}

function mvzip_extract(array $zip, string $name)
{
    if (!isset($zip['files'][$name])) {
        return false;
    }
    $e = $zip['files'][$name];
    $data = $zip['data'];
    $off = $e['localoff'];
    if (substr($data, $off, 4) !== "PK\x03\x04") {
        throw new RuntimeException('Corrupt ZIP local header for ' . $name);
    }
    $lh = unpack('vmethod/vtime/vdate/Vcrc/Vcomp/Vuncomp/vnamelen/vextralen', substr($data, $off + 8, 22));
    $dataOff = $off + 30 + $lh['namelen'] + $lh['extralen'];
    $payload = substr($data, $dataOff, $lh['comp']);
    if ($lh['method'] === 8) {
        $out = gzinflate($payload);
        if ($out === false) {
            throw new RuntimeException('Decompression failed for ' . $name);
        }
        return $out;
    }
    if ($lh['method'] === 0) {
        return $payload;
    }
    throw new RuntimeException('Unsupported ZIP method for ' . $name);
}

/* ================= AES-256-CBC file encryption ================= */

function mv_backup_derive_key(string $passphrase, string $salt, int $iter = 100000): string
{
    return hash_pbkdf2('sha256', $passphrase, $salt, $iter, 32, true);
}

function mv_backup_encrypt_file(string $inPath, string $outPath, string $passphrase): array
{
    $plain = file_get_contents($inPath);
    if ($plain === false) {
        throw new RuntimeException('Cannot read ' . $inPath);
    }
    $salt = random_bytes(16);
    $iv = random_bytes(16);
    $key = mv_backup_derive_key($passphrase, $salt);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    $header = json_encode(['salt' => base64_encode($salt), 'iv' => base64_encode($iv), 'iter' => 100000, 'v' => 1]);
    $out = "MVBAK1\x00\x00" . pack('N', strlen($header)) . $header . $cipher;
    if (file_put_contents($outPath, $out) === false) {
        throw new RuntimeException('Cannot write ' . $outPath);
    }
    return ['size' => strlen($out)];
}

function mv_backup_decrypt_file(string $inPath, string $outPath, string $passphrase): void
{
    $fh = fopen($inPath, 'rb');
    if (!$fh) {
        throw new RuntimeException('Cannot read ' . $inPath);
    }
    $magic = fread($fh, 8);
    if ($magic !== "MVBAK1\x00\x00") {
        fclose($fh);
        throw new RuntimeException('Not a Metro Valet backup file (bad magic).');
    }
    $hlen = unpack('N', fread($fh, 4))[1];
    if ($hlen < 10 || $hlen > 10000) {
        fclose($fh);
        throw new RuntimeException('Corrupt backup header.');
    }
    $header = json_decode(fread($fh, $hlen), true);
    if (!is_array($header) || empty($header['salt']) || empty($header['iv'])) {
        fclose($fh);
        throw new RuntimeException('Corrupt backup header.');
    }
    $cipher = stream_get_contents($fh);
    fclose($fh);
    $key = mv_backup_derive_key($passphrase, base64_decode($header['salt']), (int) ($header['iter'] ?? 100000));
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, base64_decode($header['iv']));
    if ($plain === false) {
        throw new RuntimeException('Decryption failed — wrong passphrase or corrupt file.');
    }
    if (file_put_contents($outPath, $plain) === false) {
        throw new RuntimeException('Cannot write ' . $outPath);
    }
}

/* ================= Backup creation ================= */

function mv_backup_passphrase(): string
{
    $p = (string) setting('backup_passphrase', '');
    if ($p === '') {
        throw new RuntimeException('Set a backup passphrase in Settings → Backups first.');
    }
    return $p;
}

function mv_backup_ensure_dir(): string
{
    $dir = MV_BACKUP_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new RuntimeException('Cannot create backup directory.');
    }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
    $idx = $dir . '/index.html';
    if (!file_exists($idx)) {
        @file_put_contents($idx, '');
    }
    return $dir;
}

/** Files/dirs (relative to app root) excluded from app-file backup. */
function mv_backup_excludes(): array
{
    return ['data/backups', 'data/ratelimit'];
}

function mv_backup_create(string $type = 'manual', string $passphrase = ''): array
{
    if ($passphrase === '') {
        $passphrase = mv_backup_passphrase();
    }
    $appRoot = dirname(__DIR__);
    $dir = mv_backup_ensure_dir();
    $ts = time();
    $stamp = date('Y-m-d-His', $ts);
    $base = "metrovalet-backup-{$stamp}-{$type}";

    $tmpZip = $dir . '/.' . $base . '.zip.tmp';
    $final = $dir . '/' . $base . '.mvbak';
    if (file_exists($tmpZip)) {
        @unlink($tmpZip);
    }

    $db = db();
    // Consistent DB snapshot via a DEDICATED connection (VACUUM INTO
    // fails if the shared handle has statements in progress).
    $snapPath = $dir . '/.' . $base . '.sqlite';
    $snapDb = new PDO('sqlite:' . $appRoot . '/data/metrovalet.sqlite');
    $snapDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    try {
        $snapDb->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
    }
    $snapDb->exec("VACUUM INTO '" . str_replace("'", "''", $snapPath) . "'");
    $snapDb = null;

    // Row counts for manifest.
    $tables = [];
    try {
        $tnames = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tnames as $t) {
            $tables[$t] = (int) $db->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $t) . '"')->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    $checksums = [];
    $fileCount = 0;
    $w = mvzip_open_write($tmpZip);
    try {
        // DB snapshot.
        mvzip_add($w, 'data/metrovalet.sqlite', $snapPath, true);
        $checksums['data/metrovalet.sqlite'] = hash_file('sha256', $snapPath);

        // Uploads.
        $upDir = $appRoot . '/uploads';
        $upCount = 0;
        if (is_dir($upDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($upDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $f) {
                $rel = 'uploads/' . substr($f->getPathname(), strlen($upDir) + 1);
                $rel = str_replace('\\', '/', $rel);
                mvzip_add($w, $rel, $f->getPathname(), true);
                $checksums[$rel] = hash_file('sha256', $f->getPathname());
                $upCount++;
            }
        }

        // App files (everything else, minus excludes).
        $excludes = mv_backup_excludes();
        $appCount = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            $full = $f->getPathname();
            $rel = substr($full, strlen($appRoot) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($rel === 'data/metrovalet.sqlite' || str_starts_with($rel, 'data/metrovalet.sqlite-')) {
                continue; // snapshot already added
            }
            if (str_starts_with($rel, 'uploads/')) {
                continue; // already added
            }
            $skip = false;
            foreach ($excludes as $ex) {
                if ($rel === $ex || str_starts_with($rel, $ex . '/')) {
                    $skip = true;
                    break;
                }
            }
            if ($skip || str_ends_with($rel, '.mvbak')) {
                continue;
            }
            mvzip_add($w, $rel, $full, true);
            $appCount++;
        }
        $fileCount = $upCount + $appCount + 1;

        // Manifest.
        $manifest = [
            'app' => 'metro-valet',
            'backup_version' => MV_BACKUP_VERSION,
            'created_at' => date('c', $ts),
            'type' => $type,
            'db_tables' => $tables,
            'files' => ['total' => $fileCount, 'uploads' => $upCount, 'app' => $appCount],
            'checksums' => $checksums,
            'php' => PHP_VERSION,
        ];
        mvzip_add($w, 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        mvzip_close($w);
    } catch (Throwable $e) {
        try {
            fclose($w['fh']);
        } catch (Throwable $e2) {
        }
        @unlink($tmpZip);
        @unlink($snapPath);
        throw $e;
    }
    @unlink($snapPath);

    // Encrypt.
    mv_backup_encrypt_file($tmpZip, $final, $passphrase);
    @unlink($tmpZip);

    $size = filesize($final);
    $sha = hash_file('sha256', $final);

    // Verify immediately: decrypt + checksum the manifest.
    $verified = false;
    $verifyNote = '';
    try {
        $v = mv_backup_verify_file($final, $passphrase);
        $verified = true;
        $verifyNote = 'decrypt+manifest OK, ' . count($v['checksums'] ?? []) . ' checksums';
    } catch (Throwable $e) {
        $verifyNote = 'verify failed: ' . $e->getMessage();
    }

    $id = mv_backup_log([
        'filename' => basename($final),
        'size_bytes' => $size,
        'sha256' => $sha,
        'type' => $type,
        'status' => $verified ? 'ok' : 'verify_failed',
        'verified' => $verified ? 1 : 0,
        'verify_note' => $verifyNote,
        'manifest' => json_encode(['created_at' => date('c', $ts), 'db_tables' => $tables, 'files' => ['total' => $fileCount]]),
    ]);

    return [
        'id' => $id, 'path' => $final, 'filename' => basename($final),
        'size' => $size, 'sha256' => $sha, 'verified' => $verified,
        'verify_note' => $verifyNote, 'created_at' => date('c', $ts),
    ];
}

/** Decrypt + parse + validate a backup file. Returns manifest array. */
function mv_backup_verify_file(string $path, string $passphrase): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'mvbak') . '.zip';
    try {
        mv_backup_decrypt_file($path, $tmp, $passphrase);
        $zip = mvzip_open($tmp);
        $raw = mvzip_extract($zip, 'manifest.json');
        if ($raw === false) {
            throw new RuntimeException('manifest.json missing from backup.');
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'metro-valet') {
            throw new RuntimeException('Not a Metro Valet backup (bad manifest).');
        }
        // Spot-check checksums (DB + up to 20 files).
        $checked = 0;
        foreach (($manifest['checksums'] ?? []) as $name => $expect) {
            $data = mvzip_extract($zip, $name);
            if ($data === false) {
                throw new RuntimeException('Backup is missing file: ' . $name);
            }
            if (hash('sha256', $data) !== $expect) {
                throw new RuntimeException('Checksum mismatch: ' . $name);
            }
            if (++$checked >= 20) {
                break;
            }
        }
        if (!isset($zip['files']['data/metrovalet.sqlite'])) {
            throw new RuntimeException('Backup has no database snapshot.');
        }
        return $manifest;
    } finally {
        @unlink($tmp);
    }
}

/** Keep only the newest $keep backups (safety backups are never auto-pruned). */
function mv_backup_prune(int $keep = -1): int
{
    if ($keep < 0) {
        $keep = max(1, (int) setting('backup_keep', (string) MV_BACKUP_KEEP));
    }
    $db = db();
    $rows = $db->query(
        "SELECT id, filename FROM backup_logs WHERE type IN ('weekly','manual') AND status = 'ok' ORDER BY id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $removed = 0;
    foreach (array_slice($rows, $keep) as $r) {
        $p = MV_BACKUP_DIR . '/' . $r['filename'];
        @unlink($p);
        $db->prepare('DELETE FROM backup_logs WHERE id = ?')->execute([(int) $r['id']]);
        $removed++;
    }
    // Cloud prune.
    try {
        mv_backup_cloud_prune($keep);
    } catch (Throwable $e) {
    }
    return $removed;
}

/* ================= History logging ================= */

function mv_backup_log(array $fields): int
{
    $db = db();
    $db->prepare(
        'INSERT INTO backup_logs (filename, size_bytes, sha256, type, status, verified, verify_note, emailed, cloud, manifest, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $fields['filename'], $fields['size_bytes'] ?? 0, $fields['sha256'] ?? '',
        $fields['type'], $fields['status'], $fields['verified'] ?? 0,
        $fields['verify_note'] ?? '', $fields['emailed'] ?? 0, $fields['cloud'] ?? 0,
        $fields['manifest'] ?? '', date('Y-m-d H:i:s'),
    ]);
    return (int) $db->lastInsertId();
}

function mv_backup_mark(int $id, array $fields): void
{
    $db = db();
    $sets = [];
    $vals = [];
    foreach (['status' => 1, 'emailed' => 1, 'cloud' => 1, 'verify_note' => 1] as $k => $_) {
        if (array_key_exists($k, $fields)) {
            $sets[] = "$k = ?";
            $vals[] = $fields[$k];
        }
    }
    if ($sets === []) {
        return;
    }
    $vals[] = $id;
    $db->prepare('UPDATE backup_logs SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}

/* ================= Restore ================= */

/** Preview a backup: decrypt+validate, return manifest (no changes made). */
function mv_backup_preview(string $path, string $passphrase): array
{
    return mv_backup_verify_file($path, $passphrase);
}

function mv_backup_restore(string $path, string $passphrase, string $mode = 'data'): array
{
    // $mode: 'data' = database + uploads only (app code untouched);
    //        'full' = everything including app files (disaster recovery).
    $appRoot = dirname(__DIR__);
    // 1. Validate.
    $manifest = mv_backup_verify_file($path, $passphrase);

    // 2. Safety backup of current state (never auto-pruned).
    $safety = mv_backup_create('safety', $passphrase);

    // 3. Extract to temp.
    $tmpZip = tempnam(sys_get_temp_dir(), 'mvrestore') . '.zip';
    mv_backup_decrypt_file($path, $tmpZip, $passphrase);
    $zip = mvzip_open($tmpZip);

    $restoredFiles = 0;
    try {
        $db = db();
        // 4a. Database: checkpoint, remove WAL artifacts, replace file.
        // Use atomic rename (new inode) so any lingering old handle
        // cannot lock the fresh file.
        try {
            $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (Throwable $e) {
        }
        $dbFile = $appRoot . '/data/metrovalet.sqlite';
        $newDb = mvzip_extract($zip, 'data/metrovalet.sqlite');
        if ($newDb === false) {
            throw new RuntimeException('Backup has no database snapshot.');
        }
        $tmpDb = $dbFile . '.restoretmp';
        if (file_put_contents($tmpDb, $newDb) === false) {
            throw new RuntimeException('Cannot write database file.');
        }
        // Drop old handles before the swap.
        $db = null;
        $GLOBALS['db'] = null;
        @unlink($dbFile . '-wal');
        @unlink($dbFile . '-shm');
        if (!rename($tmpDb, $dbFile)) {
            throw new RuntimeException('Cannot replace database file.');
        }
        $GLOBALS['db'] = db_init();

        // 4b. Uploads: wipe and re-extract.
        $upDir = $appRoot . '/uploads';
        if (is_dir($upDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($upDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
        } else {
            mkdir($upDir, 0755, true);
        }

        // 4c. Uploads (+ app files in full mode) from archive.
        foreach ($zip['files'] as $name => $_e) {
            if ($name === 'manifest.json' || $name === 'data/metrovalet.sqlite') {
                continue;
            }
            // Never overwrite the backups dir or this restore's safety net.
            if ($name === 'data/backups' || str_starts_with($name, 'data/backups/')) {
                continue;
            }
            // Data-only mode: restore uploads/ only, leave app code alone.
            if ($mode !== 'full' && !str_starts_with($name, 'uploads/')) {
                continue;
            }
            $dest = $appRoot . '/' . $name;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            $data = mvzip_extract($zip, $name);
            if ($data !== false) {
                file_put_contents($dest, $data);
                $restoredFiles++;
            }
        }
    } finally {
        @unlink($tmpZip);
    }

    // 5. Verify with the fresh shared handle.
    $verifyOk = false;
    $verifyNote = '';
    try {
        $chk = db();
        $t = $chk->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
        $verifyOk = ((int) $t) > 0;
        $verifyNote = $verifyOk ? "restored DB opens, $t tables, $restoredFiles files" : 'restored DB has no tables';
    } catch (Throwable $e) {
        $verifyNote = 'verify failed: ' . $e->getMessage();
    }

    try {
        audit('backup.restored', 'backup', null, [
            'source' => basename($path),
            'backup_date' => $manifest['created_at'] ?? '',
            'safety_backup' => $safety['filename'],
            'files' => $restoredFiles,
            'verified' => $verifyOk,
        ]);
    } catch (Throwable $e) {
    }

    // Re-log the safety backup into the RESTORED db (the overwrite wiped
    // its log entry, but the file survives in data/backups/).
    try {
        $sp = MV_BACKUP_DIR . '/' . $safety['filename'];
        if (file_exists($sp)) {
            mv_backup_log([
                'filename' => $safety['filename'],
                'size_bytes' => filesize($sp),
                'sha256' => hash_file('sha256', $sp),
                'type' => 'safety',
                'status' => 'ok', 'verified' => 1,
                'verify_note' => 'pre-restore safety backup',
                'manifest' => '',
            ]);
        }
    } catch (Throwable $e) {
    }

    return [
        'verified' => $verifyOk, 'verify_note' => $verifyNote,
        'files' => $restoredFiles, 'manifest' => $manifest,
        'safety_backup' => $safety['filename'],
    ];
}

/* ================= S3 / R2 compatible cloud upload ================= */

function mv_backup_cloud_configured(): bool
{
    return trim((string) setting('backup_cloud_provider', 'none')) !== 'none'
        && trim((string) setting('backup_cloud_bucket', '')) !== ''
        && trim((string) setting('backup_cloud_key', '')) !== '';
}

function mv_s3_put(string $objectKey, string $filePath): void
{
    $provider = trim((string) setting('backup_cloud_provider', 'none'));
    $endpoint = rtrim(trim((string) setting('backup_cloud_endpoint', '')), '/');
    $region = trim((string) setting('backup_cloud_region', 'auto'));
    $bucket = trim((string) setting('backup_cloud_bucket', ''));
    $access = trim((string) setting('backup_cloud_key', ''));
    $secret = trim((string) setting('backup_cloud_secret', ''));
    if ($provider === 'none' || $bucket === '' || $access === '' || $secret === '') {
        throw new RuntimeException('Cloud storage not configured.');
    }
    if ($endpoint === '') {
        if ($provider === 'r2') {
            throw new RuntimeException('R2 needs the account endpoint URL.');
        }
        $endpoint = 'https://s3.' . ($region !== '' && $region !== 'auto' ? $region : 'us-east-1') . '.amazonaws.com';
    }
    if ($region === '' || $region === 'auto') {
        $region = 'auto';
    }
    $host = parse_url($endpoint, PHP_URL_HOST);
    // Path-style: endpoint/bucket/key (works for R2 and most S3-compatible).
    $url = $endpoint . '/' . $bucket . '/' . $objectKey;
    $body = file_get_contents($filePath);
    if ($body === false) {
        throw new RuntimeException('Cannot read backup file.');
    }
    $sha = hash('sha256', $body);
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $headers = [
        'host' => $host,
        'x-amz-content-sha256' => $sha,
        'x-amz-date' => $amzDate,
    ];
    ksort($headers);
    $signedHeaders = implode(';', array_keys($headers));
    $canonicalHeaders = '';
    foreach ($headers as $k => $v) {
        $canonicalHeaders .= $k . ':' . trim($v) . "\n";
    }
    $canonical = "PUT\n" . '/' . $bucket . '/' . $objectKey . "\n\n"
        . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $sha;
    $scope = $dateStamp . '/' . $region . '/s3/aws4_request';
    $sts = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonical);
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSign = hash_hmac('sha256', 'aws4_request', $kService, true);
    $sig = hash_hmac('sha256', $sts, $kSign);
    $auth = 'AWS4-HMAC-SHA256 Credential=' . $access . '/' . $scope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $sig;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $auth,
            'x-amz-date: ' . $amzDate,
            'x-amz-content-sha256: ' . $sha,
            'Content-Type: application/octet-stream',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') {
        throw new RuntimeException('Cloud upload cURL error: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Cloud upload failed (HTTP ' . $code . '): ' . substr((string) $resp, 0, 200));
    }
}

function mv_backup_cloud_upload(string $filePath, string $filename): string
{
    $prefix = trim((string) setting('backup_cloud_prefix', 'metrovalet/'), '/');
    $objectKey = ($prefix !== '' ? $prefix . '/' : '') . $filename;
    // S3 object keys: avoid spaces.
    $objectKey = str_replace(' ', '_', $objectKey);
    mv_s3_put($objectKey, $filePath);
    return $objectKey;
}

function mv_backup_cloud_prune(int $keep): void
{
    // Best-effort: cloud pruning via listing is provider-specific; the
    // local 12-copy retention is the enforced guarantee. Cloud copies
    // accumulate unless the bucket lifecycle rule prunes them — the
    // admin UI recommends setting a bucket lifecycle rule.
}

/* ================= Email delivery ================= */

function mv_backup_admin_email(): string
{
    $e = trim((string) setting('backup_email', ''));
    if ($e !== '') {
        return $e;
    }
    // Fall back to first super admin's email.
    try {
        $db = db();
        $em = $db->query(
            "SELECT u.email FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.name = 'super_admin' AND u.active = 1 ORDER BY u.id LIMIT 1"
        )->fetchColumn();
        if ($em) {
            return (string) $em;
        }
    } catch (Throwable $ex) {
    }
    return trim((string) setting('mail_from', ''));
}

/** Create an expiring, single-purpose download token for a backup. */
function mv_backup_make_token(int $backupId, int $ttlHours = 72): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO backup_tokens (token_hash, backup_id, expires_at, used) VALUES (?,?,?,0)'
    )->execute([hash('sha256', $token), $backupId, date('Y-m-d H:i:s', time() + $ttlHours * 3600)]);
    return $token;
}

function mv_backup_send_email(array $meta): array
{
    $to = mv_backup_admin_email();
    if ($to === '') {
        return ['sent' => false, 'note' => 'no admin email configured'];
    }
    $biz = (string) setting('business_name', 'Metro Valet Parking');
    $sizeMb = round($meta['size'] / 1048576, 2);
    $maxAttach = max(1, (int) setting('backup_attach_max_mb', 12)) * 1048576;

    $html = '<p>Weekly backup completed and verified.</p>'
        . '<ul><li><strong>Date:</strong> ' . htmlspecialchars($meta['created_at']) . '</li>'
        . '<li><strong>File:</strong> ' . htmlspecialchars($meta['filename']) . '</li>'
        . '<li><strong>Size:</strong> ' . $sizeMb . ' MB</li>'
        . '<li><strong>Verification:</strong> ' . htmlspecialchars($meta['verify_note']) . '</li></ul>'
        . '<p>The backup is encrypted (AES-256). Keep your backup passphrase safe — it is required to restore.</p>';

    $attachments = [];
    $note = '';
    if ($meta['size'] <= $maxAttach) {
        $content = file_get_contents($meta['path']);
        if ($content !== false) {
            $attachments[] = ['filename' => $meta['filename'], 'type' => 'application/octet-stream', 'content' => $content];
            $html .= '<p>The encrypted backup is attached to this email.</p>';
            $note = 'attached';
        }
    } else {
        $token = mv_backup_make_token((int) $meta['id'], 72);
        $url = site_url('admin/backup_download.php?token=' . urlencode($token));
        $html .= '<p>The backup (' . $sizeMb . ' MB) is too large to attach. Download it within 72 hours:</p>'
            . '<p><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a></p>';
        $note = 'link (72h)';
    }

    try {
        $sent = send_mail_raw($to, '[' . $biz . '] Weekly backup ' . date('Y-m-d', strtotime($meta['created_at'])), $html, $attachments);
    } catch (Throwable $e) {
        $sent = false;
    }
    return ['sent' => (bool) $sent, 'note' => $sent ? $note : 'send failed'];
}

function mv_backup_send_alert(string $subject, string $message): void
{
    $to = mv_backup_admin_email();
    if ($to === '') {
        return;
    }
    $biz = (string) setting('business_name', 'Metro Valet Parking');
    try {
        send_mail_raw($to, '[' . $biz . '] BACKUP FAILED — ' . $subject,
            '<p><strong>Automatic backup failed and needs attention.</strong></p><p>' . nl2br(htmlspecialchars($message)) . '</p>');
    } catch (Throwable $e) {
    }
}
