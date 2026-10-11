<?php
require __DIR__ . '/../lib/bootstrap.php';

$auth     = require_customer();
$scope    = $_SESSION['customer_scope'] ?? 'customer';
$scope_id = (int)($_SESSION['customer_scope_id'] ?? 0);
$login_id = (int)($_SESSION['customer_id'] ?? 0);
$table = (($auth['kind'] ?? 'customer') === 'company_contact') ? 'company_contacts' : 'customers';
$pdo = db();

/* Load the fresh row for whichever table the session came from. */
$st = $pdo->prepare("SELECT * FROM $table WHERE id = ? LIMIT 1");
$st->execute(array($login_id));
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    redirect(site_url('portal/logout.php'));
}
$customer = $row;

/* ---------- Update contact info ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_contact') {
    verify_csrf(); /* exits 403 on mismatch */
    $name  = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $errs  = array();
    if ($name === '')  { $errs[] = 'Name is required.'; }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errs[] = 'A valid email address is required.'; }

    /* Email must be unique across both login tables. */
    if (empty($errs)) {
        $st = $pdo->prepare('SELECT id FROM customers WHERE LOWER(email) = ? AND id <> ? LIMIT 1');
        $st->execute(array($email, $table === 'customers' ? $login_id : -1));
        $dup = $st->fetch(PDO::FETCH_ASSOC);
        if (!$dup) {
            $st = $pdo->prepare('SELECT id FROM company_contacts WHERE LOWER(email) = ? AND id <> ? LIMIT 1');
            $st->execute(array($email, $table === 'company_contacts' ? $login_id : -1));
            $dup = $st->fetch(PDO::FETCH_ASSOC);
        }
        if ($dup) { $errs[] = 'That email address is already in use.'; }
    }

    if (!empty($errs)) {
        flash(implode(' ', $errs), 'error');
        redirect(site_url('portal/profile.php'));
    }

    if ($table === 'customers') {
        $contact_name = trim((string)($_POST['contact_name'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $city    = trim((string)($_POST['city'] ?? ''));
        $state   = trim((string)($_POST['state'] ?? ''));
        $zip     = trim((string)($_POST['zip'] ?? ''));
        $st = $pdo->prepare(
            'UPDATE customers SET name = ?, contact_name = ?, email = ?, phone = ?,
                                  address = ?, city = ?, state = ?, zip = ? WHERE id = ?'
        );
        $st->execute(array($name, $contact_name, $email, $phone, $address, $city, $state, $zip, $login_id));
        audit('profile_update', 'customers', $login_id, array('email' => $email));
    } else {
        $st = $pdo->prepare('UPDATE company_contacts SET name = ?, email = ?, phone = ? WHERE id = ?');
        $st->execute(array($name, $email, $phone, $login_id));
        audit('profile_update', 'company_contacts', $login_id, array('email' => $email));
    }
    flash('Contact information updated.', 'success');
    redirect(site_url('portal/profile.php'));
}

/* ---------- Change password ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'change_password') {
    verify_csrf(); /* exits 403 on mismatch */
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $errs    = array();
    if (!password_verify($current, (string)($row['password_hash'] ?? ''))) {
        $errs[] = 'Your current password is incorrect.';
    }
    if (strlen($new) < 10) {
        $errs[] = 'The new password must be at least 10 characters.';
    }
    if ($new !== $confirm) {
        $errs[] = 'The new passwords do not match.';
    }
    if (!empty($errs)) {
        flash(implode(' ', $errs), 'error');
        redirect(site_url('portal/profile.php'));
    }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $st = $pdo->prepare("UPDATE $table SET password_hash = ? WHERE id = ?");
    $st->execute(array($hash, $login_id));
    audit('password_change', $table, $login_id, array());
    flash('Password changed successfully.', 'success');
    redirect(site_url('portal/profile.php'));
}

$page_title = 'Profile';
$active     = 'profile';
include __DIR__ . '/_layout.php';
?>

<h1>Profile</h1>
<p class="p-lead">Keep your contact details up to date so we can reach you about your account.</p>

<div class="p-card">
  <h2>Account</h2>
  <dl class="p-kv">
    <dt>Account type</dt><dd><?= e($scope === 'company' ? 'Company account' : 'Customer') ?></dd>
    <?php if ($scope === 'company' && $scope_id > 0):
        $st = $pdo->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
        $st->execute(array($scope_id));
        $co = $st->fetch(PDO::FETCH_ASSOC);
    ?>
      <dt>Company</dt><dd><?= e((string)($co['name'] ?? '')) ?></dd>
    <?php endif; ?>
    <dt>Member since</dt><dd><?= e(fmt_date(substr((string)($row['created_at'] ?? ''), 0, 10))) ?></dd>
  </dl>
  <p class="p-muted">Subscription locations, dates, and billing amounts are managed by our team — contact support if anything needs to change.</p>
</div>

<div class="p-card">
  <h2>Contact information</h2>
  <form method="post" action="<?= e(site_url('portal/profile.php')) ?>">
    <?php $cf = csrf_field(); if (is_string($cf)) { echo $cf; } ?>
    <input type="hidden" name="action" value="update_contact">
    <div class="p-form-grid">
      <div class="p-field">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" required maxlength="190" value="<?= e((string)($row['name'] ?? '')) ?>">
      </div>
      <?php if ($table === 'customers'): ?>
      <div class="p-field">
        <label for="contact_name">Contact name <span class="p-muted">(if different)</span></label>
        <input type="text" id="contact_name" name="contact_name" maxlength="190" value="<?= e((string)($row['contact_name'] ?? '')) ?>">
      </div>
      <?php endif; ?>
      <div class="p-field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required maxlength="190" value="<?= e((string)($row['email'] ?? '')) ?>">
      </div>
      <div class="p-field">
        <label for="phone">Phone</label>
        <input type="tel" id="phone" name="phone" maxlength="40" value="<?= e((string)($row['phone'] ?? '')) ?>">
      </div>
      <?php if ($table === 'customers'): ?>
      <div class="p-field">
        <label for="address">Street address</label>
        <input type="text" id="address" name="address" maxlength="255" value="<?= e((string)($row['address'] ?? '')) ?>">
      </div>
      <div class="p-field">
        <label for="city">City</label>
        <input type="text" id="city" name="city" maxlength="120" value="<?= e((string)($row['city'] ?? '')) ?>">
      </div>
      <div class="p-field">
        <label for="state">State</label>
        <input type="text" id="state" name="state" maxlength="40" value="<?= e((string)($row['state'] ?? '')) ?>">
      </div>
      <div class="p-field">
        <label for="zip">ZIP</label>
        <input type="text" id="zip" name="zip" maxlength="20" value="<?= e((string)($row['zip'] ?? '')) ?>">
      </div>
      <?php endif; ?>
    </div>
    <button class="btn btn-navy" type="submit">Save changes</button>
  </form>
</div>

<div class="p-card">
  <h2>Change password</h2>
  <form method="post" action="<?= e(site_url('portal/profile.php')) ?>" autocomplete="off">
    <?php $cf = csrf_field(); if (is_string($cf)) { echo $cf; } ?>
    <input type="hidden" name="action" value="change_password">
    <div class="p-field">
      <label for="current_password">Current password</label>
      <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
    </div>
    <div class="p-form-grid">
      <div class="p-field">
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
        <div class="hint">At least 10 characters.</div>
      </div>
      <div class="p-field">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
      </div>
    </div>
    <button class="btn btn-navy" type="submit">Change password</button>
  </form>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
