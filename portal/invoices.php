<?php
require __DIR__ . '/../lib/bootstrap.php';

$auth     = require_customer();
$scope    = $_SESSION['customer_scope'] ?? 'customer';
$scope_id = (int)($_SESSION['customer_scope_id'] ?? 0);
$login_id = (int)($_SESSION['customer_id'] ?? 0);
$customer = $auth;
$pdo      = db();

if ($scope === 'company' && $scope_id > 0) {
    $f_inv = 'i.company_id = :oid';
    $oid   = $scope_id;
} else {
    $f_inv = 'i.customer_id = :oid';
    $oid   = $login_id;
}

$st = $pdo->prepare(
    "SELECT * FROM invoices i WHERE $f_inv ORDER BY i.issue_date DESC, i.id DESC"
);
$st->execute(array(':oid' => $oid));
$invoices = $st->fetchAll(PDO::FETCH_ASSOC);

/* Inline detail view (?view=id), scope-checked. */
$detail = null;
$detail_items = array();
$detail_payments = array();
$view = (int)($_GET['view'] ?? 0);
if ($view > 0) {
    $st = $pdo->prepare("SELECT * FROM invoices i WHERE i.id = :id AND $f_inv LIMIT 1");
    $st->execute(array(':id' => $view, ':oid' => $oid));
    $detail = $st->fetch(PDO::FETCH_ASSOC);
    if ($detail) {
        $st = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC');
        $st->execute(array($detail['id']));
        $detail_items = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY paid_at DESC, id DESC');
        $st->execute(array($detail['id']));
        $detail_payments = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

$page_title = 'Invoices';
$active     = 'invoices';
include __DIR__ . '/_layout.php';
?>
<style>
@media print {
  body.portal .p-top, body.portal .p-footer, .no-print { display:none !important; }
  body.portal{background:#fff;}
  .p-main{max-width:none;padding:0;}
  .p-card{box-shadow:none;border:none;margin:0;padding:0;}
}
</style>

<h1>Invoices</h1>
<p class="p-lead">Every invoice for your account. Open one to see line items and payments, or print a receipt.</p>

<?php if ($detail): ?>
  <?php
    $dbal = (int)$detail['amount_cents'] - (int)$detail['amount_paid_cents'];
    if ($dbal < 0) { $dbal = 0; }
  ?>
  <div class="p-card" id="invoice-detail">
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
      <h2>Invoice <?= e((string)$detail['number']) ?></h2>
      <?= portal_inv_badge((string)$detail['status']) ?>
    </div>
    <dl class="p-kv">
      <dt>Issue date</dt><dd><?= e(fmt_date((string)$detail['issue_date'])) ?></dd>
      <dt>Due date</dt><dd><?= e(fmt_date((string)$detail['due_date'])) ?></dd>
      <dt>Amount</dt><dd><?= e(money((int)$detail['amount_cents'])) ?></dd>
      <dt>Paid to date</dt><dd><?= e(money((int)$detail['amount_paid_cents'])) ?></dd>
      <dt>Balance due</dt><dd><strong><?= e(money($dbal)) ?></strong></dd>
      <?php if (!empty($detail['notes'])): ?>
        <dt>Notes</dt><dd><?= e((string)$detail['notes']) ?></dd>
      <?php endif; ?>
    </dl>

    <h3>Line items</h3>
    <?php if (empty($detail_items)): ?>
      <p class="p-muted">No line items recorded.</p>
    <?php else: ?>
      <div class="p-tablewrap">
        <table class="p-table">
          <thead><tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Amount</th></tr></thead>
          <tbody>
            <?php foreach ($detail_items as $it): ?>
            <tr>
              <td><?= e((string)$it['description']) ?></td>
              <td><?= e((string)$it['qty']) ?></td>
              <td><?= e(money((int)$it['unit_cents'])) ?></td>
              <td><?= e(money((int)$it['amount_cents'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <h3>Payments</h3>
    <?php if (empty($detail_payments)): ?>
      <p class="p-muted">No payments recorded yet.</p>
    <?php else: ?>
      <div class="p-tablewrap">
        <table class="p-table">
          <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($detail_payments as $p): ?>
            <tr>
              <td><?= e(fmt_date(substr((string)$p['paid_at'], 0, 10))) ?></td>
              <td><?= e(ucfirst((string)$p['method'])) ?></td>
              <td><?= $p['reference'] ? e((string)$p['reference']) : '<span class="p-muted">—</span>' ?></td>
              <td><?= e(money((int)$p['amount_cents'])) ?></td>
              <td>
                <?php if (stripos((string)($p['notes'] ?? ''), 'pending verification') !== false): ?>
                  <span class="badge b-amber">Pending verification</span>
                <?php else: ?>
                  <span class="badge b-green">Recorded</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="p-actions no-print">
      <?php if ($dbal > 0 && !in_array($detail['status'], array('voided', 'refunded'), true)): ?>
        <a class="btn btn-gold"
           href="<?= e(site_url('portal/pay.php') . '?token=' . urlencode((string)$detail['payment_token'])) ?>">Pay <?= e(money($dbal)) ?></a>
      <?php endif; ?>
      <button class="btn btn-outline" type="button" onclick="window.print()">Download / print receipt</button>
      <a class="btn btn-outline" href="<?= e(site_url('portal/invoices.php')) ?>">Close detail</a>
    </div>
  </div>
<?php elseif ($view > 0): ?>
  <div class="p-card"><div class="p-empty">Invoice not found.</div></div>
<?php endif; ?>

<div class="p-card">
  <h2>All invoices</h2>
  <?php if (empty($invoices)): ?>
    <div class="p-empty">No invoices yet. When our team bills your account, invoices will appear here.</div>
  <?php else: ?>
    <div class="p-tablewrap">
      <table class="p-table">
        <thead><tr>
          <th>Invoice</th><th>Issued</th><th>Due</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($invoices as $inv):
              $bal = (int)$inv['amount_cents'] - (int)$inv['amount_paid_cents'];
              if ($bal < 0) { $bal = 0; }
              $payable = $bal > 0 && !in_array($inv['status'], array('paid', 'voided', 'refunded'), true);
          ?>
          <tr>
            <td><strong><?= e((string)$inv['number']) ?></strong></td>
            <td><?= e(fmt_date((string)$inv['issue_date'])) ?></td>
            <td><?= e(fmt_date((string)$inv['due_date'])) ?></td>
            <td><?= e(money((int)$inv['amount_cents'])) ?></td>
            <td><?= e(money((int)$inv['amount_paid_cents'])) ?></td>
            <td><strong><?= e(money($bal)) ?></strong></td>
            <td><?= portal_inv_badge((string)$inv['status']) ?></td>
            <td style="white-space:nowrap;">
              <a class="btn btn-outline btn-sm" href="<?= e(site_url('portal/invoices.php') . '?view=' . (int)$inv['id']) ?>">View</a>
              <?php if ($payable): ?>
                <a class="btn btn-gold btn-sm"
                   href="<?= e(site_url('portal/pay.php') . '?token=' . urlencode((string)$inv['payment_token'])) ?>">Pay</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
