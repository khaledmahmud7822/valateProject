<?php
// admin/login.php — staff sign-in with optional TOTP second step.
require __DIR__ . '/../lib/bootstrap.php';

if (current_user()) {
    redirect(site_url('admin/index.php'));
}

if (isset($_GET['cancel_mfa'])) {
    unset($_SESSION['mfa_pending']);
    redirect(site_url('admin/login.php'));
}

$error = '';
$step = 'credentials'; // or 'totp'
$pending_user = null;

// TOTP second step already in progress?
if (!empty($_SESSION['mfa_pending'])) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
    $st->execute([$_SESSION['mfa_pending']]);
    $pending_user = $st->fetch(PDO::FETCH_ASSOC);
    if ($pending_user) {
        $step = 'totp';
    } else {
        unset($_SESSION['mfa_pending']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); // dies with 403 on mismatch
    $rl_key = 'admin_login_' . preg_replace('/[^a-zA-Z0-9_.\-]/', '_', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if (!rate_ok($rl_key, 5, 600)) {
        $error = 'Too many login attempts. Please wait a few minutes and try again.';
    } else {
        if ($step === 'totp' && $pending_user) {
            // ---- Second step: TOTP code ----
            $code = preg_replace('/\D/', '', (string) ($_POST['totp_code'] ?? ''));
            $ok = false;
            try {
                $ok = totp_verify($pending_user['mfa_secret'], $code);
            } catch (Exception $ex) {
                $ok = false;
            }
            if ($ok) {
                $user = $pending_user;
                unset($_SESSION['mfa_pending']);
                login_user($user);
                rate_clear($rl_key);
                audit('user.login', 'user', $user['id'], ['mfa' => true, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
                redirect(site_url('admin/index.php'));
            } else {
                rate_hit($rl_key);
                $error = 'That code was not accepted. Try again.';
            }
        } else {
            // ---- First step: email + password ----
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $st->execute([$email]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            $valid = $user && (int) $user['active'] === 1 && password_verify($password, (string) $user['password_hash']);
            if (!$valid) {
                rate_hit($rl_key);
                // Generic message: do not reveal whether the email exists.
                $error = 'Invalid email or password.';
            } elseif (!empty($user['mfa_secret'])) {
                $_SESSION['mfa_pending'] = $user['id'];
                $pending_user = $user;
                $step = 'totp';
            } else {
                login_user($user);
                rate_clear($rl_key);
                audit('user.login', 'user', $user['id'], ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
                redirect(site_url('admin/index.php'));
            }
        }
    }
}

$business = setting('business_name', 'Metro Valet Parking');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin login — <?= e($business) ?></title>
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
    <h1>Admin login</h1>
    <p class="muted">Staff access only.</p>

    <?php if ($error !== ''): ?>
        <div class="flash flash-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'totp'): ?>
        <p>Enter the 6-digit code from your authenticator app for <strong><?= e($pending_user['email']) ?></strong>.</p>
        <form method="post" action="<?= e(site_url('admin/login.php')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="totp_code">Authenticator code</label>
                <input type="text" id="totp_code" name="totp_code" inputmode="numeric"
                       autocomplete="one-time-code" maxlength="8" required autofocus>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary">Verify &amp; sign in</button>
                <a class="btn btn-ghost" href="<?= e(site_url('admin/login.php?cancel_mfa=1')) ?>">Start over</a>
            </div>
        </form>
    <?php else: ?>
        <form method="post" action="<?= e(site_url('admin/login.php')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="username" autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary">Sign in</button>
            </div>
            <p class="muted small" style="margin-top:10px"><a href="<?= e(site_url('admin/forgot.php')) ?>">Forgot password?</a></p>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
