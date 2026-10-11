<?php
// admin/resume_download.php — private, permission-gated résumé download.
require __DIR__ . '/../lib/bootstrap.php';
$required_perm = 'applications.manage';
require __DIR__ . '/_guard.php';

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT resume_path, full_name FROM applications WHERE id = ?');
$st->execute([$id]);
$app = $st->fetch(PDO::FETCH_ASSOC);
$path = $app ? __DIR__ . '/../' . $app['resume_path'] : '';
if (!$app || $app['resume_path'] === '' || !is_file($path)) {
    http_response_code(404);
    echo 'Résumé not found.';
    exit;
}
audit('application.resume_download', 'application', $id, ['name' => $app['full_name']]);
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="resume-' . preg_replace('/[^a-z0-9]+/i', '-', $app['full_name']) . '.' . $ext . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
