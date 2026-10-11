<?php
require __DIR__ . '/../lib/bootstrap.php';

/* ------------------------------------------------------------------
 * pay/stripe_webhook.php — Stripe webhook endpoint (public).
 *
 * Verifies the Stripe-Signature header (HMAC-SHA256, 300s tolerance)
 * against settings.stripe_webhook_secret, then handles
 * checkout.session.completed with payment_status=paid idempotently:
 * insert payment (reference = session id), recalc invoice, email receipt.
 * Failures are logged to notification_logs with template_key
 * 'stripe_webhook'. Always responds 200 on handled events.
 * ------------------------------------------------------------------ */

$pdo    = db();
$secret = (string)setting('stripe_webhook_secret', '');

function wh_log($pdo, $subject, $detail, $status) {
    try {
        $st = $pdo->prepare(
            "INSERT INTO notification_logs (template_key, recipient, subject, body_hash, status, sent_at, dedupe_key)
             VALUES ('stripe_webhook', 'system', :s, :h, :st, :t, :d)"
        );
        $st->execute(array(
            ':s'  => substr((string)$subject, 0, 190),
            ':h'  => sha1((string)$detail),
            ':st' => $status,
            ':t'  => date('Y-m-d H:i:s'),
            ':d'  => 'stripe_webhook:' . sha1((string)$detail . microtime(true)),
        ));
    } catch (Throwable $e) { /* never break the webhook on logging */ }
}

function wh_respond($code, $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if ($secret === '') {
    wh_log($pdo, 'webhook secret not configured', 'stripe_webhook_secret setting is empty', 'failed');
    wh_respond(500, 'webhook not configured');
}

$payload = file_get_contents('php://input');
if (!is_string($payload) || $payload === '') {
    wh_log($pdo, 'empty payload', 'no body received', 'failed');
    wh_respond(400, 'empty payload');
}

/* --- Verify Stripe-Signature: "t=<ts>,v1=<sig>[,v1=<sig>...]" --- */
$sig_header = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$ts   = 0;
$v1s  = array();
foreach (explode(',', $sig_header) as $part) {
    $kv = explode('=', trim($part), 2);
    if (count($kv) !== 2) { continue; }
    if ($kv[0] === 't') { $ts = (int)$kv[1]; }
    if ($kv[0] === 'v1' && $kv[1] !== '') { $v1s[] = $kv[1]; }
}
if ($ts <= 0 || empty($v1s) || abs(time() - $ts) > 300) {
    wh_log($pdo, 'signature verification failed', 'bad timestamp or missing v1 signatures', 'failed');
    wh_respond(400, 'invalid signature');
}
$expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
$valid = false;
foreach ($v1s as $v) {
    if (hash_equals($expected, $v)) { $valid = true; break; }
}
if (!$valid) {
    wh_log($pdo, 'signature verification failed', 'HMAC mismatch', 'failed');
    wh_respond(400, 'invalid signature');
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    wh_log($pdo, 'invalid JSON payload', substr($payload, 0, 200), 'failed');
    wh_respond(400, 'invalid payload');
}

$type = (string)($event['type'] ?? '');

/* --- Failed / abandoned payments: notify so the customer can retry --- */
if ($type === 'checkout.session.expired' || $type === 'payment_intent.payment_failed') {
    $obj = $event['data']['object'] ?? [];
    $invoice_id = (int)($obj['metadata']['invoice_id'] ?? 0);
    if ($invoice_id > 0) {
        $st = $pdo->prepare(
            'SELECT i.*, c.email AS customer_email, co.billing_email
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id
             LEFT JOIN companies co ON co.id = i.company_id
             WHERE i.id = ? LIMIT 1'
        );
        $st->execute([$invoice_id]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $to = ($inv['customer_email'] ?? '') !== '' ? (string) $inv['customer_email'] : (string) ($inv['billing_email'] ?? '');
            $pay_url = site_url('portal/pay.php') . '?token=' . $inv['payment_token'];
            $reason = $type === 'payment_intent.payment_failed'
                ? (string) (($obj['last_payment_error']['message'] ?? '') ?: 'Your card was declined.')
                : 'Your checkout session expired before payment was completed.';
            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                try {
                    send_template('payment_failed', $to, [
                        'customer_name' => (string) ($inv['customer_name'] ?? $inv['company_name'] ?? 'Valued customer'),
                        'number'        => (string) $inv['number'],
                        'amount'        => money((int) $inv['amount_cents'] - (int) $inv['amount_paid_cents']),
                        'reason'        => $reason,
                        'pay_url'       => $pay_url,
                        'business_name' => (string) setting('business_name', 'Metro Valet Parking'),
                    ], 'payment_failed:' . $invoice_id . ':' . (string) ($obj['id'] ?? microtime(true)));
                } catch (Throwable $e) {
                    wh_log($pdo, 'failed-payment email error', $e->getMessage(), 'failed');
                }
            }
            wh_log($pdo, $type, 'invoice_id=' . $invoice_id . ' — customer notified with retry link', 'sent');
        }
    } else {
        wh_log($pdo, $type, 'no invoice metadata', 'sent');
    }
    wh_respond(200, 'handled');
}

if ($type !== 'checkout.session.completed') {
    /* Acknowledge anything else without action. */
    wh_respond(200, 'ignored');
}

$sess = $event['data']['object'] ?? array();
if (($sess['payment_status'] ?? '') !== 'paid') {
    wh_log($pdo, 'session not paid', 'session ' . (string)($sess['id'] ?? '?'), 'failed');
    wh_respond(200, 'not paid');
}

$session_id = (string)($sess['id'] ?? '');
$invoice_id = (int)($sess['metadata']['invoice_id'] ?? 0);
if ($session_id === '' || $invoice_id <= 0) {
    wh_log($pdo, 'missing session/invoice reference', substr($payload, 0, 300), 'failed');
    wh_respond(400, 'missing references');
}

$st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
$st->execute(array($invoice_id));
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    wh_log($pdo, 'invoice not found', 'invoice_id=' . $invoice_id, 'failed');
    wh_respond(200, 'invoice not found');
}

$amount_cents = (int)($sess['amount_total'] ?? 0);
$now = date('Y-m-d H:i:s');
$pdo->beginTransaction();
try {
    $chk = $pdo->prepare("SELECT id FROM payments WHERE reference = ? AND method = 'card' LIMIT 1");
    $chk->execute(array($session_id));
    $already = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$already && $amount_cents > 0) {
        $ins = $pdo->prepare(
            "INSERT INTO payments (invoice_id, amount_cents, method, reference, paid_at,
                                   recorded_by, recorded_by_customer, notes, created_at)
             VALUES (?, ?, 'card', ?, ?, NULL, 1, 'Stripe Checkout (webhook)', ?)"
        );
        $ins->execute(array($invoice_id, $amount_cents, $session_id, $now, $now));
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    wh_log($pdo, 'payment insert failed', $e->getMessage(), 'failed');
    wh_respond(500, 'insert failed');
}

recalc_invoice($invoice_id);

/* Email the paid invoice + receipt (deduped per session). */
try {
    send_paid_invoice_email($invoice_id, $session_id);
} catch (Throwable $e) {
    wh_log($pdo, 'receipt email failed', $e->getMessage(), 'failed');
}

wh_log($pdo, 'checkout.session.completed processed', 'invoice_id=' . $invoice_id . ' session=' . $session_id, 'sent');
wh_respond(200, 'ok');
