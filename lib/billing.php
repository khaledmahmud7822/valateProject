<?php
/**
 * Metro Valet Parking — shared billing helpers.
 *
 * create_invoice_for_subscription() is the ONE place invoices are born:
 * request approval, direct parker creation, renewals, and the
 * "generate missing invoices" batch all go through it, so every invoice
 * has the same numbering, token, line-item shape, and audit trail.
 */

/**
 * Create an invoice for a subscription period.
 *
 * $sub must carry: id, customer_id, company_id, customer_name|company_name,
 * location_name, start_date, end_date.
 * Returns the new invoice id. Caller owns the transaction.
 */
function create_invoice_for_subscription(PDO $db, array $sub, int $amount_cents, string $issue_date, string $due_date, int $created_by, string $note = ''): int
{
    $number = new_invoice_number();
    $token = secure_token();
    $db->prepare(
        'INSERT INTO invoices (number, subscription_id, company_id, customer_id, issue_date, due_date, amount_cents, amount_paid_cents, status, payment_token, created_by, notes, created_at)
         VALUES (?,?,?,?,?,?,?,0,\'unpaid\',?,?,?,CURRENT_TIMESTAMP)'
    )->execute([$number, $sub['id'], $sub['company_id'], $sub['customer_id'], $issue_date, $due_date, $amount_cents, $token, $created_by, $note]);
    $inv_id = (int) $db->lastInsertId();

    // Re-query fresh subscription data for the description (never trust stale passed-in data).
    $fresh = $db->prepare(
        'SELECT s.start_date, s.end_date, c.name AS customer_name, co.name AS company_name, l.name AS location_name
         FROM subscriptions s
         LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN companies co ON co.id = s.company_id
         LEFT JOIN parking_locations l ON l.id = s.location_id
         WHERE s.id = ?'
    );
    $fresh->execute([$sub['id']]);
    $f = $fresh->fetch(PDO::FETCH_ASSOC) ?: [];
    $who = ($f['customer_name'] ?? null) ?: ($f['company_name'] ?? null) ?: ($sub['customer_name'] ?? null) ?: ($sub['company_name'] ?? null) ?: 'parker';
    $loc = ($f['location_name'] ?? null) ?: ($sub['location_name'] ?? '');
    $sdate = ($f['start_date'] ?? null) ?: ($sub['start_date'] ?? null);
    $edate = ($f['end_date'] ?? null) ?: ($sub['end_date'] ?? null);
    $desc = 'Monthly parking — ' . $loc . ' — ' . $who
        . ' (' . fmt_date($sdate) . ' to ' . fmt_date($edate) . ')';
    $db->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents, amount_cents) VALUES (?,?,?,?,?)')
        ->execute([$inv_id, $desc, 1, $amount_cents, $amount_cents]);

    audit('invoice.created', 'invoice', $inv_id, ['number' => $number, 'subscription_id' => $sub['id'], 'note' => $note]);
    return $inv_id;
}

/**
 * True when the subscription has at least one invoice (any status).
 * Used by the "generate missing invoices" batch — it only fills gaps,
 * never duplicates.
 */
function subscription_has_invoice(PDO $db, int $sub_id): bool
{
    $st = $db->prepare('SELECT 1 FROM invoices WHERE subscription_id = ? LIMIT 1');
    $st->execute([$sub_id]);
    return (bool) $st->fetchColumn();
}

/**
 * Send the "paid invoice" email for an invoice: the spec-formatted
 * payment_received template with the invoice PDF attached.
 *
 * This is the single shared helper — used by the Stripe return page,
 * the Stripe webhook, manual payment recording, the admin "Complete
 * payment" action and "Send paid receipt". The $dedupe_suffix makes
 * each payment's receipt unique so resends never duplicate.
 *
 * Returns true when the email was sent (or was already sent before).
 */
function send_paid_invoice_email(int $invoice_id, ?string $dedupe_suffix = null): bool
{
    $d = invoice_pdf_data($invoice_id);
    if ($d === null) {
        return false;
    }
    $to = (string) ($d['bill_email'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $inv = $d['inv'];
    $suffix = $dedupe_suffix ?? ('inv' . $invoice_id);
    $dedupe = 'payment_received:' . $invoice_id . ':' . $suffix;

    $pdf = build_invoice_pdf($invoice_id);
    $attachments = [];
    if (is_string($pdf) && $pdf !== '') {
        $attachments[] = [
            'filename' => 'Invoice-' . preg_replace('/[^A-Za-z0-9-]+/', '', (string) $inv['number']) . '.pdf',
            'content'  => $pdf,
            'type'     => 'application/pdf',
        ];
    }

    $vars = [
        'customer_name' => $d['bill_name'] !== '' ? $d['bill_name'] : 'Valued customer',
        'number'        => (string) $inv['number'],
        'amount'        => money((int) $inv['amount_paid_cents']),
        'paid_at'       => (string) ($d['last_pay']['paid_at'] ?? date('Y-m-d H:i:s')),
        'location_name' => (string) ($inv['location_name'] ?? 'Metro Valet Parking'),
        'plate'         => $d['plates'] !== [] ? implode(', ', $d['plates']) : '—',
        'period_start'  => (string) ($inv['period_start'] ?? ''),
        'period_end'    => (string) ($inv['period_end'] ?? ''),
        'reference'     => (string) ($d['last_pay']['reference'] ?? ''),
        'business_name' => (string) setting('business_name', 'Metro Valet Parking'),
    ];

    return send_template('payment_received', $to, $vars, $dedupe, $attachments);
}

/**
 * POST to the Stripe API. Returns [http_code, raw_body].
 */
function mv_stripe_post(string $secret, string $path, array $fields): array
{
    $ch = curl_init('https://api.stripe.com/v1' . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secret]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') {
        return [0, ''];
    }
    return [$code, is_string($body) ? $body : ''];
}

/**
 * Fetch a Stripe Checkout Session. Returns the decoded array, or null
 * when Stripe cannot be reached / the session is invalid.
 */
function mv_stripe_get_session(string $secret, string $session_id): ?array
{
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($session_id));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secret]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '' || $code !== 200 || !is_string($body)) {
        return null;
    }
    $sess = json_decode($body, true);
    return is_array($sess) ? $sess : null;
}

/**
 * GET from the Stripe API. Returns [http_code, decoded_array_or_null, curl_error].
 */
function mv_stripe_get(string $secret, string $path): array
{
    $ch = curl_init('https://api.stripe.com/v1' . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secret]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    return [$code, is_array($data) ? $data : null, $err];
}

/**
 * Auto-extend a subscription when its invoice is paid.
 *
 * Called after an invoice flips to 'paid'. Extends when:
 *  - the invoice was flagged as a renewal (extend_months > 0), OR
 *  - the linked subscription ends within 14 days (or already ended)
 * And only once per invoice (extended_at IS NULL) — idempotent.
 *
 * New end date = max(current end_date, today) + N months (N = extend_months
 * or 1). Also extends active permits for the subscription's vehicle.
 *
 * Returns the new end date (Y-m-d) on success, null when nothing was done.
 */
function maybe_auto_extend_subscription(int $invoice_id): ?string
{
    $db = db();
    $st = $db->prepare('SELECT id, subscription_id, extend_months, extended_at, status FROM invoices WHERE id = ?');
    $st->execute([$invoice_id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv || !empty($inv['extended_at'])) {
        return null; // already extended
    }
    if (($inv['status'] ?? '') !== 'paid' || empty($inv['subscription_id'])) {
        return null;
    }
    $sub_id = (int) $inv['subscription_id'];
    $st = $db->prepare('SELECT id, end_date, vehicle_id, location_id FROM subscriptions WHERE id = ?');
    $st->execute([$sub_id]);
    $sub = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        return null;
    }
    $months = (int) ($inv['extend_months'] ?? 0);
    $today = date('Y-m-d');
    $expiring_soon = empty($sub['end_date']) || $sub['end_date'] <= date('Y-m-d', strtotime($today . ' +14 days'));
    if ($months <= 0) {
        if (!$expiring_soon) {
            return null; // not a renewal invoice and not expiring — do nothing
        }
        $months = 1;
    }
    // Extend from the current end date (not today) so each payment catches up
    // one missed month at a time. E.g. end=Jun 2, pay 1 month → Jul 2.
    $base = !empty($sub['end_date']) ? $sub['end_date'] : $today;
    $new_end = date('Y-m-d', strtotime($base . ' +' . $months . ' month'));
    $now = date('Y-m-d H:i:s');

    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $db->prepare("UPDATE subscriptions SET end_date = ?, status = 'active', updated_at = ? WHERE id = ?")
            ->execute([$new_end, $now, $sub_id]);
        if (!empty($sub['vehicle_id'])) {
            $db->prepare("UPDATE permits SET end_date = ?, status = 'active' WHERE vehicle_id = ? AND location_id = ? AND status != 'expired'")
                ->execute([$new_end, $sub['vehicle_id'], $sub['location_id']]);
        }
        $db->prepare('UPDATE invoices SET extended_at = ? WHERE id = ?')
            ->execute([$now, $invoice_id]);
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own) {
            $db->rollBack();
        }
        return null;
    }
    try {
        audit('subscription.auto_renewed', 'subscription', $sub_id, ['invoice_id' => $invoice_id, 'new_end' => $new_end]);
    } catch (Throwable $e) { /* audit is best-effort here */ }
    return $new_end;
}
