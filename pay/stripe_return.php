<?php
require __DIR__ . '/../lib/bootstrap.php';

/* ------------------------------------------------------------------
 * pay/stripe_return.php — Stripe Checkout return (public).
 *
 * Params: ?session_id=<checkout session id>&token=<invoice.payment_token>
 *
 * Flow:
 *   1. GET  — verify the session with Stripe, record the payment
 *              idempotently, recalc the invoice, email the receipt,
 *              then show a "Payment successful" confirmation with a
 *              "Complete payment" button.
 *   2. POST — customer clicks "Complete payment" -> full PAID invoice
 *              with a thank-you message (printable).
 * ------------------------------------------------------------------ */

function sr_head(string $title): string {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' — Metro Valet Parking</title>'
        . '<style>:root{--navy:#0A1A2F;--gold:#C9A227;--ink:#1B2430;--muted:#5B6774;--line:#E2E8F0;--mist:#F4F6F9;--green:#1E7E46;}'
        . 'body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--mist);color:var(--ink);margin:0;}'
        . '.h{background:var(--navy);color:#fff;padding:18px 20px;}'
        . '.h div{max-width:680px;margin:0 auto;font-size:18px;font-weight:700;display:flex;align-items:center;gap:10px;}'
        . '.h .mv{background:var(--gold);color:var(--navy);font-weight:800;border-radius:8px;padding:2px 8px;font-size:14px;}'
        . '.w{max-width:680px;margin:24px auto 48px;padding:0 20px;}'
        . '.c{background:#fff;border:1px solid var(--line);border-radius:14px;padding:28px;box-shadow:0 2px 10px rgba(10,26,47,.06);}'
        . 'h1{font-size:22px;color:var(--navy);margin:0 0 6px;}'
        . 'p{color:var(--muted);line-height:1.55;}'
        . 'table{width:100%;border-collapse:collapse;font-size:14px;margin-top:14px;}'
        . 'td,th{padding:9px 4px;border-bottom:1px solid var(--line);text-align:left;}'
        . 'td:first-child,th:first-child{color:var(--muted);}td:last-child,th:last-child{text-align:right;}'
        . '.ok{color:var(--green);font-weight:700;}'
        . '.paid-stamp{display:inline-block;background:var(--green);color:#fff;font-weight:800;letter-spacing:2px;border-radius:8px;padding:6px 16px;margin:8px 0;}'
        . '.btn{display:inline-block;background:var(--navy);color:#fff!important;border:0;border-radius:10px;padding:13px 26px;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;margin-top:14px;}'
        . '.btn:hover{background:#13283f;}.btn-ghost{background:#fff;color:var(--navy)!important;border:1px solid var(--line);}'
        . '.thanks{background:#f0fbf4;border:1px solid #bfe6cd;border-radius:10px;padding:16px;margin:16px 0;color:#14532d;}'
        . '.foot{text-align:center;margin-top:18px;font-size:13px;}'
        . '@media print{.h,.foot,.no-print{display:none!important;}body{background:#fff;}.w{margin:0;max-width:100%;}.c{box-shadow:none;border:0;}}'
        . '</style></head><body>'
        . '<div class="h"><div><span class="mv">MV</span> Metro Valet Parking</div></div><div class="w">';
}

function sr_foot(): string {
    return '<p class="foot">Questions? Call ' . htmlspecialchars((string)setting('business_phone', '(855)-442-6500'), ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div></body></html>';
}

function sr_page(string $title, string $inner, bool $ok = true): void {
    http_response_code($ok ? 200 : 400);
    echo sr_head($title) . '<div class="c"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $inner . '</div>' . sr_foot();
    exit;
}

$pdo        = db();
$session_id = trim((string)($_REQUEST['session_id'] ?? ''));
$token      = trim((string)($_REQUEST['token'] ?? ''));
$secret     = (string)setting('stripe_secret_key', '');

if ($token === '' || $session_id === '' || $secret === '') {
    sr_page('Payment link invalid', '<p>The payment link is incomplete. Please use the link from your invoice email.</p>', false);
}

/* Verify the invoice by its secure token. */
$st = $pdo->prepare('SELECT * FROM invoices WHERE payment_token = ? LIMIT 1');
$st->execute([$token]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    sr_page('Invoice not found', '<p>The payment link is invalid or has expired.</p>', false);
}
$inv_id = (int)$inv['id'];

/* Ask Stripe for the authoritative session state. */
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($session_id));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secret]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);
$sess = is_string($body) ? json_decode($body, true) : null;

if ($cerr !== '' || $code !== 200 || !is_array($sess)) {
    sr_page('Could not verify payment', '<p>We could not reach the payment processor to verify this session. '
        . 'If you were charged, the payment will appear on your invoice once confirmed — '
        . 'no need to pay again.</p>', false);
}

/* The session must belong to this invoice. */
if ((string)($sess['metadata']['invoice_id'] ?? '') !== (string)$inv_id) {
    sr_page('Session mismatch', '<p>This payment session does not match the invoice.</p>', false);
}

if (($sess['payment_status'] ?? '') !== 'paid') {
    $inner = '<p>Your payment has not completed yet.</p>'
        . '<p>If you just paid, give it a moment and refresh this page. '
        . 'If you cancelled the checkout, <a href="' . htmlspecialchars(site_url('portal/pay.php') . '?token=' . urlencode($token), ENT_QUOTES, 'UTF-8') . '">return to the payment page</a> to try again.</p>';
    sr_page('Payment pending', $inner, true);
}

/* Paid — record idempotently (payments.reference = Stripe session id). */
$amount_cents = (int)($sess['amount_total'] ?? 0);
$now = date('Y-m-d H:i:s');
$pdo->beginTransaction();
try {
    $chk = $pdo->prepare("SELECT id FROM payments WHERE reference = ? AND method = 'card' LIMIT 1");
    $chk->execute([$session_id]);
    $already = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$already && $amount_cents > 0) {
        $ins = $pdo->prepare(
            "INSERT INTO payments (invoice_id, amount_cents, method, reference, paid_at,
                                   recorded_by, recorded_by_customer, notes, created_at)
             VALUES (?, ?, 'card', ?, ?, NULL, 1, 'Stripe Checkout', ?)"
        );
        $ins->execute([$inv_id, $amount_cents, $session_id, $now, $now]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    sr_page('Payment error', '<p>Your card was charged but we could not record it. Please contact us with this reference: '
        . htmlspecialchars($session_id, ENT_QUOTES, 'UTF-8') . '</p>', false);
}

recalc_invoice($inv_id);

/* Email the paid invoice + receipt (deduped on the session id). */
try {
    send_paid_invoice_email($inv_id, $session_id);
} catch (Throwable $e) { /* receipt page is the source of truth */ }

/* Refresh the invoice row. */
$st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
$st->execute([$inv_id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);

/* ---------------------------------------------------------------
 * STEP 2 — customer clicked "Complete payment": show the PAID invoice.
 * --------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'complete') {
    // The token + a recorded card payment for this session is the proof.
    $chk = $pdo->prepare("SELECT id, paid_at FROM payments WHERE invoice_id = ? AND reference = ? AND method = 'card' LIMIT 1");
    $chk->execute([$inv_id, $session_id]);
    $pay_rec = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$pay_rec) {
        sr_page('Payment not found', '<p>We could not confirm this payment. Please contact us.</p>', false);
    }
    $paid_at = (string)($pay_rec['paid_at'] ?? $now);

    $d = $pdo->prepare(
        'SELECT i.*, c.name AS customer_name, c.email AS customer_email,
                co.name AS company_name, co.billing_email,
                l.name AS location_name
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         LEFT JOIN companies co ON co.id = i.company_id
         LEFT JOIN subscriptions s ON s.id = i.subscription_id
         LEFT JOIN parking_locations l ON l.id = s.location_id
         WHERE i.id = ? LIMIT 1'
    );
    $d->execute([$inv_id]);
    $full = $d->fetch(PDO::FETCH_ASSOC);

    $it = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id');
    $it->execute([$inv_id]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);

    $bill_to = ($full['customer_name'] ?? '') ?: ($full['company_name'] ?? '');
    $bill_email = ($full['customer_email'] ?? '') ?: ($full['billing_email'] ?? '');

    $rows = '';
    foreach ($items as $row) {
        $rows .= '<tr><td>' . htmlspecialchars((string)$row['description'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . (int)$row['qty'] . '</td>'
            . '<td>' . htmlspecialchars(money((int)$row['unit_cents']), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td><strong>' . htmlspecialchars(money((int)$row['amount_cents']), ENT_QUOTES, 'UTF-8') . '</strong></td></tr>';
    }

    $inner = '<div class="thanks"><strong>Thank you for your payment!</strong><br>'
        . 'Your monthly parking is all set. A receipt has been emailed to you.</div>'
        . '<div style="text-align:center"><span class="paid-stamp">PAID</span></div>'
        . '<table>'
        . '<tr><td>Invoice</td><td><strong>' . htmlspecialchars((string)$full['number'], ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
        . '<tr><td>Billed to</td><td>' . htmlspecialchars($bill_to, ENT_QUOTES, 'UTF-8')
        . ($bill_email !== '' ? '<br><span style="font-size:13px">' . htmlspecialchars($bill_email, ENT_QUOTES, 'UTF-8') . '</span>' : '') . '</td></tr>'
        . (!empty($full['location_name']) ? '<tr><td>Location</td><td>' . htmlspecialchars((string)$full['location_name'], ENT_QUOTES, 'UTF-8') . '</td></tr>' : '')
        . '<tr><td>Paid on</td><td>' . htmlspecialchars($paid_at, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td>Reference</td><td style="font-size:13px">' . htmlspecialchars($session_id, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . '<table><tr><th>Description</th><th>Qty</th><th>Unit</th><th>Amount</th></tr>' . $rows . '</table>'
        . '<table>'
        . '<tr><td>Total</td><td><strong>' . htmlspecialchars(money((int)$full['amount_cents']), ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
        . '<tr><td>Paid</td><td class="ok">' . htmlspecialchars(money((int)$full['amount_paid_cents']), ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td><strong>Balance due</strong></td><td><strong>' . htmlspecialchars(money((int)$full['amount_cents'] - (int)$full['amount_paid_cents']), ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
        . '</table>'
        . '<div class="no-print" style="text-align:center;margin-top:8px">'
        . '<button type="button" class="btn btn-ghost" onclick="window.print()">Print / Save PDF</button>'
        . '</div>';

    http_response_code(200);
    echo sr_head('Paid invoice ' . (string)$full['number'])
        . '<div class="c"><h1>Invoice ' . htmlspecialchars((string)$full['number'], ENT_QUOTES, 'UTF-8') . '</h1>' . $inner . '</div>'
        . sr_foot();
    exit;
}

/* ---------------------------------------------------------------
 * STEP 1 — confirmation with the "Complete payment" button.
 * --------------------------------------------------------------- */
$complete_url = site_url('pay/stripe_return.php');
$inner = '<p class="ok" style="font-size:17px">Payment successful — thank you!</p>'
    . '<table>'
    . '<tr><td>Invoice</td><td><strong>' . htmlspecialchars((string)$inv['number'], ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
    . '<tr><td>Amount charged</td><td><strong>' . htmlspecialchars(money($amount_cents), ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
    . '<tr><td>Date</td><td>' . htmlspecialchars($now, ENT_QUOTES, 'UTF-8') . '</td></tr>'
    . '<tr><td>Invoice status</td><td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$inv['status'])), ENT_QUOTES, 'UTF-8') . '</td></tr>'
    . '</table>'
    . '<p>Click below to complete and view your paid invoice.</p>'
    . '<form method="post" action="' . htmlspecialchars($complete_url, ENT_QUOTES, 'UTF-8') . '">'
    . '<input type="hidden" name="op" value="complete">'
    . '<input type="hidden" name="session_id" value="' . htmlspecialchars($session_id, ENT_QUOTES, 'UTF-8') . '">'
    . '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<div style="text-align:center"><button type="submit" class="btn">Complete payment</button></div>'
    . '</form>'
    . '<p style="font-size:13px">A receipt has been emailed to you.</p>';
sr_page('Payment successful', $inner, true);
