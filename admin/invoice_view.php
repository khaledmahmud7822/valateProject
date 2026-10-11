<?php
// admin/invoice_view.php — invoice detail: items, payments, totals, pay link, print.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Invoice';
$nav_section = 'invoices';
$required_perm = 'invoices.view';
require __DIR__ . '/_guard.php';

$db = db();

$id = (int) ($_GET['id'] ?? 0);
$st = $db->prepare(
    'SELECT i.*, c.name AS customer_name, c.email AS customer_email,
            co.name AS company_name, co.billing_email,
            l.name AS location_name,
            u.name AS created_by_name
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     LEFT JOIN companies co ON co.id = i.company_id
     LEFT JOIN subscriptions s ON s.id = i.subscription_id
     LEFT JOIN parking_locations l ON l.id = s.location_id
     LEFT JOIN users u ON u.id = i.created_by
     WHERE i.id = ?'
);
$st->execute([$id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    flash('Invoice not found.', 'error');
    redirect(site_url('admin/invoices.php'));
}
$page_title = 'Invoice ' . $inv['number'];

$is = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id');
$is->execute([$id]);
$items = $is->fetchAll(PDO::FETCH_ASSOC);

$ps = $db->prepare(
    'SELECT p.*, u.name AS recorded_by_name FROM payments p
     LEFT JOIN users u ON u.id = p.recorded_by
     WHERE p.invoice_id = ? ORDER BY p.paid_at DESC, p.id DESC'
);
$ps->execute([$id]);
$payments = $ps->fetchAll(PDO::FETCH_ASSOC);

$can_edit = can('invoices.edit');
$edit_mode = $can_edit && isset($_GET['edit']);
$user = current_user();

// ---------- Edit / void / restore ----------
if ($can_edit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = (string) ($_POST['op'] ?? '');

    if ($op === 'void' && $inv['status'] !== 'voided') {
        $db->prepare("UPDATE invoices SET status = 'voided', deleted_at = datetime('now') WHERE id = ?")->execute([$id]);
        audit('invoice.voided', 'invoice', $id, ['number' => $inv['number']]);
        flash('Invoice ' . $inv['number'] . ' voided and moved to trash.', 'success');
        redirect(site_url('admin/invoices.php?view=trash'));
    }
    if ($op === 'restore' && $inv['status'] === 'voided') {
        $db->prepare("UPDATE invoices SET status = 'unpaid', deleted_at = NULL WHERE id = ?")->execute([$id]);
        recalc_invoice($id);
        audit('invoice.restored', 'invoice', $id, ['number' => $inv['number']]);
        flash('Invoice ' . $inv['number'] . ' restored.', 'success');
        redirect(site_url('admin/invoice_view.php?id=' . $id));
    }

    /* ---- Send / resend the invoice email with the secure pay link ---- */
    if ($op === 'send_invoice' && !in_array($inv['status'], ['voided', 'refunded'], true)) {
        $to = ($inv['customer_email'] ?? '') !== '' ? (string) $inv['customer_email'] : (($inv['billing_email'] ?? '') !== '' ? (string) $inv['billing_email'] : (string) ($inv['bill_email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('No valid customer email on file — cannot send the invoice.', 'error');
        } else {
            $pay_url = site_url('portal/pay.php') . '?token=' . $inv['payment_token'];
            $who = ($inv['customer_name'] ?? '') !== '' ? (string) $inv['customer_name'] : (($inv['company_name'] ?? '') !== '' ? (string) $inv['company_name'] : (string) ($inv['bill_name'] ?? ''));
            $ok = send_template('invoice_issued', $to, [
                'customer_name' => $who !== '' ? $who : 'Valued customer',
                'number'        => (string) $inv['number'],
                'amount'        => money((int) $inv['amount_cents'] - (int) $inv['amount_paid_cents']),
                'due_date'      => (string) ($inv['due_date'] ?? ''),
                'pay_url'       => $pay_url,
                'business_name' => (string) setting('business_name', 'Metro Valet Parking'),
            ], 'invoice_sent:' . $id . ':' . time());
            audit('invoice.sent', 'invoice', $id, ['to' => $to, 'number' => $inv['number']]);
            flash($ok ? 'Invoice ' . $inv['number'] . ' emailed to ' . $to . '.' : 'Could not send the invoice email.', $ok ? 'success' : 'error');
        }
        redirect(site_url('admin/invoice_view.php?id=' . $id));
    }

    /* ---- Resend the paid invoice + thank-you email (paid invoices only) ---- */
    if ($op === 'send_receipt' && can('payments.record') && $inv['status'] === 'paid') {
        $ok = false;
        try {
            $ok = send_paid_invoice_email($id, 'resend' . time());
        } catch (Throwable $e) { /* fall through to flash */ }
        audit('invoice.receipt_resent', 'invoice', $id, ['number' => $inv['number']]);
        flash($ok ? 'Paid invoice and thank-you email resent.' : 'Could not resend the receipt.', $ok ? 'success' : 'error');
        redirect(site_url('admin/invoice_view.php?id=' . $id));
    }

    /* ---- Complete payment: finalize ONLY a Stripe-verified paid session ----
     * This NEVER marks an invoice paid on its own. It asks Stripe for the
     * authoritative session state; if Stripe says the session is not paid,
     * nothing changes. */
    if ($op === 'complete_payment' && can('payments.record')
        && !in_array($inv['status'], ['voided', 'refunded', 'paid'], true)) {
        $balance = (int) $inv['amount_cents'] - (int) $inv['amount_paid_cents'];
        $sess_id = (string) ($inv['stripe_session_id'] ?? '');
        $secret = (string) setting('stripe_secret_key', '');
        if ($balance <= 0 || $sess_id === '' || $secret === '') {
            flash('Nothing to complete: no outstanding balance with a Stripe checkout session.', 'error');
            redirect(site_url('admin/invoice_view.php?id=' . $id));
        }
        $sess = mv_stripe_get_session($secret, $sess_id);
        if ($sess === null) {
            flash('Could not reach Stripe to verify this payment. Nothing was changed.', 'error');
            redirect(site_url('admin/invoice_view.php?id=' . $id));
        }
        if ((string) ($sess['metadata']['invoice_id'] ?? '') !== (string) $id) {
            flash('This Stripe session does not belong to this invoice. Nothing was changed.', 'error');
            redirect(site_url('admin/invoice_view.php?id=' . $id));
        }
        if (($sess['payment_status'] ?? '') !== 'paid') {
            flash('Stripe reports this payment is NOT complete (status: ' . ($sess['payment_status'] ?? 'unknown') . '). The invoice was not marked paid.', 'error');
            redirect(site_url('admin/invoice_view.php?id=' . $id));
        }
        // Stripe confirms paid — record idempotently, recalc, email the paid invoice.
        $amount_cents = (int) ($sess['amount_total'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            $chk = $db->prepare("SELECT id FROM payments WHERE reference = ? AND method = 'card' LIMIT 1");
            $chk->execute([$sess_id]);
            if (!$chk->fetch() && $amount_cents > 0) {
                $db->prepare(
                    "INSERT INTO payments (invoice_id, amount_cents, method, reference, paid_at, recorded_by, recorded_by_customer, notes, created_at)
                     VALUES (?, ?, 'card', ?, ?, ?, 0, 'Stripe Checkout (admin completion)', ?)"
                )->execute([$id, $amount_cents, $sess_id, $now, (int) $user['id'], $now]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            flash('Could not record the payment: ' . $e->getMessage(), 'error');
            redirect(site_url('admin/invoice_view.php?id=' . $id));
        }
        $new_status = recalc_invoice($id);
        try {
            send_paid_invoice_email($id, $sess_id);
        } catch (Throwable $e) { /* invoice is paid regardless */ }
        audit('payment.completed', 'invoice', $id, ['number' => $inv['number'], 'session' => $sess_id, 'new_status' => $new_status]);
        flash('Payment verified with Stripe and finalized. Invoice is now ' . str_replace('_', ' ', $new_status) . '; the paid invoice was emailed.', 'success');
        redirect(site_url('admin/invoice_view.php?id=' . $id));
    }

    /* ---- Stripe refund (authorized users only; card payments only) ---- */
    if ($op === 'refund' && can('payments.refund') && !in_array($inv['status'], ['voided', 'refunded'], true)) {
        $secret = (string) setting('stripe_secret_key', '');
        $cp = $db->prepare("SELECT * FROM payments WHERE invoice_id = ? AND method = 'card' AND reference LIKE 'cs\\_%' ESCAPE '\\' ORDER BY id DESC LIMIT 1");
        $cp->execute([$id]);
        $card_pay = $cp->fetch(PDO::FETCH_ASSOC);
        if ($secret === '' || !$card_pay) {
            flash('Refunds are only available for card payments processed through Stripe.', 'error');
            redirect(site_url('admin/invoice_view.php?id=' . $id));
        }
        $sess = mv_stripe_get_session($secret, (string) $card_pay['reference']);
        $pi = is_array($sess) ? (string) ($sess['payment_intent'] ?? '') : '';
        if ($pi === '') {
            flash('Could not find the Stripe payment for this invoice. Nothing was refunded.', 'error');
            redirect(site_url('admin/invoice_view.php?id=' . $id));
        }
        [$code, $body] = mv_stripe_post($secret, '/refunds', ['payment_intent' => $pi]);
        $rj = is_string($body) ? json_decode($body, true) : null;
        if ($code < 200 || $code >= 300 || !is_array($rj) || ($rj['status'] ?? '') === 'failed') {
            $msg = is_array($rj) && isset($rj['error']['message']) ? (string) $rj['error']['message'] : 'Stripe rejected the refund.';
            flash('Refund failed: ' . $msg, 'error');
            redirect(site_url('admin/invoice_view.php?id=' . $id));
        }
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO payments (invoice_id, amount_cents, method, reference, paid_at, recorded_by, recorded_by_customer, notes, created_at)
                 VALUES (?, ?, 'card', ?, ?, ?, 0, ?, ?)"
            )->execute([$id, -1 * (int) $card_pay['amount_cents'], 're_' . (string) ($rj['id'] ?? ''), date('Y-m-d H:i:s'), (int) $user['id'], 'Stripe refund of ' . (string) $card_pay['reference'], date('Y-m-d H:i:s')]);
            $db->prepare("UPDATE invoices SET status = 'refunded' WHERE id = ?")->execute([$id]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            flash('Stripe issued the refund but we could not record it: ' . $e->getMessage(), 'error');
            redirect(site_url('admin/invoice_view.php?id=' . $id));
        }
        audit('payment.refunded', 'invoice', $id, ['number' => $inv['number'], 'refund_id' => (string) ($rj['id'] ?? ''), 'amount_cents' => (int) $card_pay['amount_cents']]);
        flash('Refund of ' . money((int) $card_pay['amount_cents']) . ' issued through Stripe. Invoice marked refunded.', 'success');
        redirect(site_url('admin/invoice_view.php?id=' . $id));
    }
    if ($op === 'save') {
        $due = trim((string) ($_POST['due_date'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $descs = (array) ($_POST['item_desc'] ?? []);
        $qtys = (array) ($_POST['item_qty'] ?? []);
        $units = (array) ($_POST['item_unit'] ?? []);
        $errs = [];
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            $errs[] = 'Due date must be YYYY-MM-DD.';
        }
        $new_items = [];
        foreach ($descs as $k => $d) {
            $d = trim((string) $d);
            $q = trim((string) ($qtys[$k] ?? ''));
            $u = trim((string) ($units[$k] ?? ''));
            if ($d === '' && $q === '' && $u === '') {
                continue; // blank row
            }
            if ($d === '') {
                $errs[] = 'Every item needs a description.';
                break;
            }
            if (!ctype_digit($q) || (int) $q < 0) {
                $errs[] = 'Quantities must be whole numbers.';
                break;
            }
            if (!is_numeric($u) || (float) $u < 0) {
                $errs[] = 'Unit prices must be non-negative numbers.';
                break;
            }
            $unit_cents = (int) round((float) $u * 100);
            $new_items[] = ['description' => $d, 'qty' => (int) $q, 'unit_cents' => $unit_cents, 'amount_cents' => (int) $q * $unit_cents];
        }
        if ($new_items === []) {
            $errs[] = 'The invoice needs at least one line item.';
        }
        $new_total = array_sum(array_column($new_items, 'amount_cents'));
        if ($new_total <= 0) {
            $errs[] = 'The invoice total must be greater than $0.';
        }
        if ($errs !== []) {
            foreach ($errs as $er) {
                flash($er, 'error');
            }
            redirect(site_url('admin/invoice_view.php?id=' . $id . '&edit=1'));
        }
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $ins = $db->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents, amount_cents) VALUES (?,?,?,?,?)');
            foreach ($new_items as $ni) {
                $ins->execute([$id, $ni['description'], $ni['qty'], $ni['unit_cents'], $ni['amount_cents']]);
            }
            $db->prepare('UPDATE invoices SET due_date = ?, notes = ?, amount_cents = ? WHERE id = ?')
                ->execute([$due !== '' ? $due : null, $notes, $new_total, $id]);
            $new_status = recalc_invoice($id); // re-derive paid/partial/unpaid from real payments
            $db->commit();
            audit('invoice.updated', 'invoice', $id, ['number' => $inv['number'], 'new_total_cents' => $new_total, 'status' => $new_status]);
            flash('Invoice ' . $inv['number'] . ' updated.', 'success');
            redirect(site_url('admin/invoice_view.php?id=' . $id));
        } catch (Exception $ex) {
            $db->rollBack();
            flash('Could not save: ' . $ex->getMessage(), 'error');
            redirect(site_url('admin/invoice_view.php?id=' . $id . '&edit=1'));
        }
    }
}

$balance = (int) $inv['amount_cents'] - (int) $inv['amount_paid_cents'];
$pay_url = site_url('portal/pay.php') . '?token=' . $inv['payment_token'];
$business = setting('business_name', 'Metro Valet Parking');

function mv_inv_badge2(string $status): string
{
    $color = match ($status) {
        'paid' => 'green',
        'partially_paid' => 'amber',
        'pending' => 'navy',
        'overdue' => 'red',
        'unpaid' => 'gray',
        'refunded' => 'navy',
        'voided' => 'gray',
        default => 'gray',
    };
    return '<span class="badge badge-' . $color . '">' . status_dot($color, ucfirst(str_replace('_', ' ', $status))) . '</span>';
}

require __DIR__ . '/_layout.php';
?>

<div class="page-head no-print">
    <h1>Invoice <span class="mono"><?= e($inv['number']) ?></span></h1>
    <div class="page-actions">
        <?php if (!$edit_mode): ?>
            <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">Print / PDF</button>
            <a class="btn btn-outline btn-sm" href="<?= e(site_url('admin/invoice_pdf.php?id=' . (int) $inv['id'])) ?>">Download PDF</a>
            <button type="button" class="btn btn-outline btn-sm" data-copy="<?= e($pay_url) ?>">Copy pay link</button>
        <?php endif; ?>
        <?php if ($can_edit && !$edit_mode && !in_array($inv['status'], ['voided', 'refunded'], true)): ?>
            <form method="post" action="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'])) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="send_invoice">
                <button type="submit" class="btn btn-outline btn-sm" data-confirm="Email invoice <?= e($inv['number']) ?> with the secure pay link?">Send invoice</button>
            </form>
        <?php endif; ?>
        <?php if (can('payments.record') && !$edit_mode && $inv['status'] === 'paid'): ?>
            <form method="post" action="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'])) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="send_receipt">
                <button type="submit" class="btn btn-outline btn-sm">Send paid receipt</button>
            </form>
        <?php endif; ?>
        <?php if (can('payments.record') && !$edit_mode && $balance > 0 && ($inv['stripe_session_id'] ?? '') !== '' && !in_array($inv['status'], ['voided', 'refunded', 'paid'], true)): ?>
            <form method="post" action="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'])) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="complete_payment">
                <button type="submit" class="btn btn-primary btn-sm" data-confirm="Verify this payment with Stripe and finalize it? This only completes payments Stripe confirms as paid.">Complete payment</button>
            </form>
        <?php endif; ?>
        <?php if (can('payments.refund') && !$edit_mode && in_array($inv['status'], ['paid', 'partially_paid'], true)): ?>
            <form method="post" action="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'])) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="refund">
                <button type="submit" class="btn btn-outline btn-sm" data-confirm="Issue a Stripe refund for this invoice's card payment? This cannot be undone.">Refund</button>
            </form>
        <?php endif; ?>
        <?php if ($can_edit && !$edit_mode && !in_array($inv['status'], ['voided', 'refunded'], true)): ?>
            <a class="btn btn-outline btn-sm" href="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'] . '&edit=1')) ?>">Edit invoice</a>
        <?php endif; ?>
        <?php if ($can_edit && $edit_mode): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'])) ?>">Cancel editing</a>
        <?php endif; ?>
        <?php if (can('payments.record') && $balance > 0 && !in_array($inv['status'], ['voided', 'refunded'], true)): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(site_url('admin/payments.php?invoice=' . (int) $inv['id'])) ?>">Record payment</a>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/invoices.php')) ?>">Back</a>
    </div>
</div>

<?php if ($edit_mode): ?>
<div class="card">
    <div class="section-title" style="margin-top:0"><h2>Edit invoice <span class="mono"><?= e($inv['number']) ?></span></h2></div>
    <?php if ($payments !== []): ?>
        <div class="flash flash-warning" role="alert"><strong>Heads up:</strong> this invoice already has recorded payments (<?= e(money((int) $inv['amount_paid_cents'])) ?>). Changing the items changes the total and the balance due — the recorded payments stay as they are.</div>
    <?php endif; ?>
    <form method="post" action="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'] . '&edit=1')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save">
        <div class="grid-2">
            <div class="field">
                <label for="ed_due">Due date</label>
                <input type="date" id="ed_due" name="due_date" value="<?= e($inv['due_date'] ?: '') ?>">
            </div>
            <div class="field">
                <label for="ed_notes">Notes</label>
                <input type="text" id="ed_notes" name="notes" value="<?= e($inv['notes'] ?: '') ?>" maxlength="255">
            </div>
        </div>
        <h3>Line items</h3>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Description</th><th style="width:90px">Qty</th><th style="width:140px">Unit price ($)</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td><input type="text" name="item_desc[]" value="<?= e($it['description']) ?>" maxlength="255" style="width:100%"></td>
                            <td><input type="number" name="item_qty[]" value="<?= (int) $it['qty'] ?>" min="0" step="1" style="width:100%"></td>
                            <td><input type="number" name="item_unit[]" value="<?= e(number_format((int) $it['unit_cents'] / 100, 2, '.', '')) ?>" min="0" step="0.01" style="width:100%"></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php for ($bi = 0; $bi < 2; $bi++): ?>
                        <tr>
                            <td><input type="text" name="item_desc[]" value="" maxlength="255" placeholder="New item…" style="width:100%"></td>
                            <td><input type="number" name="item_qty[]" value="" min="0" step="1" placeholder="1" style="width:100%"></td>
                            <td><input type="number" name="item_unit[]" value="" min="0" step="0.01" placeholder="0.00" style="width:100%"></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">Leave a blank row empty to ignore it. The total is recalculated from the items, and the status is re-derived from recorded payments.</p>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a class="btn btn-ghost" href="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'])) ?>">Cancel</a>
        </div>
    </form>
    <hr>
    <div class="btn-row">
        <?php if ($inv['status'] === 'voided'): ?>
            <form method="post" action="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'] . '&edit=1')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="restore">
                <button type="submit" class="btn btn-outline btn-sm">Restore invoice</button>
            </form>
        <?php elseif ($inv['status'] !== 'refunded'): ?>
            <form method="post" action="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'] . '&edit=1')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="void">
                <button type="submit" class="btn btn-outline btn-sm" data-confirm="Void invoice <?= e($inv['number']) ?>? It stays in history but can no longer be paid.">Void invoice</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="section-title" style="margin-top:0">
        <h2><?= e($business) ?></h2>
        <?= mv_inv_badge2($inv['status']) ?>
    </div>
    <div class="grid-2">
        <div>
            <h3>Bill to</h3>
            <p><strong><?= e($inv['customer_name'] ?: $inv['company_name'] ?: '—') ?></strong><br>
                <span class="muted"><?= e($inv['customer_email'] ?: $inv['billing_email'] ?: '') ?></span>
                <?php if ($inv['location_name']): ?><br><span class="muted">Location: <?= e($inv['location_name']) ?></span><?php endif; ?>
            </p>
        </div>
        <div>
            <dl class="dl">
                <dt>Invoice #</dt><dd class="mono"><?= e($inv['number']) ?></dd>
                <dt>Issue date</dt><dd><?= e(fmt_date($inv['issue_date'])) ?></dd>
                <dt>Due date</dt><dd><?= e(fmt_date($inv['due_date'])) ?></dd>
                <?php if ($inv['created_by_name']): ?><dt>Created by</dt><dd><?= e($inv['created_by_name']) ?></dd><?php endif; ?>
                <?php if ($inv['notes']): ?><dt>Notes</dt><dd><?= e($inv['notes']) ?></dd><?php endif; ?>
            </dl>
        </div>
    </div>

    <h3>Items</h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= e($it['description']) ?></td>
                        <td><?= (int) $it['qty'] ?></td>
                        <td><?= e(money((int) $it['unit_cents'])) ?></td>
                        <td><?= e(money((int) $it['amount_cents'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="totals">
        <table>
            <tr><td>Total</td><td><?= e(money((int) $inv['amount_cents'])) ?></td></tr>
            <tr><td>Paid</td><td><?= e(money((int) $inv['amount_paid_cents'])) ?></td></tr>
            <tr class="grand"><td>Balance due</td><td><?= e(money($balance)) ?></td></tr>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="section-title" style="margin-top:0"><h2>Payments</h2></div>
    <?php if ($payments === []): ?>
        <div class="empty"><strong>No payments recorded</strong></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded by</th><th>Notes</th></tr></thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="nowrap"><?= e(fmt_date($p['paid_at'])) ?></td>
                            <td><?= e(money((int) $p['amount_cents'])) ?></td>
                            <td><?= e(ucfirst($p['method'])) ?></td>
                            <td class="mono small"><?= e($p['reference'] ?: '—') ?></td>
                            <td><?= e($p['recorded_by_name'] ?: ($p['recorded_by_customer'] ? 'Customer (portal)' : '—')) ?></td>
                            <td class="small"><?= e($p['notes'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card no-print">
    <h3>Customer pay link</h3>
    <p class="muted small">Share this secure link so the customer can pay online. It contains a random token and reveals nothing else.</p>
    <p><code class="mono"><?= e($pay_url) ?></code></p>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
