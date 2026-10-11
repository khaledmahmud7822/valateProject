<?php
// admin/_guard.php — login + permission gate for every admin page.
// Pages MUST include ../lib/bootstrap.php first, then set $page_title,
// $nav_section, and $required_perm (or $required_any) BEFORE including this file.
require_login();

$__denied = false;
if (isset($required_perm) && $required_perm !== null && $required_perm !== '') {
    if (!can($required_perm)) {
        $__denied = true;
    }
}
if (isset($required_any) && is_array($required_any) && $required_any !== []) {
    $__any_ok = false;
    foreach ($required_any as $__p) {
        if (can($__p)) {
            $__any_ok = true;
            break;
        }
    }
    if (!$__any_ok) {
        $__denied = true;
    }
}
unset($__p, $__any_ok);

if ($__denied) {
    http_response_code(403);
    $u = current_user();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>403 — Access denied</title>
<link rel="stylesheet" href="<?= e(site_url('assets/css/admin.css')) ?>">
</head>
<body class="admin-standalone">
<div class="standalone-card">
<h1>Access denied</h1>
<p>Sorry<?= $u ? ' ' . e($u['name']) : '' ?>, your account does not have permission to view this page.</p>
<p class="muted">If you believe this is a mistake, ask an administrator to review your role.</p>
<p><a class="btn btn-primary" href="<?= e(site_url('admin/index.php')) ?>">Back to dashboard</a></p>
</div>
</body>
</html>
    <?php
    exit;
}
unset($__denied);
