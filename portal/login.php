<?php
require __DIR__ . '/../lib/bootstrap.php';

/* Already signed in? Go to the dashboard. */
try {
    $me = current_customer();
} catch (Throwable $e) {
    $me = null;
}
if (!empty($me)) {
    redirect(site_url('portal/index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); /* exits 403 on mismatch */
    if (false) {
        $error = 'Your session expired. Please try again.';
    } else {
        $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rlkey = 'portal_login_' . $ip;
        if (!rate_ok($rlkey, 5, 600)) {
            $error = 'Too many login attempts. Please wait 10 minutes and try again.';
        } else {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $pass  = (string)($_POST['password'] ?? '');
            $pdo   = db();
            $done  = false;

            /* 1) customers table (individuals + company billing contacts with type='company') */
            $st = $pdo->prepare('SELECT * FROM customers WHERE LOWER(email) = ? LIMIT 1');
            $st->execute(array($email));
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if ($c && !empty($c['password_hash']) && password_verify($pass, (string)$c['password_hash'])) {
                $c['kind'] = 'customer';
                if (($c['type'] ?? '') === 'company' && !empty($c['company_id'])) {
                    customer_login($c, 'company', (int)$c['company_id']);
                } else {
                    customer_login($c, 'customer', (int)$c['id']);
                }
                audit('portal_login', 'customers', (int)$c['id'], array());
                $done = true;
                redirect(site_url('portal/index.php'));
            }

            /* 2) company_contacts table (corporate billing contacts) */
            if (!$done) {
                $st = $pdo->prepare('SELECT * FROM company_contacts WHERE LOWER(email) = ? LIMIT 1');
                $st->execute(array($email));
                $cc = $st->fetch(PDO::FETCH_ASSOC);
                if ($cc && !empty($cc['password_hash']) && password_verify($pass, (string)$cc['password_hash'])) {
                    $cc['kind'] = 'company_contact';
                    customer_login($cc, 'company', (int)$cc['company_id']);
                    audit('portal_login', 'company_contacts', (int)$cc['id'], array());
                    $done = true;
                    redirect(site_url('portal/index.php'));
                }
            }

            if (!$done) {
                rate_hit($rlkey);
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Customer Portal Sign In — Metro Valet Parking</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(rtrim((string)base_url(), '/')) ?>/assets/css/site.css?v=20251011">
<style>
body.login-body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0A1A2F;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px;box-sizing:border-box;}
.login-card{background:#fff;border-radius:18px;padding:36px 34px;width:100%;max-width:420px;box-shadow:0 24px 60px rgba(0,0,0,.35);}
.login-brand{display:flex;align-items:center;gap:12px;margin-bottom:8px;}
.login-mark{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:#C9A227;color:#0A1A2F;font-family:Fraunces,serif;font-weight:600;font-size:20px;}
.login-brand h1{font-family:Fraunces,serif;font-size:22px;margin:0;color:#0A1A2F;}
.login-brand p{margin:0;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#5B6774;}
.login-sub{color:#5B6774;font-size:14px;margin:0 0 22px;}
.login-err{background:#FBE7E4;border:1px solid #F3C1BB;color:#7A1F14;border-radius:10px;padding:10px 14px;font-size:14px;margin:0 0 16px;}
.login-field{margin:0 0 14px;}
.login-field label{display:block;font-size:13px;font-weight:600;margin:0 0 6px;color:#1B2430;}
.login-field input{width:100%;box-sizing:border-box;border:1px solid #E2E8F0;border-radius:10px;padding:11px 13px;font:400 15px Inter,sans-serif;}
.login-field input:focus{outline:2px solid #E3B94E;border-color:#C9A227;}
.login-btn{width:100%;border:none;cursor:pointer;background:#C9A227;color:#0A1A2F;font:700 15px Inter,sans-serif;border-radius:10px;padding:12px;margin-top:6px;}
.login-btn:hover{background:#E3B94E;}
.login-back{display:block;text-align:center;margin-top:18px;font-size:14px;color:#5B6774;text-decoration:none;}
.login-back:hover{color:#0A1A2F;text-decoration:underline;}
</style>
</head>
<body class="login-body">
<div class="login-card">
  <div class="login-brand">
    <span class="login-mark">MV</span>
    <div>
      <h1>Metro Valet</h1>
      <p>Customer Portal</p>
    </div>
  </div>
  <p class="login-sub">Sign in to view your subscriptions, invoices, and vehicles.</p>
  <?php if ($error !== ''): ?>
    <p class="login-err" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <form method="post" action="<?= e(site_url('portal/login.php')) ?>" autocomplete="off">
    <?php $cf = csrf_field(); if (is_string($cf)) { echo $cf; } ?>
    <div class="login-field">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" required maxlength="190"
             value="<?= e((string)($_POST['email'] ?? '')) ?>" autocomplete="username">
    </div>
    <div class="login-field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button class="login-btn" type="submit">Sign In</button>
  </form>
  <p style="text-align:center;margin-top:1rem"><a href="forgot.php" style="font-size:.9rem">Forgot your password?</a></p>
  <a class="login-back" href="<?= e(site_url('index.php')) ?>">&larr; Back to website</a>
</div>
</body>
</html>
