<?php
require __DIR__ . '/../lib/bootstrap.php';

$auth     = require_customer();
$scope    = $_SESSION['customer_scope'] ?? 'customer';
$scope_id = (int)($_SESSION['customer_scope_id'] ?? 0);
$login_id = (int)($_SESSION['customer_id'] ?? 0);
$customer = $auth;
$pdo      = db();

if ($scope === 'company' && $scope_id > 0) {
    $f_veh = 'v.company_id = :oid';
    $oid   = $scope_id;
} else {
    $f_veh = 'v.customer_id = :oid';
    $oid   = $login_id;
}

/* ---------- Vehicle change request (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'vehicle_change') {
    verify_csrf(); /* exits 403 on mismatch */
    $vid    = (int)($_POST['vehicle_id'] ?? 0);
    $make   = trim((string)($_POST['make'] ?? ''));
    $model  = trim((string)($_POST['model'] ?? ''));
    $plate  = trim((string)($_POST['plate'] ?? ''));
    $pstate = strtoupper(trim((string)($_POST['plate_state'] ?? '')));
    $reason = trim((string)($_POST['reason'] ?? ''));

    $errs = array();
    if ($vid <= 0)          { $errs[] = 'Please choose the vehicle you want to change.'; }
    if ($plate === '')      { $errs[] = 'The new license plate is required.'; }
    if (strlen($reason) < 5){ $errs[] = 'Please give a short reason for the change.'; }

    /* Verify ownership of the vehicle. */
    $veh = null;
    if (empty($errs)) {
        $st = $pdo->prepare("SELECT * FROM vehicles v WHERE v.id = :id AND $f_veh LIMIT 1");
        $st->execute(array(':id' => $vid, ':oid' => $oid));
        $veh = $st->fetch(PDO::FETCH_ASSOC);
        if (!$veh) { $errs[] = 'Vehicle not found.'; }
    }

    /* Normalized plate + duplicate check within this account's scope. */
    $plate_norm = '';
    if (empty($errs)) {
        $plate_norm = normalize_plate($plate);
        $st = $pdo->prepare("SELECT id FROM vehicles v WHERE v.plate_norm = :pn AND v.id <> :id AND $f_veh LIMIT 1");
        $st->execute(array(':pn' => $plate_norm, ':id' => $vid, ':oid' => $oid));
        if ($st->fetch(PDO::FETCH_ASSOC)) {
            $errs[] = 'That plate is already registered on another vehicle in your account.';
        }
    }

    if (!empty($errs)) {
        flash(implode(' ', $errs), 'error');
        redirect(site_url('portal/vehicles.php'));
    }

    $admin_email = (string)(setting('admin_email') ?: setting('business_email'));
    $who         = (string)($customer['name'] ?? 'Customer');
    $old_label   = trim((string)($veh['make'] ?? '') . ' ' . (string)($veh['model'] ?? ''));
    $new_label   = trim($make . ' ' . $model);
    $vars = array(
        'customer_name'   => $who,
        'customer_email'  => (string)($customer['email'] ?? ''),
        'customer_phone'  => (string)($customer['phone'] ?? ''),
        'vehicle_id'      => (string)$veh['id'],
        'old_vehicle'     => $old_label !== '' ? $old_label : 'Vehicle #' . $veh['id'],
        'old_plate'       => (string)($veh['plate'] ?? ''),
        'new_make'        => $make,
        'new_model'       => $model,
        'new_plate'       => $plate,
        'new_plate_state' => $pstate,
        'reason'          => $reason,
    );
    $sent = false;
    if ($admin_email !== '') {
        try {
            $sent = (bool)send_template('vehicle_change_received', $admin_email, $vars);
        } catch (Throwable $e) {
            $sent = false;
        }
    }
    audit('vehicle_change_request', 'vehicles', (int)$veh['id'], array(
        'new_plate' => $plate, 'new_plate_state' => $pstate, 'reason' => $reason,
    ));
    flash(
        $sent
            ? 'Request received. Our team will review the vehicle change and confirm by email.'
            : 'Request recorded. Our team will review the vehicle change and confirm by email.',
        $sent ? 'success' : 'info'
    );
    redirect(site_url('portal/vehicles.php'));
}

/* ---------- Vehicle list + permit status ---------- */
$today = today();
$st = $pdo->prepare(
    "SELECT v.*, MAX(s.end_date) AS permit_end
     FROM vehicles v
     LEFT JOIN subscriptions s ON s.vehicle_id = v.id AND s.status = 'active' AND s.end_date >= :today
     WHERE $f_veh
     GROUP BY v.id
     ORDER BY v.id DESC"
);
$st->execute(array(':today' => $today, ':oid' => $oid));
$vehicles = $st->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Vehicles';
$active     = 'vehicles';
include __DIR__ . '/_layout.php';
?>

<h1>Vehicles</h1>
<p class="p-lead">Vehicles registered to your account and their permit status.</p>

<div class="p-card">
  <h2>Your vehicles</h2>
  <?php if (empty($vehicles)): ?>
    <div class="p-empty">No vehicles registered yet.<br>
      <span class="p-muted">When our team registers your vehicle, it will appear here.</span></div>
  <?php else: ?>
    <div class="p-tablewrap">
      <table class="p-table">
        <thead><tr><th>Vehicle</th><th>Plate</th><th>Color</th><th>Permit status</th></tr></thead>
        <tbody>
          <?php foreach ($vehicles as $v):
              $label = trim((string)($v['make'] ?? '') . ' ' . (string)($v['model'] ?? ''));
              $permit_end = $v['permit_end'] ?? null;
          ?>
          <tr>
            <td><strong><?= e($label !== '' ? $label : 'Vehicle #' . (int)$v['id']) ?></strong></td>
            <td><?= e((string)($v['plate'] ?? '')) ?> <?= e((string)($v['plate_state'] ?? '')) ?></td>
            <td><?= e((string)($v['color'] ?? '—')) ?></td>
            <td>
              <?php if ($permit_end): ?>
                <?php $b = days_badge((string)$permit_end); $d = (int)($b['days'] ?? 0); ?>
                <span class="badge <?= $d <= 7 ? 'b-amber' : 'b-green' ?>">
                  Active &middot; <?= e((string)($b['label'] ?? '')) ?>
                </span>
              <?php else: ?>
                <span class="badge b-gray">No active permit</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="p-card">
  <h2>Request a vehicle change</h2>
  <p class="p-muted">Selling a car or driving something new? Send us the replacement vehicle details and we&rsquo;ll update your permit. Changes take effect only after our team approves them.</p>
  <?php if (empty($vehicles)): ?>
    <div class="p-empty">You need a registered vehicle before you can request a change.</div>
  <?php else: ?>
    <form method="post" action="<?= e(site_url('portal/vehicles.php')) ?>">
      <?php $cf = csrf_field(); if (is_string($cf)) { echo $cf; } ?>
      <input type="hidden" name="action" value="vehicle_change">
      <div class="p-form-grid">
        <div class="p-field">
          <label for="vehicle_id">Vehicle to replace</label>
          <select id="vehicle_id" name="vehicle_id" required>
            <?php foreach ($vehicles as $v):
                $label = trim((string)($v['make'] ?? '') . ' ' . (string)($v['model'] ?? ''));
                if ($label === '') { $label = 'Vehicle #' . (int)$v['id']; }
            ?>
              <option value="<?= (int)$v['id'] ?>">
                <?= e($label . ' — ' . (string)($v['plate'] ?? '') . ' ' . (string)($v['plate_state'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="p-field">
          <label for="reason">Reason for change</label>
          <input type="text" id="reason" name="reason" required maxlength="255" placeholder="e.g. Bought a new car">
        </div>
        <div class="p-field">
          <label for="make">New vehicle make</label>
          <input type="text" id="make" name="make" maxlength="80" placeholder="e.g. Toyota">
        </div>
        <div class="p-field">
          <label for="model">New vehicle model</label>
          <input type="text" id="model" name="model" maxlength="80" placeholder="e.g. Camry">
        </div>
        <div class="p-field">
          <label for="plate">New license plate</label>
          <input type="text" id="plate" name="plate" required maxlength="20" placeholder="e.g. ABC1234">
        </div>
        <div class="p-field">
          <label for="plate_state">Plate state</label>
          <input type="text" id="plate_state" name="plate_state" maxlength="4" placeholder="e.g. MI">
        </div>
      </div>
      <button class="btn btn-navy" type="submit">Send change request</button>
    </form>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
