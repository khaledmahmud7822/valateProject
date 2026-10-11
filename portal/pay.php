<?php
require __DIR__ . '/../lib/bootstrap.php';

/* ------------------------------------------------------------------
 * portal/pay.php — pay an invoice.
 *
 * Access: ?token=<invoice.payment_token> (secure emailed link, no login
 * required) OR ?id=<invoice id> (must be logged in and own the invoice).
 * ------------------------------------------------------------------ */

function pay_fail($title, $msg) {
    http_response_code(404);
    $t = (string)$title;
    $m = (string)$msg;
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . ' — Metro Valet Parking</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#F4F6F9;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;box-sizing:border-box;}'
        . '.c{background:#fff;border-radius:14px;padding:32px;max-width:460px;text-align:center;}'
        . 'h1{font-size:22px;color:#0A1A2F;margin:0 0 10px;}p{color:#5B6774;}</style></head><body>'
        . '<div class="c"><h1>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p>' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
    exit;
}

function stripe_api_post($secret, $path, array $fields) {
    $ch = curl_init('https://api.stripe.com/v1' . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $secret));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array($code, $body, $err);
}

$pdo      = db();
$token    = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$inv      = null;
$loggedIn = false;
$scope    = 'customer';
$scope_id = 0;
$login_id = 0;
$auth     = null;

if ($token !== '') {
    $st = $pdo->prepare('SELECT * FROM invoices WHERE payment_token = ? LIMIT 1');
    $st->execute(array($token));
    $inv = $st->fetch(PDO::FETCH_ASSOC);
} elseif (isset($_GET['id'])) {
    $auth     = require_customer();
    $loggedIn = true;
    $scope    = $_SESSION['customer_scope'] ?? 'customer';
    $scope_id = (int)($_SESSION['customer_scope_id'] ?? 0);
    $login_id = (int)($_SESSION['customer_id'] ?? 0);
    $id       = (int)$_GET['id'];
    $st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
    $st->execute(array($id));
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if ($inv) {
        if ($scope === 'company' && $scope_id > 0) {
            $mine = ((int)$inv['company_id'] === $scope_id);
        } else {
            $mine = ((int)$inv['customer_id'] === $login_id);
        }
        if (!$mine) {
            pay_fail('Forbidden', 'This invoice does not belong to your account.');
        }
    }
    $token = (string)($inv['payment_token'] ?? '');
}

if (!$inv) {
    pay_fail('Invoice not found', 'The payment link is invalid or has expired. Please check your invoice email or sign in to the customer portal.');
}

/* Refresh totals from recorded payments (source of truth). */
recalc_invoice((int)$inv['id']);
$st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
$st->execute(array((int)$inv['id']));
$inv = $st->fetch(PDO::FETCH_ASSOC);

$due = (int)$inv['amount_cents'] - (int)$inv['amount_paid_cents'];
if ($due < 0) { $due = 0; }

$st = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC');
$st->execute(array((int)$inv['id']));
$items = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY paid_at DESC, id DESC');
$st->execute(array((int)$inv['id']));
$payments = $st->fetchAll(PDO::FETCH_ASSOC);

/* Payer email (for Stripe receipt + our records). */
$payer_email = '';
if (!empty($inv['customer_id'])) {
    $st = $pdo->prepare('SELECT email FROM customers WHERE id = ? LIMIT 1');
    $st->execute(array((int)$inv['customer_id']));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) { $payer_email = (string)($r['email'] ?? ''); }
} elseif (!empty($inv['company_id'])) {
    $st = $pdo->prepare('SELECT billing_email, email FROM companies WHERE id = ? LIMIT 1');
    $st->execute(array((int)$inv['company_id']));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) { $payer_email = (string)($r['billing_email'] ?? $r['email'] ?? ''); }
}

$stripe_key    = (string)setting('stripe_secret_key', '');
$stripe_on     = ($stripe_key !== '');
$manual_text   = (string)setting('manual_payment_methods', 'We accept cash, check, or ACH bank transfer. Contact us for payment instructions.');
$pay_error     = '';
$ach_recorded  = false;

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $due > 0) {
    $action = (string)($_POST['action'] ?? '');
    verify_csrf(); /* exits 403 on mismatch */
    if (false) {
        $pay_error = 'Your session expired. Please reload the page and try again.';
    } elseif ($action === 'stripe' && $stripe_on) {
        $success_url = site_url('pay/stripe_return.php')
            . '?session_id={CHECKOUT_SESSION_ID}&token=' . urlencode($token);
        $cancel_url  = site_url('portal/pay.php') . '?token=' . urlencode($token);
        // Note: `payment_method_types` is intentionally omitted — Stripe deprecated it
        // for Checkout Sessions (dynamic payment methods are managed in the Dashboard).
        $fields = array(
            'line_items' => array(array(
                'price_data' => array(
                    'currency'     => 'usd',
                    'unit_amount'  => $due,
                    'product_data' => array(
                        'name' => 'Metro Valet Parking — Invoice ' . $inv['number'],
                    ),
                ),
                'quantity' => 1,
            )),
            'mode'         => 'payment',
            'success_url'  => $success_url,
            'cancel_url'   => $cancel_url,
            'metadata'     => array('invoice_id' => (string)$inv['id']),
        );
        if ($payer_email !== '') {
            $fields['customer_email'] = $payer_email;
        }
        list($code, $body, $cerr) = stripe_api_post($stripe_key, '/checkout/sessions', $fields);
        $data = is_string($body) ? json_decode($body, true) : null;
        if ($cerr !== '' || $code < 200 || $code >= 300 || empty($data['url'])) {
            // Log the exact failure where the admin can see it (Admin → Notifications).
            $detail = $cerr !== ''
                ? 'cURL error: ' . $cerr
                : 'HTTP ' . $code . ' — ' . (is_array($data) && isset($data['error']['message'])
                    ? (string) $data['error']['message']
                    : substr((string) $body, 0, 300));
            try {
                $pdo->prepare(
                    "INSERT INTO notification_logs (template_key, recipient, subject, body_hash, status, dedupe_key, error, sent_at)
                     VALUES ('stripe_error', 'system', ?, ?, 'failed', ?, ?, ?)"
                )->execute([
                    'Stripe checkout failed for invoice ' . $inv['number'],
                    sha1($detail),
                    'stripe_error:' . $inv['id'] . ':' . time(),
                    $detail,
                    date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $e) { /* never break the pay page on logging */ }
            $pay_error = 'We could not start the card checkout. Please try again, or use one of the manual payment methods below.';
        } else {
            // Remember the Stripe session so admin "Complete payment" can verify it later.
            try {
                $pdo->prepare('UPDATE invoices SET stripe_session_id = ? WHERE id = ?')
                    ->execute([(string) $data['id'], (int) $inv['id']]);
            } catch (Throwable $e) { /* non-fatal */ }
            header('Location: ' . $data['url'], true, 303);
            exit;
        }
    } elseif ($action === 'ach') {
        /* Customer-initiated bank transfer: recorded as PENDING only.
         * The invoice is NEVER marked paid from this action. */
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            "INSERT INTO payments (invoice_id, amount_cents, method, reference, paid_at,
                                   recorded_by, recorded_by_customer, notes, created_at)
             VALUES (?, ?, 'ach', NULL, ?, NULL, 1, 'Customer-initiated; pending verification', ?)"
        );
        $ins->execute(array((int)$inv['id'], $due, $now, $now));
        recalc_invoice((int)$inv['id']);
        if ($loggedIn) {
            audit('payment_initiated_ach', 'invoices', (int)$inv['id'], array('amount_cents' => $due));
        }
        $ach_recorded = true;
        /* Refresh local state. */
        $st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
        $st->execute(array((int)$inv['id']));
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        $due = (int)$inv['amount_cents'] - (int)$inv['amount_paid_cents'];
        if ($due < 0) { $due = 0; }
        $st = $pdo->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY paid_at DESC, id DESC');
        $st->execute(array((int)$inv['id']));
        $payments = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

$is_paid = ($due <= 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pay <?= e((string)$inv['number']) ?> — Metro Valet Parking</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--navy:#0A1A2F;--gold:#C9A227;--gold-2:#E3B94E;--ink:#1B2430;--muted:#5B6774;--line:#E2E8F0;--mist:#F4F6F9;--green:#1E7E46;--amber:#B7791F;--red:#C0392B;}
body{margin:0;background:var(--mist);color:var(--ink);font-family:Inter,system-ui,sans-serif;font-size:15px;line-height:1.55;}
.pay-head{background:var(--navy);color:#fff;padding:18px 20px;}
.pay-head-inner{max-width:720px;margin:0 auto;display:flex;align-items:center;gap:12px;}
.pay-mark{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;background:var(--gold);color:var(--navy);font-family:Fraunces,serif;font-weight:600;font-size:18px;}
.pay-head h1{font-family:Fraunces,serif;font-size:20px;margin:0;}
.pay-head p{margin:0;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:var(--gold-2);}
.pay-wrap{max-width:720px;margin:24px auto 48px;padding:0 20px;}
.pay-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;margin:0 0 18px;}
.pay-card h2{font-family:Fraunces,serif;font-size:20px;color:var(--navy);margin:0 0 12px;}
.pay-due{font-family:Fraunces,serif;font-size:34px;color:var(--navy);margin:6px 0 2px;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th{text-align:left;padding:8px 10px;background:#F8FAFC;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--line);}
td{padding:8px 10px;border-bottom:1px solid var(--line);}
.kv{display:grid;grid-template-columns:160px 1fr;gap:6px 12px;font-size:14px;margin:0;}
.kv dt{color:var(--muted);}.kv dd{margin:0;font-weight:500;}
.btn{display:inline-block;border:none;cursor:pointer;font:600 15px Inter,sans-serif;border-radius:10px;padding:12px 22px;text-decoration:none;}
.btn-gold{background:var(--gold);color:var(--navy);width:100%;}
.btn-gold:hover{background:var(--gold-2);}
.btn-outline{background:#fff;color:var(--navy);border:1px solid var(--line);width:100%;margin-top:10px;}
.alert{border-radius:10px;padding:12px 16px;font-size:14px;margin:0 0 16px;border:1px solid;}
.a-err{background:#FBE7E4;border-color:#F3C1BB;color:#7A1F14;}
.a-ok{background:#E7F4EC;border-color:#BFE3CD;color:#14532D;}
.a-info{background:#E8EFFA;border-color:#C3D4EE;color:#1E3A66;}
.badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;border-radius:999px;padding:3px 10px;}
.badge::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor;}
.b-green{background:#E7F4EC;color:var(--green);}
.b-amber{background:#FBF1DC;color:var(--amber);}
.muted{color:var(--muted);font-size:13px;}
.hr{border:none;border-top:1px solid var(--line);margin:18px 0;}
@media (max-width:560px){.kv{grid-template-columns:1fr;}.kv dt{margin-top:8px;}}
</style>
</head>
<body>
<div class="pay-head">
  <div class="pay-head-inner">
    <span class="pay-mark">MV</span>
    <div><h1>Metro Valet Parking</h1><p>Secure invoice payment</p></div>
  </div>
</div>
<div class="pay-wrap">

  <?php if ($pay_error !== ''): ?>
    <div class="alert a-err" role="alert"><?= e($pay_error) ?></div>
  <?php endif; ?>
  <?php if ($ach_recorded): ?>
    <div class="alert a-info" role="status">
      Your bank transfer has been recorded and is <strong>pending verification</strong>.
      The invoice will be updated once our team confirms receipt.
    </div>
  <?php endif; ?>

  <div class="pay-card">
    <h2>Invoice <?= e((string)$inv['number']) ?></h2>
    <dl class="kv">
      <dt>Issue date</dt><dd><?= e(fmt_date((string)$inv['issue_date'])) ?></dd>
      <dt>Due date</dt><dd><?= e(fmt_date((string)$inv['due_date'])) ?></dd>
      <dt>Total</dt><dd><?= e(money((int)$inv['amount_cents'])) ?></dd>
      <dt>Paid to date</dt><dd><?= e(money((int)$inv['amount_paid_cents'])) ?></dd>
    </dl>
    <hr class="hr">
    <div class="muted">Amount due</div>
    <div class="pay-due"><?= e(money($due)) ?></div>
    <div style="margin-top:6px;">
      <?php if ($is_paid): ?>
        <span class="badge b-green">Paid in full</span>
      <?php elseif ((string)$inv['status'] === 'overdue'): ?>
        <span class="badge" style="background:#FBE7E4;color:#C0392B;">Overdue</span>
      <?php else: ?>
        <span class="badge b-amber"><?= e(ucfirst(str_replace('_', ' ', (string)$inv['status']))) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($items)): ?>
  <div class="pay-card">
    <h2>Line items</h2>
    <table>
      <thead><tr><th>Description</th><th>Qty</th><th>Amount</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e((string)$it['description']) ?></td>
          <td><?= e((string)$it['qty']) ?></td>
          <td><?= e(money((int)$it['amount_cents'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!$is_paid): ?>
    <?php if ($stripe_on): ?>
    <div class="pay-card">
      <h2>Pay by card</h2>
      <p class="muted">You&rsquo;ll be taken to our secure card checkout. You&rsquo;ll return here with a receipt when it completes.</p>
      <form method="post" action="<?= e(site_url('portal/pay.php')) ?>">
        <?php $cf = csrf_field(); if (is_string($cf)) { echo $cf; } ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="stripe">
        <button class="btn btn-gold" type="submit">Pay <?= e(money($due)) ?> with card</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="pay-card">
      <h2>Pay manually</h2>
      <p><?= e($manual_text) ?></p>
      <form method="post" action="<?= e(site_url('portal/pay.php')) ?>"
            onsubmit="return confirm('Confirm that you have initiated a bank transfer for <?= e(money($due)) ?>?');">
        <?php $cf = csrf_field(); if (is_string($cf)) { echo $cf; } ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="ach">
        <button class="btn btn-outline" type="submit">I&rsquo;ve initiated a bank transfer</button>
      </form>
      <p class="muted" style="margin-top:10px;">Transfers are verified by our team before the invoice is marked paid.</p>
    </div>
  <?php else: ?>
    <div class="pay-card">
      <h2>Receipt</h2>
      <div class="alert a-ok" role="status">This invoice is paid in full. Thank you!</div>
      <?php if (!empty($payments)): ?>
      <table>
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Amount</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
          <tr>
            <td><?= e(fmt_date(substr((string)$p['paid_at'], 0, 10))) ?></td>
            <td><?= e(ucfirst((string)$p['method'])) ?></td>
            <td><?= $p['reference'] ? e((string)$p['reference']) : '<span class="muted">—</span>' ?></td>
            <td><?= e(money((int)$p['amount_cents'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <p class="muted" style="text-align:center;">
    Questions about this invoice? Call <?= e(setting('business_phone', '(855)-442-6500')) ?>
    <?php $be = (string)setting('business_email', ''); if ($be !== ''): ?>
      or email <a href="mailto:<?= e($be) ?>"><?= e($be) ?></a>
    <?php endif; ?>
  </p>
</div>
</body>
</html>
