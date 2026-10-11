<?php
// portal/forgot.php — customer self-service password reset.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Forgot Password';
$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $db = db();
        // Check both customers and company contacts (don't reveal which).
        $found = false; $kind = 'customer'; $name = '';
        $st = $db->prepare('SELECT name FROM customers WHERE LOWER(email) = ? LIMIT 1');
        $st->execute([$email]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $found = true; $name = $r['name']; }
        if (!$found) {
            $st = $db->prepare('SELECT name FROM company_contacts WHERE LOWER(email) = ? LIMIT 1');
            $st->execute([$email]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $found = true; $kind = 'contact'; $name = $r['name']; }
        }
        if ($found) {
            $token = bin2hex(random_bytes(32));
            $db->prepare('INSERT INTO customer_resets (email, token_hash, kind, expires_at, created_at) VALUES (?,?,?,?,?)')
               ->execute([$email, hash('sha256', $token), $kind, date('Y-m-d H:i:s', time() + 3600), date('Y-m-d H:i:s')]);
            $link = site_url('portal/reset.php?token=' . $token);
            try {
                send_mail_raw($email, 'Reset your Metro Valet Parking password',
                    '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                    . '<p>We received a request to reset your customer portal password. Click the link below to set a new one (valid for 1 hour):</p>'
                    . '<p><a href="' . htmlspecialchars($link) . '">Set a new password</a></p>'
                    . '<p>If you didn\'t request this, just ignore this email.</p>'
                    . '<p>— Metro Valet Parking</p>');
            } catch (Throwable $e) {}
        }
        // Always show success to avoid email enumeration.
        $sent = true;
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
<h1 style="font-size:1.6rem">Forgot Password</h1>
<?php if ($sent): ?>
    <div class="alert alert-success">If an account exists for that email, a reset link is on its way. Check your inbox (and spam folder).</div>
    <p><a class="btn btn-ghost" href="login.php">Back to login</a></p>
<?php else: ?>
    <?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <p class="muted">Enter your account email and we'll send you a link to set a new password.</p>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" required autocomplete="email"></div>
        <button type="submit" class="btn btn-gold" style="width:100%">Send reset link</button>
    </form>
    <p style="margin-top:1rem"><a class="btn btn-ghost" href="login.php">Back to login</a></p>
<?php endif; ?>
</div>
</div></section></main>
</body>
</html>
