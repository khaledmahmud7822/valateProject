<?php
// portal/reset.php — set new password via emailed token.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Set New Password';
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$valid = false; $email = ''; $kind = 'customer';
$done = false; $error = '';

if ($token !== '') {
    $db = db();
    // Compare against PHP time (not SQLite datetime('now') which is UTC) to avoid timezone mismatch with expires_at.
    $now = date('Y-m-d H:i:s');
    $st = $db->prepare("SELECT * FROM customer_resets WHERE token_hash = ? AND used = 0 AND expires_at > ? LIMIT 1");
    $st->execute([hash('sha256', $token), $now]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $valid = true; $email = $r['email']; $kind = $r['kind'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    verify_csrf();
    $pw = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');
    if (strlen($pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        $db = db();
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        if ($kind === 'contact') {
            $db->prepare('UPDATE company_contacts SET password_hash = ? WHERE LOWER(email) = ?')->execute([$hash, $email]);
        } else {
            $db->prepare('UPDATE customers SET password_hash = ? WHERE LOWER(email) = ?')->execute([$hash, $email]);
        }
        $db->prepare('UPDATE customer_resets SET used = 1 WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        audit('portal_password_reset', 'customer', 0, ['email' => $email]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?> | Metro Valet Parking</title>
<link rel="stylesheet" href="../assets/css/site.css?v=20251011">
</head>
<body>
<main><section class="section"><div class="container" style="max-width:28rem">
<div class="card">
<h1 style="font-size:1.6rem">Set New Password</h1>
<?php if ($done): ?>
    <div class="alert alert-success">Your password has been set. You can now log in.</div>
    <p><a class="btn btn-gold" href="login.php">Log in</a></p>
<?php elseif (!$valid): ?>
    <div class="alert alert-error">This link is invalid or has expired.</div>
    <p><a class="btn btn-ghost" href="forgot.php">Request a new link</a></p>
<?php else: ?>
    <?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <p class="muted">Setting password for <strong><?= e($email) ?></strong></p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="pw">New password (min 8 characters)</label><input type="password" id="pw" name="password" required minlength="8" autocomplete="new-password"></div>
        <div class="field"><label for="pw2">Confirm password</label><input type="password" id="pw2" name="password2" required autocomplete="new-password"></div>
        <button type="submit" class="btn btn-gold" style="width:100%">Set password</button>
    </form>
<?php endif; ?>
</div>
</div></section></main>
</body>
</html>
