<?php
// admin/payments.php — record payments (transaction + recalc) and list payment history.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Payments';
$nav_section = 'payments';
$required_perm = 'payments.record';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$today = today();

$preset_invoice = (int) ($_GET['invoice'] ?? 0);

// ---------- Record payment ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
    $amount_d = trim((string) ($_POST['amount'] ?? ''));
    $method = in_array($_POST['method'] ?? '', ['cash', 'check', 'ach', 'card', 'other'], true) ? $_POST['method'] : 'other';
    $reference = trim((string) ($_POST['reference'] ?? ''));
    $paid_at = trim((string) ($_POST['paid_at'] ?? $today));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $send_receipt = isset($_POST['send_receipt']);

    $st = $db->prepare(
        'SELECT i.*, c.name AS customer_name, c.email AS customer_email, co.name AS company_name, co.billing_email
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         LEFT JOIN companies co ON co.id = i.company_id
         WHERE i.id = ?'
    );
    $st->execute([$invoice_id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);

    $errs = [];
    if (!$inv) {
        $errs[] = 'Choose a valid invoice.';
    } elseif (in_array($inv['status'], ['voided', 'refunded'], true)) {
        $errs[] = 'Payments cannot be recorded on a ' . $inv['status'] . ' invoice.';
    }
    if ($amount_d === '' || !is_numeric($amount_d) || (float) $amount_d <= 0) {
        $errs[] = 'Amount must be greater than zero.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_at)) {
        $errs[] = 'Payment date must be YYYY-MM-DD.';
    }
    $amount_cents = (int) round((float) $amount_d * 100);
    if ($inv && $amount_cents > ((int) $inv['amount_cents'] - (int) $inv['amount_paid_cents'])) {
        $errs[] = 'Amount exceeds the invoice balance of ' . money((int) $inv['amount_cents'] - (int) $inv['amount_paid_cents']) . '.';
    }

    if ($errs === []) {
        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO payments (invoice_id, amount_cents, method, reference, paid_at, recorded_by, notes, created_at)
                 VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
            )->execute([$invoice_id, $amount_cents, $method, $reference, $paid_at, $user['id'], $notes]);
            $pay_id = (int) $db->lastInsertId();
            // Invoice status recalculated from SUM(payments) — never trusted from input.
            $new_status = recalc_invoice($invoice_id);
            $db->commit();
            audit('payment.recorded', 'payment', $pay_id, [
                'invoice_id' => $invoice_id,
                'amount_cents' => $amount_cents, 'method' => $method, 'new_status' => $new_status,
            ]);
            if ($send_receipt) {
                try {
                    if (!send_paid_invoice_email($invoice_id, 'manual' . $pay_id)) {
                        flash('Payment recorded, but the receipt email failed (no valid customer email).', 'warning');
                    }
                } catch (Exception $ex) {
                    flash('Payment recorded, but the receipt email failed.', 'warning');
                }
            }
            flash('Payment of ' . money($amount_cents) . ' recorded. Invoice is now ' . str_replace('_', ' ', (string) $new_status) . '.', 'success');
            redirect(site_url('admin/invoice_view.php?id=' . $invoice_id));
        } catch (Exception $ex) {
            $db->rollBack();
            flash('Payment failed: ' . $ex->getMessage(), 'error');
            redirect(site_url('admin/payments.php'));
        }
    } else {
        foreach ($errs as $er) {
            flash($er, 'error');
        }
        redirect(site_url('admin/payments.php' . ($invoice_id ? '?invoice=' . $invoice_id : '')));
    }
}

// ---------- List ----------
$search = trim((string) ($_GET['q'] ?? ''));
$f_method = (string) ($_GET['method'] ?? '');
$f_from = trim((string) ($_GET['from'] ?? ''));
$f_to = trim((string) ($_GET['to'] ?? ''));
$f_invoice = (int) ($_GET['invoice'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(i.number LIKE ? OR p.reference LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if (in_array($f_method, ['cash', 'check', 'ach', 'card', 'other'], true)) {
    $where[] = 'p.method = ?';
    $params[] = $f_method;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) {
    $where[] = 'p.paid_at >= ?';
    $params[] = $f_from;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to)) {
    $where[] = 'p.paid_at <= ?';
    $params[] = $f_to;
}
if ($f_invoice > 0) {
    $where[] = 'p.invoice_id = ?';
    $params[] = $f_invoice;
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_st = $db->prepare("SELECT COUNT(*) FROM payments p JOIN invoices i ON i.id = p.invoice_id $sql_where");
$count_st->execute($params);
$total = (int) $count_st->fetchColumn();

$sum_st = $db->prepare("SELECT COALESCE(SUM(p.amount_cents),0) FROM payments p JOIN invoices i ON i.id = p.invoice_id $sql_where");
$sum_st->execute($params);
$total_cents = (int) $sum_st->fetchColumn();

$offset = ($page - 1) * $per;
$st = $db->prepare(
    "SELECT p.*, i.number AS invoice_number, i.status AS invoice_status,
            c.name AS customer_name, co.name AS company_name, u.name AS recorded_by_name
     FROM payments p
     JOIN invoices i ON i.id = p.invoice_id
     LEFT JOIN customers c ON c.id = i.customer_id
     LEFT JOIN companies co ON co.id = i.company_id
     LEFT JOIN users u ON u.id = p.recorded_by
     $sql_where
     ORDER BY p.paid_at DESC, p.id DESC
     LIMIT $per OFFSET $offset"
);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Open invoices for the record-payment select.
$open = $db->query(
    "SELECT i.id, i.number, i.amount_cents, i.amount_paid_cents, c.name AS customer_name, co.name AS company_name
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     LEFT JOIN companies co ON co.id = i.company_id
     WHERE i.status IN ('unpaid','pending','partially_paid','overdue')
     ORDER BY i.due_date ASC, i.id DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

$base = site_url('admin/payments.php'); // paginate() preserves the current GET filters itself

require __DIR__ . '/_layout.php';
?>

<div class="page-head"><h1>Payments</h1></div>

<div class="card">
    <h3>Record a payment</h3>
    <?php if ($open === []): ?>
        <div class="empty"><strong>No open invoices</strong>There is nothing to record a payment against.</div>
    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="field full">
                    <label for="invoice_id">Invoice *</label>
                    <select id="invoice_id" name="invoice_id" required>
                        <option value="">Choose…</option>
                        <?php foreach ($open as $o): ?>
                            <?php $bal = (int) $o['amount_cents'] - (int) $o['amount_paid_cents']; ?>
                            <option value="<?= (int) $o['id'] ?>"<?= $preset_invoice === (int) $o['id'] ? ' selected' : '' ?>>
                                <?= e($o['number']) ?> — <?= e($o['customer_name'] ?: $o['company_name']) ?> — balance <?= e(money($bal)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="amount">Amount ($) *</label><input type="number" id="amount" name="amount" min="0.01" step="0.01" required></div>
                <div class="field"><label for="method">Method *</label>
                    <select id="method" name="method" required>
                        <?php foreach (['cash' => 'Cash', 'check' => 'Check', 'ach' => 'ACH / bank transfer', 'card' => 'Card', 'other' => 'Other'] as $k => $lbl): ?>
                            <option value="<?= $k ?>"><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="paid_at">Payment date *</label><input type="date" id="paid_at" name="paid_at" value="<?= e($today) ?>" required></div>
                <div class="field"><label for="reference">Reference</label><input type="text" id="reference" name="reference" maxlength="120" placeholder="Check #, auth code…"></div>
                <div class="field full"><label for="notes">Notes</label><input type="text" id="notes" name="notes" maxlength="500"></div>
                <div class="field full"><label class="check-line"><input type="checkbox" name="send_receipt" value="1" checked> Email receipt to customer</label></div>
            </div>
            <p class="muted small">Recorded by <?= e($user['name']) ?>. Partial payments are supported; the invoice status is recalculated from total payments.</p>
            <div class="btn-row"><button type="submit" class="btn btn-primary">Record payment</button></div>
        </form>
    <?php endif; ?>
</div>

<div class="section-title"><h2>Payment history</h2>
    <span class="badge badge-navy">Total: <?= e(money($total_cents)) ?></span>
</div>

<form method="get" action="<?= e(site_url('admin/payments.php')) ?>" class="filter-bar">
    <div class="field">
        <label for="fq">Search</label>
        <input type="text" id="fq" name="q" value="<?= e($search) ?>" placeholder="Invoice # or reference…">
    </div>
    <div class="field narrow"><label for="fmethod">Method</label>
        <select id="fmethod" name="method">
            <option value="">All</option>
            <?php foreach (['cash' => 'Cash', 'check' => 'Check', 'ach' => 'ACH', 'card' => 'Card', 'other' => 'Other'] as $k => $lbl): ?>
                <option value="<?= $k ?>"<?= $f_method === $k ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field narrow"><label for="ffrom">From</label><input type="date" id="ffrom" name="from" value="<?= e($f_from) ?>"></div>
    <div class="field narrow"><label for="fto">To</label><input type="date" id="fto" name="to" value="<?= e($f_to) ?>"></div>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/payments.php')) ?>">Clear</a>
</form>

<?php if ($rows === []): ?>
    <div class="empty"><strong>No payments found</strong></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Date</th><th>Invoice</th><th>Bill to</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded by</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="nowrap"><?= e(fmt_date($r['paid_at'])) ?></td>
                        <td><a href="<?= e(site_url('admin/invoice_view.php?id=' . (int) $r['invoice_id'])) ?>" class="mono"><?= e($r['invoice_number']) ?></a></td>
                        <td><?= e($r['customer_name'] ?: $r['company_name'] ?: '—') ?></td>
                        <td><strong><?= e(money((int) $r['amount_cents'])) ?></strong></td>
                        <td><?= e(ucfirst($r['method'])) ?></td>
                        <td class="mono small"><?= e($r['reference'] ?: '—') ?></td>
                        <td><?= e($r['recorded_by_name'] ?: ($r['recorded_by_customer'] ? 'Customer (portal)' : '—')) ?></td>
                        <td><a class="btn btn-ghost btn-xs" href="<?= e(site_url('admin/invoice_view.php?id=' . (int) $r['invoice_id'])) ?>">Invoice</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginate($total, $per, $page, $base) ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
