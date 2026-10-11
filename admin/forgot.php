<?php
// admin/forgot.php — request a password reset link (staff only).
require __DIR__ . '/../lib/bootstrap.php';

if (current_user()) {
    redirect(site_url('admin/index.php'));
}

$business = (string) setting('business_name', 'Metro Valet Parking');
$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rl_key = 'pw_forgot_' . preg_replace('/[^a-zA-Z0-9_.\-]/', '_', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if (!rate_ok($rl_key, 5, 3600)) {
        $error = 'Too many requests. Please wait a while and try again.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            // Always show the same message — never reveal whether the email exists.
            $st = db()->prepare('SELECT id, name FROM users WHERE email = ? AND active = 1 LIMIT 1');
            $st->execute([$email]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $token = secure_token(32);
                $hash = hash('sha256', $token);
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
                db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')
                    ->execute([(int) $u['id']]);
                db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, used) VALUES (?,?,?,0)')
                    ->execute([(int) $u['id'], $hash, $expires]);
                $reset_url = site_url('admin/reset.php?token=' . urlencode($token));
                try {
                    send_template('password_reset', $email, [
                        'name' => $u['name'],
                        'reset_url' => $reset_url,
                        'business_name' => $business,
                    ], 'pwreset:' . $hash);
                } catch (Throwable $e) {
                    // Email failure shouldn't reveal anything either.
                }
                audit('user.password_reset_requested', 'user', (int) $u['id'], ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            }
            rate_hit($rl_key);
            $sent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot password — <?= e($business) ?></title>
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
    <h1>Forgot password</h1>
    <p class="muted">Enter your staff email and we'll send you a reset link.</p>

    <?php if ($error !== ''): ?>
        <div class="flash flash-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($sent): ?>
        <div class="flash flash-success" role="alert">If an account exists for that email, a reset link is on its way. It expires in 1 hour.</div>
        <p><a class="btn btn-ghost" href="<?= e(site_url('admin/login.php')) ?>">Back to login</a></p>
    <?php else: ?>
        <form method="post" action="<?= e(site_url('admin/forgot.php')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="username" autofocus>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary">Send reset link</button>
                <a class="btn btn-ghost" href="<?= e(site_url('admin/login.php')) ?>">Back to login</a>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
