<?php
require __DIR__ . '/../lib/bootstrap.php';

$auth     = require_customer();
$scope    = $_SESSION['customer_scope'] ?? 'customer';
$scope_id = (int)($_SESSION['customer_scope_id'] ?? 0);
$login_id = (int)($_SESSION['customer_id'] ?? 0);
$customer = $auth;
$pdo      = db();

if ($scope === 'company' && $scope_id > 0) {
    $f_sub = 's.company_id = :oid';
    $f_inv = 'i.company_id = :oid';
    $oid   = $scope_id;
} else {
    $f_sub = 's.customer_id = :oid';
    $f_inv = 'i.customer_id = :oid';
    $oid   = $login_id;
}

/* ---------- Renewal request (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'renewal_request') {
    verify_csrf(); /* exits 403 on mismatch */
    $sub_id = (int)($_POST['subscription_id'] ?? 0);
    $st = $pdo->prepare(
        "SELECT s.*, l.name AS location_name, v.plate, v.plate_state, v.make, v.model
         FROM subscriptions s
         LEFT JOIN parking_locations l ON l.id = s.location_id
         LEFT JOIN vehicles v ON v.id = s.vehicle_id
         WHERE s.id = :id AND $f_sub LIMIT 1"
    );
    $st->execute(array(':id' => $sub_id, ':oid' => $oid));
    $sub = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        flash('Subscription not found.', 'error');
        redirect(site_url('portal/index.php'));
    }

    $admin_email = (string)(setting('admin_email') ?: setting('business_email'));
    $who         = (string)($customer['name'] ?? 'Customer');
    $subject     = 'Renewal request — ' . $who . ' (subscription #' . $sub['id'] . ')';
    $body  = '<p>A customer has requested a subscription renewal from the portal.</p>';
    $body .= '<ul>';
    $body .= '<li><strong>Customer:</strong> ' . e($who) . ' (' . e((string)($customer['email'] ?? '')) . ')</li>';
    $body .= '<li><strong>Subscription #:</strong> ' . (int)$sub['id'] . '</li>';
    $body .= '<li><strong>Location:</strong> ' . e((string)($sub['location_name'] ?? '')) . '</li>';
    $body .= '<li><strong>Vehicle:</strong> ' . e(trim((string)($sub['make'] ?? '') . ' ' . (string)($sub['model'] ?? ''))) .
               ' — plate ' . e((string)($sub['plate'] ?? '')) . ' ' . e((string)($sub['plate_state'] ?? '')) . '</li>';
    $body .= '<li><strong>Current term:</strong> ' . e(fmt_date((string)$sub['start_date'])) . ' to ' .
               e(fmt_date((string)$sub['end_date'])) . '</li>';
    $body .= '</ul>';

    $sent = false;
    if ($admin_email !== '') {
        $sent = (bool)send_mail_raw($admin_email, $subject, $body);
    }
    try {
        $ins = $pdo->prepare(
            "INSERT INTO notification_logs (template_key, recipient, subject, body_hash, status, sent_at, dedupe_key)
             VALUES ('renewal_request', :r, :s, :h, :st, :t, :d)"
        );
        $ins->execute(array(
            ':r'  => $admin_email,
            ':s'  => $subject,
            ':h'  => sha1($body),
            ':st' => $sent ? 'sent' : 'failed',
            ':t'  => date('Y-m-d H:i:s'),
            ':d'  => 'renewal_request:' . $sub['id'] . ':' . $sub['end_date'],
        ));
    } catch (Throwable $e) {
        /* duplicate request — already logged; keep going */
    }
    audit('renewal_request', 'subscriptions', $sub_id, array('end_date' => $sub['end_date']));
    flash(
        $sent
            ? 'Renewal request sent. Our team will contact you shortly.'
            : 'Renewal request recorded. Our team will contact you shortly.',
        $sent ? 'success' : 'info'
    );
    redirect(site_url('portal/index.php'));
}

/* ---------- Dashboard data ---------- */
$st = $pdo->prepare(
    "SELECT s.*, l.name AS location_name, v.plate, v.plate_state, v.make, v.model
     FROM subscriptions s
     LEFT JOIN parking_locations l ON l.id = s.location_id
     LEFT JOIN vehicles v ON v.id = s.vehicle_id
     WHERE $f_sub AND s.status = 'active'
     ORDER BY s.end_date ASC"
);
$st->execute(array(':oid' => $oid));
$subs = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare(
    "SELECT * FROM invoices i
     WHERE $f_inv AND i.status IN ('unpaid','pending','partially_paid','overdue')
     ORDER BY i.due_date ASC"
);
$st->execute(array(':oid' => $oid));
$open_invoices = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare(
    "SELECT p.*, i.number AS invoice_number
     FROM payments p
     JOIN invoices i ON i.id = p.invoice_id
     WHERE $f_inv
     ORDER BY p.paid_at DESC, p.id DESC
     LIMIT 5"
);
$st->execute(array(':oid' => $oid));
$recent_payments = $st->fetchAll(PDO::FETCH_ASSOC);

function portal_days_class($days) {
    if ($days < 0) { return 'b-red'; }
    if ($days <= 7) { return 'b-amber'; }
    return 'b-green';
}

$page_title = 'Dashboard';
$active     = 'dashboard';
include __DIR__ . '/_layout.php';
?>

<h1>Welcome back, <?= e((string)($customer['name'] ?? 'Customer')) ?></h1>
<p class="p-lead">Your parking subscriptions, invoices, and recent payments at a glance.</p>

<div class="p-card">
  <h2>Active subscriptions</h2>
  <?php if (empty($subs)): ?>
    <div class="p-empty">You have no active subscriptions right now.<br>
      <span class="p-muted">When our team sets one up for you, it will appear here.</span></div>
  <?php else: ?>
    <div class="p-grid">
      <?php foreach ($subs as $s):
          $b    = days_badge((string)$s['end_date']);
          $days = (int)($b['days'] ?? 0);
          $blab = (string)($b['label'] ?? '');
          $veh  = trim((string)($s['make'] ?? '') . ' ' . (string)($s['model'] ?? ''));
      ?>
      <div class="p-card" style="margin:0;">
        <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">
          <h3 style="margin:0;"><?= e((string)($s['location_name'] ?? 'Location')) ?></h3>
          <span class="badge <?= e(portal_days_class($days)) ?>"><?= e($blab !== '' ? $blab : ($days . ' days left')) ?></span>
        </div>
        <dl class="p-kv" style="margin-top:10px;">
          <dt>Vehicle</dt>
          <dd><?= e($veh !== '' ? $veh : '—') ?><?= $s['plate'] ? ' · ' . e((string)$s['plate']) . ' ' . e((string)($s['plate_state'] ?? '')) : '' ?></dd>
          <dt>Term</dt>
          <dd><?= e(fmt_date((string)$s['start_date'])) ?> &rarr; <?= e(fmt_date((string)$s['end_date'])) ?></dd>
          <dt>Monthly rate</dt>
          <dd><?= e(money((int)$s['monthly_rate_cents'])) ?></dd>
          <?php if (!empty($s['auto_renew'])): ?>
            <dt>Auto-renew</dt><dd>On</dd>
          <?php endif; ?>
        </dl>
        <div class="p-actions">
          <form method="post" action="<?= e(site_url('portal/index.php')) ?>"
                onsubmit="return confirm('Send a renewal request for this subscription to our team?');">
            <?php $cf = csrf_field(); if (is_string($cf)) { echo $cf; } ?>
            <input type="hidden" name="action" value="renewal_request">
            <input type="hidden" name="subscription_id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-outline btn-sm" type="submit">Request renewal</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="p-card">
  <h2>Outstanding invoices</h2>
  <?php if (empty($open_invoices)): ?>
    <div class="p-empty">Nothing due right now. You&rsquo;re all caught up.</div>
  <?php else: ?>
    <div class="p-tablewrap">
      <table class="p-table">
        <thead><tr>
          <th>Invoice</th><th>Due date</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($open_invoices as $inv):
              $bal = (int)$inv['amount_cents'] - (int)$inv['amount_paid_cents'];
              if ($bal < 0) { $bal = 0; }
          ?>
          <tr>
            <td><strong><?= e((string)$inv['number']) ?></strong></td>
            <td><?= e(fmt_date((string)$inv['due_date'])) ?></td>
            <td><?= e(money((int)$inv['amount_cents'])) ?></td>
            <td><?= e(money((int)$inv['amount_paid_cents'])) ?></td>
            <td><strong><?= e(money($bal)) ?></strong></td>
            <td><?= portal_inv_badge((string)$inv['status']) ?></td>
            <td style="white-space:nowrap;">
              <a class="btn btn-gold btn-sm"
                 href="<?= e(site_url('portal/pay.php') . '?token=' . urlencode((string)$inv['payment_token'])) ?>">Pay</a>
              <a class="btn btn-outline btn-sm"
                 href="<?= e(site_url('portal/invoices.php') . '?view=' . (int)$inv['id']) ?>">View</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="p-card">
  <h2>Recent payments</h2>
  <?php if (empty($recent_payments)): ?>
    <div class="p-empty">No payments recorded yet.</div>
  <?php else: ?>
    <div class="p-tablewrap">
      <table class="p-table">
        <thead><tr>
          <th>Date</th><th>Invoice</th><th>Method</th><th>Amount</th><th>Reference</th>
        </tr></thead>
        <tbody>
          <?php foreach ($recent_payments as $p): ?>
          <tr>
            <td><?= e(fmt_date(substr((string)$p['paid_at'], 0, 10))) ?></td>
            <td><?= e((string)$p['invoice_number']) ?></td>
            <td><?= e(ucfirst((string)$p['method'])) ?></td>
            <td><?= e(money((int)$p['amount_cents'])) ?></td>
            <td><?= $p['reference'] ? e((string)$p['reference']) : '<span class="p-muted">—</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
