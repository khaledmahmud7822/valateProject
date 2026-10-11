<?php
// admin/reset.php — set a new password from a reset token (staff only).
require __DIR__ . '/../lib/bootstrap.php';

if (current_user()) {
    redirect(site_url('admin/index.php'));
}

$business = (string) setting('business_name', 'Metro Valet Parking');
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$hash = $token !== '' ? hash('sha256', $token) : '';

$reset = null;
if ($hash !== '') {
    $st = db()->prepare(
        'SELECT pr.*, u.email, u.name FROM password_resets pr JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ? AND pr.used = 0 AND pr.expires_at > CURRENT_TIMESTAMP AND u.active = 1 LIMIT 1'
    );
    $st->execute([$hash]);
    $reset = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    verify_csrf();
    $pw1 = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password_confirm'] ?? '');
    if (strlen($pw1) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($pw1, PASSWORD_DEFAULT), (int) $reset['user_id']]);
        db()->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')->execute([(int) $reset['id']]);
        audit('user.password_reset_completed', 'user', (int) $reset['user_id'], ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset password — <?= e($business) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(site_url('assets/css/admin.css')) ?>">
</head>
<body class="admin-standalone">
<div class="standalone-card">
    <div class="standalone-brand">
        <span class="brand-mark">MV</span>
        <span class="brand-text"><?= e($business) ?></span>
    </div>
    <h1>Reset password</h1>

    <?php if ($done): ?>
        <div class="flash flash-success" role="alert">Your password has been reset. You can sign in now.</div>
        <p><a class="btn btn-primary" href="<?= e(site_url('admin/login.php')) ?>">Sign in</a></p>
    <?php elseif (!$reset): ?>
        <div class="flash flash-error" role="alert">This reset link is invalid or has expired. Request a new one.</div>
        <p><a class="btn btn-ghost" href="<?= e(site_url('admin/forgot.php')) ?>">Request new link</a></p>
    <?php else: ?>
        <p class="muted">Setting a new password for <strong><?= e($reset['email']) ?></strong>.</p>
        <?php if ($error !== ''): ?>
            <div class="flash flash-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(site_url('admin/reset.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="field">
                <label for="password">New password</label>
                <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
                <div class="hint">At least 8 characters.</div>
            </div>
            <div class="field">
                <label for="password_confirm">Confirm new password</label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary">Reset password</button>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
