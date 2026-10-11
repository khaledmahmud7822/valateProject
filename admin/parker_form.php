<?php
// admin/parker_form.php — create/edit a monthly parker: customer + vehicle + subscription in one form.
// Modes: new | ?subscription_id= (edit all three) | ?customer_id= (customer + vehicle management).
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Parker';
$nav_section = 'parkers';
$required_perm = 'parkers.edit';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$today = today();

$subscription_id = (int) ($_GET['subscription_id'] ?? 0);
$customer_id = (int) ($_GET['customer_id'] ?? 0);

$sub = null;
$customer = null;
$vehicle = null;
$mode = 'new';

if ($subscription_id > 0) {
    $st = $db->prepare('SELECT * FROM subscriptions WHERE id = ?');
    $st->execute([$subscription_id]);
    $sub = $st->fetch(PDO::FETCH_ASSOC);
    if ($sub) {
        $mode = 'edit_sub';
        $customer_id = (int) $sub['customer_id'];
    }
}
if ($customer_id > 0 && !$customer) {
    $st = $db->prepare('SELECT * FROM customers WHERE id = ?');
    $st->execute([$customer_id]);
    $customer = $st->fetch(PDO::FETCH_ASSOC);
    if ($customer && $mode === 'new') {
        $mode = 'edit_customer';
    }
}
if ($sub && $sub['vehicle_id']) {
    $st = $db->prepare('SELECT * FROM vehicles WHERE id = ?');
    $st->execute([$sub['vehicle_id']]);
    $vehicle = $st->fetch(PDO::FETCH_ASSOC);
}

if (($subscription_id > 0 && !$sub) || ($customer_id > 0 && !$customer)) {
    flash('Record not found.', 'error');
    redirect(site_url('admin/parkers.php'));
}
if ($mode === 'edit_sub' && !$customer && !empty($sub['company_id'])) {
    // Corporate subscriptions are managed from the company record.
    flash('This is a corporate subscription — manage it from the company record.', 'info');
    redirect(site_url('admin/company_form.php?id=' . (int) $sub['company_id']));
}

$page_title = $mode === 'new' ? 'Add Parker' : 'Edit Parker';

$locations = $db->query('SELECT id, name, monthly_rate_cents FROM parking_locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// ---------- Vehicle add / remove (customer mode) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vehicle_op'])) {
    verify_csrf();
    {
        $op = (string) $_POST['vehicle_op'];
        if ($op === 'add' && $customer) {
            $make = trim((string) ($_POST['v_make'] ?? ''));
            $model = trim((string) ($_POST['v_model'] ?? ''));
            $plate = trim((string) ($_POST['v_plate'] ?? ''));
            $plate_state = trim((string) ($_POST['v_plate_state'] ?? ''));
            $color = trim((string) ($_POST['v_color'] ?? ''));
            $tag_number = trim((string) ($_POST['v_tag_number'] ?? ''));
            if ($plate === '') {
                flash('License plate is required.', 'error');
            } else {
                $norm = normalize_plate($plate);
                $dup = $db->prepare('SELECT id FROM vehicles WHERE plate_norm = ? AND plate_norm != \'\' LIMIT 1');
                $dup->execute([$norm]);
                if ($dup->fetchColumn()) {
                    flash('A vehicle with that plate is already registered.', 'error');
                } else {
                    $db->prepare('INSERT INTO vehicles (customer_id, make, model, plate, plate_state, plate_norm, color, tag_number, created_at) VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')
                        ->execute([$customer['id'], $make, $model, strtoupper($plate), $plate_state, $norm, $color, $tag_number ?? null]);
                    $vid = (int) $db->lastInsertId();
                    audit('vehicle.created', 'vehicle', $vid, ['customer_id' => $customer['id'], 'plate' => $plate]);
                    flash('Vehicle added.', 'success');
                }
            }
        } elseif ($op === 'remove' && $customer) {
            $vid = (int) ($_POST['vehicle_id'] ?? 0);
            $vs = $db->prepare('SELECT * FROM vehicles WHERE id = ? AND customer_id = ?');
            $vs->execute([$vid, $customer['id']]);
            $vv = $vs->fetch(PDO::FETCH_ASSOC);
            if (!$vv) {
                flash('Vehicle not found.', 'error');
            } else {
                $sc = $db->prepare('SELECT COUNT(*) FROM subscriptions WHERE vehicle_id = ?');
                $sc->execute([$vid]);
                $pc = $db->prepare('SELECT COUNT(*) FROM permits WHERE vehicle_id = ?');
                $pc->execute([$vid]);
                if ((int) $sc->fetchColumn() > 0 || (int) $pc->fetchColumn() > 0) {
                    flash('Cannot remove this vehicle: it has subscriptions or permits on record. Financial history is never deleted.', 'error');
                } else {
                    $db->prepare('DELETE FROM vehicles WHERE id = ?')->execute([$vid]);
                    audit('vehicle.deleted', 'vehicle', $vid, ['plate' => $vv['plate']]);
                    flash('Vehicle removed.', 'success');
                }
            }
        }
    }
    redirect(site_url('admin/parker_form.php?customer_id=' . $customer['id']));
}

// ---------- Main save ----------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['vehicle_op'])) {
    verify_csrf();
    {
        // Customer fields.
        $c = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'contact_name' => trim((string) ($_POST['contact_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'portal_password' => (string) ($_POST['portal_password'] ?? ''),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'city' => trim((string) ($_POST['city'] ?? '')),
            'state' => trim((string) ($_POST['state'] ?? '')),
            'zip' => trim((string) ($_POST['zip'] ?? '')),
            'location_id' => (int) ($_POST['customer_location_id'] ?? 0) ?: null,
            'status' => in_array($_POST['status'] ?? '', ['active', 'inactive', 'archived'], true) ? $_POST['status'] : 'active',
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];
        // Vehicle fields.
        $v = [
            'make' => trim((string) ($_POST['make'] ?? '')),
            'model' => trim((string) ($_POST['model'] ?? '')),
            'plate' => trim((string) ($_POST['plate'] ?? '')),
            'plate_state' => trim((string) ($_POST['plate_state'] ?? '')),
            'color' => trim((string) ($_POST['color'] ?? '')),
            'tag_number' => trim((string) ($_POST['tag_number'] ?? '')),
        ];
        // Subscription fields.
        $s = [
            'location_id' => (int) ($_POST['location_id'] ?? 0),
            'start_date' => trim((string) ($_POST['start_date'] ?? '')),
            'end_date' => trim((string) ($_POST['end_date'] ?? '')),
            'rate_dollars' => trim((string) ($_POST['rate_dollars'] ?? '')),
            'status' => in_array($_POST['sub_status'] ?? '', ['active', 'expired', 'suspended', 'cancelled'], true) ? $_POST['sub_status'] : 'active',
            'auto_renew' => isset($_POST['auto_renew']) ? 1 : 0,
            'hours_allowed' => trim((string) ($_POST['hours_allowed'] ?? '')),
            'category' => in_array($_POST['category'] ?? '', ['monthly', 'resident'], true) ? $_POST['category'] : 'monthly',
            'spaces' => trim((string) ($_POST['spaces'] ?? '')),
            'sub_notes' => trim((string) ($_POST['sub_notes'] ?? '')),
        ];

        if ($c['name'] === '') {
            $errors[] = 'Customer name is required.';
        }
        if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is not valid.';
        }
        if ($s['location_id'] <= 0) {
            $errors[] = 'Choose a parking location.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s['start_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s['end_date'])) {
            $errors[] = 'Start and end dates are required (YYYY-MM-DD).';
        } elseif ($s['end_date'] < $s['start_date']) {
            $errors[] = 'End date cannot be before the start date.';
        }
        $rate_cents = null;
        if ($s['rate_dollars'] !== '') {
            if (!is_numeric($s['rate_dollars']) || (float) $s['rate_dollars'] < 0) {
                $errors[] = 'Monthly rate must be a non-negative number.';
            } else {
                $rate_cents = (int) round((float) $s['rate_dollars'] * 100);
            }
        }
        $plate_norm = $v['plate'] !== '' ? normalize_plate($v['plate']) : '';

        if ($errors === []) {
            $db->beginTransaction();
            try {
                if ($mode === 'new') {
                    // Duplicate plate guard.
                    if ($plate_norm !== '') {
                        $dup = $db->prepare('SELECT id FROM vehicles WHERE plate_norm = ? LIMIT 1');
                        $dup->execute([$plate_norm]);
                        if ($dup->fetchColumn()) {
                            throw new Exception('A vehicle with that plate is already registered.');
                        }
                    }
                    $db->prepare(
                        'INSERT INTO customers (type, name, contact_name, email, phone, location_id, address, city, state, zip, status, notes, created_at)
                         VALUES (\'individual\',?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
                    )->execute([$c['name'], $c['contact_name'] ?: $c['name'], $c['email'], $c['phone'], $c['location_id'], $c['address'], $c['city'], $c['state'], $c['zip'], $c['status'], $c['notes']]);
                    $new_cid = (int) $db->lastInsertId();
                    if ($c['portal_password'] !== '') {
                        $db->prepare('UPDATE customers SET password_hash = ? WHERE id = ?')
                           ->execute([password_hash($c['portal_password'], PASSWORD_DEFAULT), $new_cid]);
                    }
                    // Welcome email with set-password link (only if they have an email and no manual password was set).
                    if ($c['email'] !== '' && filter_var($c['email'], FILTER_VALIDATE_EMAIL) && $c['portal_password'] === '') {
                        $wtoken = bin2hex(random_bytes(32));
                        $db->prepare('INSERT INTO customer_resets (email, token_hash, kind, expires_at, created_at) VALUES (?,?,?,?,?)')
                           ->execute([strtolower($c['email']), hash('sha256', $wtoken), 'customer',
                                      date('Y-m-d H:i:s', time() + 72 * 3600), date('Y-m-d H:i:s')]);
                        $wlink = site_url('portal/reset.php?token=' . $wtoken);
                        try {
                            send_mail_raw($c['email'], 'Welcome to Metro Valet Parking — set up your portal access',
                                '<p>Hi ' . htmlspecialchars($c['name']) . ',</p>'
                                . '<p>Your monthly parking account with Metro Valet Parking is ready. Set up your customer portal password to view invoices, make payments, and manage your vehicles online:</p>'
                                . '<p><a href="' . htmlspecialchars($wlink) . '" style="display:inline-block;padding:12px 28px;background:#0B1B30;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold">Set My Password</a></p>'
                                . '<p style="color:#666;font-size:.9em">This link expires in 72 hours. Your login email is ' . htmlspecialchars($c['email']) . '.</p>'
                                . '<p>— Metro Valet Parking<br>(855)-442-6500</p>');
                        } catch (Throwable $e) {}
                    }
                    $cid = (int) $db->lastInsertId();

                    $vid = null;
                    if ($v['plate'] !== '' || $v['make'] !== '' || $v['model'] !== '') {
                        $db->prepare('INSERT INTO vehicles (customer_id, make, model, plate, plate_state, plate_norm, color, tag_number, created_at) VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')
                            ->execute([$cid, $v['make'], $v['model'], strtoupper($v['plate']), $v['plate_state'], $plate_norm, $v['color']]);
                        $vid = (int) $db->lastInsertId();
                    }

                    $db->prepare(
                        'INSERT INTO subscriptions (customer_id, location_id, vehicle_id, start_date, end_date, monthly_rate_cents, status, auto_renew, hours_allowed, spaces, category, notes, created_at, updated_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)'
                    )->execute([$cid, $s['location_id'], $vid, $s['start_date'], $s['end_date'], $rate_cents, $s['status'], $s['auto_renew'], $s['hours_allowed'] ?: null, $s['spaces'] !== '' ? (int) $s['spaces'] : null, $s['category'], $s['sub_notes']]);
                    $sid = (int) $db->lastInsertId();

                    if ($vid) {
                        $db->prepare('INSERT INTO permits (vehicle_id, customer_id, location_id, start_date, end_date, status, created_at) VALUES (?,?,?,?,?,\'active\',CURRENT_TIMESTAMP)')
                            ->execute([$vid, $cid, $s['location_id'], $s['start_date'], $s['end_date']]);
                    }
                    // Auto-generate the first invoice — no manual invoice needed.
                    $new_invoice_id = null;
                    if ($rate_cents !== null && $rate_cents > 0) {
                        $loc_st = $db->prepare('SELECT name FROM parking_locations WHERE id = ?');
                        $loc_st->execute([$s['location_id']]);
                        $new_invoice_id = create_invoice_for_subscription($db, [
                            'id' => $sid,
                            'customer_id' => $cid,
                            'company_id' => null,
                            'customer_name' => $c['name'],
                            'company_name' => null,
                            'location_name' => $loc_st->fetchColumn() ?: '',
                            'start_date' => $s['start_date'],
                            'end_date' => $s['end_date'],
                        ], $rate_cents, $today, date('Y-m-d', strtotime($today . ' +14 days')), (int) $user['id'], 'First invoice — parker created');
                    }
                    $db->commit();
                    audit('customer.created', 'customer', $cid, ['name' => $c['name']]);
                    audit('subscription.created', 'subscription', $sid, ['customer_id' => $cid]);
                    if ($new_invoice_id) {
                        flash('Parker created with subscription. Invoice auto-generated.', 'success');
                        redirect(site_url('admin/invoice_view.php?id=' . $new_invoice_id));
                    }
                    flash('Parker created with subscription. (No rate set — create the invoice manually when ready.)', 'success');
                    redirect(site_url('admin/parker_form.php?subscription_id=' . $sid));
                } else {
                    // Edit existing customer (+ vehicle + subscription where applicable).
                    $db->prepare('UPDATE customers SET name=?, contact_name=?, email=?, phone=?, location_id=?, address=?, city=?, state=?, zip=?, status=?, notes=? WHERE id=?')
                        ->execute([$c['name'], $c['contact_name'] ?: $c['name'], $c['email'], $c['phone'], $c['location_id'], $c['address'], $c['city'], $c['state'], $c['zip'], $c['status'], $c['notes'], $customer['id']]);
                    if ($c['portal_password'] !== '') {
                        $db->prepare('UPDATE customers SET password_hash = ? WHERE id = ?')
                           ->execute([password_hash($c['portal_password'], PASSWORD_DEFAULT), $customer['id']]);
                    }
                    audit('customer.updated', 'customer', $customer['id']);

                    if ($mode === 'edit_sub') {
                        if ($vehicle) {
                            if ($plate_norm !== '' && $plate_norm !== $vehicle['plate_norm']) {
                                $dup = $db->prepare('SELECT id FROM vehicles WHERE plate_norm = ? AND id != ? LIMIT 1');
                                $dup->execute([$plate_norm, $vehicle['id']]);
                                if ($dup->fetchColumn()) {
                                    throw new Exception('A vehicle with that plate is already registered.');
                                }
                            }
                            $db->prepare('UPDATE vehicles SET make=?, model=?, plate=?, plate_state=?, plate_norm=?, color=?, tag_number=? WHERE id=?')
                                ->execute([$v['make'], $v['model'], strtoupper($v['plate']), $v['plate_state'], $plate_norm, $v['color'], $v['tag_number'] ?: null, $vehicle['id']]);
                            audit('vehicle.updated', 'vehicle', $vehicle['id']);
                        } elseif ($v['plate'] !== '' || $v['make'] !== '') {
                            $db->prepare('INSERT INTO vehicles (customer_id, make, model, plate, plate_state, plate_norm, color, tag_number, created_at) VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')
                                ->execute([$customer['id'], $v['make'], $v['model'], strtoupper($v['plate']), $v['plate_state'], $plate_norm, $v['color']]);
                            $new_vid = (int) $db->lastInsertId();
                            $db->prepare('UPDATE subscriptions SET vehicle_id = ? WHERE id = ?')->execute([$new_vid, $sub['id']]);
                            audit('vehicle.created', 'vehicle', $new_vid, ['subscription_id' => $sub['id']]);
                        }
                        $db->prepare('UPDATE subscriptions SET location_id=?, start_date=?, end_date=?, monthly_rate_cents=?, status=?, auto_renew=?, hours_allowed=?, spaces=?, category=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
                            ->execute([$s['location_id'], $s['start_date'], $s['end_date'], $rate_cents, $s['status'], $s['auto_renew'], $s['hours_allowed'] ?: null, $s['spaces'] !== '' ? (int) $s['spaces'] : null, $s['category'], $s['sub_notes'], $sub['id']]);
                        audit('subscription.updated', 'subscription', $sub['id']);
                    }
                    $db->commit();
                    flash('Parker updated.', 'success');
                    redirect(site_url('admin/parker_form.php?' . ($mode === 'edit_sub' ? 'subscription_id=' . $sub['id'] : 'customer_id=' . $customer['id'])));
                }
            } catch (Exception $ex) {
                $db->rollBack();
                $errors[] = 'Save failed: ' . $ex->getMessage();
            }
        }
    }
}

// Defaults for the form.
$f = [
    'name' => $customer['name'] ?? '', 'contact_name' => $customer['contact_name'] ?? '',
    'email' => $customer['email'] ?? '', 'phone' => $customer['phone'] ?? '',
    'has_portal' => !empty($customer['password_hash']),
    'address' => $customer['address'] ?? '', 'city' => $customer['city'] ?? '',
    'state' => $customer['state'] ?? '', 'zip' => $customer['zip'] ?? '',
    'customer_location_id' => $customer['location_id'] ?? 0,
    'status' => $customer['status'] ?? 'active', 'notes' => $customer['notes'] ?? '',
    'make' => $vehicle['make'] ?? '', 'model' => $vehicle['model'] ?? '',
    'plate' => $vehicle['plate'] ?? '', 'plate_state' => $vehicle['plate_state'] ?? '', 'color' => $vehicle['color'] ?? '',
    'tag_number' => $vehicle['tag_number'] ?? '',
    'location_id' => $sub['location_id'] ?? 0,
    'start_date' => $sub['start_date'] ?? $today,
    'end_date' => $sub['end_date'] ?? date('Y-m-d', strtotime($today . ' +1 month')),
    'rate_dollars' => isset($sub['monthly_rate_cents']) && $sub['monthly_rate_cents'] !== null ? number_format(((int) $sub['monthly_rate_cents']) / 100, 2, '.', '') : '',
    'hours_allowed' => $sub['hours_allowed'] ?? '',
    'spaces' => $sub['spaces'] ?? '',
    'category' => $sub['category'] ?? 'monthly',
    'sub_status' => $sub['status'] ?? 'active', 'auto_renew' => $sub ? (int) $sub['auto_renew'] : 1,
    'sub_notes' => $sub['notes'] ?? '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['vehicle_op']) && $errors !== []) {
    // Repopulate from the failed POST.
    foreach (['name', 'contact_name', 'email', 'phone', 'address', 'city', 'state', 'zip', 'notes', 'make', 'model', 'plate', 'plate_state', 'color', 'tag_number', 'start_date', 'end_date', 'rate_dollars', 'hours_allowed', 'spaces', 'sub_notes'] as $k) {
        $f[$k] = trim((string) ($_POST[$k] ?? $f[$k]));
    }
    $f['customer_location_id'] = (int) ($_POST['customer_location_id'] ?? 0);
    $f['location_id'] = (int) ($_POST['location_id'] ?? 0);
    $f['status'] = (string) ($_POST['status'] ?? 'active');
    $f['sub_status'] = (string) ($_POST['sub_status'] ?? 'active');
    $f['auto_renew'] = isset($_POST['auto_renew']) ? 1 : 0;
}

// Customer-mode extras: vehicles + subscriptions.
$cust_vehicles = [];
$cust_subs = [];
if ($customer && $mode === 'edit_customer') {
    $vs = $db->prepare('SELECT * FROM vehicles WHERE customer_id = ? ORDER BY id');
    $vs->execute([$customer['id']]);
    $cust_vehicles = $vs->fetchAll(PDO::FETCH_ASSOC);
    $ss = $db->prepare('SELECT s.*, l.name AS location_name FROM subscriptions s LEFT JOIN parking_locations l ON l.id = s.location_id WHERE s.customer_id = ? ORDER BY s.end_date DESC');
    $ss->execute([$customer['id']]);
    $cust_subs = $ss->fetchAll(PDO::FETCH_ASSOC);
}

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1><?= $mode === 'new' ? 'Add Parker' : 'Edit Parker' ?></h1>
    <div class="page-actions"><a class="btn btn-outline" href="<?= e(site_url('admin/parkers.php')) ?>">Back to list</a></div>
</div>

<?php foreach ($errors as $er): ?>
    <div class="flash flash-error"><?= e($er) ?></div>
<?php endforeach; ?>

<form method="post" class="card">
    <?= csrf_field() ?>
    <h3>Customer</h3>
    <div class="form-grid">
        <div class="field"><label for="name">Full name *</label><input type="text" id="name" name="name" value="<?= e($f['name']) ?>" required maxlength="200"></div>
        <div class="field"><label for="contact_name">Contact name</label><input type="text" id="contact_name" name="contact_name" value="<?= e($f['contact_name']) ?>" maxlength="200"><div class="hint">Defaults to full name.</div></div>
        <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($f['email']) ?>" maxlength="200"></div>
        <div class="field"><label for="phone">Phone</label><input type="tel" id="phone" name="phone" value="<?= e($f['phone']) ?>" maxlength="60"></div>
        <div class="field full" style="background:#f0f7ff;border:1px solid #bee3f8;border-radius:8px;padding:1rem">
            <label for="portal_password" style="font-weight:700">🔑 Customer Portal Access</label>
            <?php if ($f['has_portal']): ?>
                <div class="hint" style="color:#2b6cb0;margin-bottom:.5rem">✓ Portal login is active for this customer.</div>
            <?php endif; ?>
            <input type="text" id="portal_password" name="portal_password" value="" maxlength="100" autocomplete="new-password"
                   placeholder="<?= $f['has_portal'] ? 'Enter a new password to change it (leave blank to keep current)' : 'Set a portal password to enable customer login' ?>">
            <div class="hint">Customer logs in at <?= e(site_url('portal/login.php')) ?> with their email + this password. Leave blank to keep unchanged.</div>
        </div>
        <div class="field full"><label for="address">Address</label><input type="text" id="address" name="address" value="<?= e($f['address']) ?>" maxlength="255"></div>
        <div class="field"><label for="city">City</label><input type="text" id="city" name="city" value="<?= e($f['city']) ?>" maxlength="120"></div>
        <div class="field"><label for="zip">ZIP</label><input type="text" id="zip" name="zip" value="<?= e($f['zip']) ?>" maxlength="20"></div>
        <div class="field"><label for="state">State</label><input type="text" id="state" name="state" value="<?= e($f['state']) ?>" maxlength="40"></div>
        <div class="field"><label for="customer_location_id">Home location</label>
            <select id="customer_location_id" name="customer_location_id">
                <option value="0">—</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= (int) $l['id'] ?>"<?= (int) $f['customer_location_id'] === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label for="status">Customer status</label>
            <select id="status" name="status">
                <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $k => $lbl): ?>
                    <option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
            <div class="hint">Archiving hides the customer from active lists; history is kept.</div>
        </div>
        <div class="field full"><label for="notes">Notes</label><textarea id="notes" name="notes" style="min-height:70px"><?= e($f['notes']) ?></textarea></div>
    </div>

    <?php if ($mode !== 'edit_customer'): ?>
        <hr class="hr-gold">
        <h3>Vehicle<?= $mode === 'new' ? '' : ' (on this subscription)' ?></h3>
        <div class="form-grid">
            <div class="field"><label for="make">Make</label><input type="text" id="make" name="make" value="<?= e($f['make']) ?>" maxlength="80"></div>
            <div class="field"><label for="model">Model</label><input type="text" id="model" name="model" value="<?= e($f['model']) ?>" maxlength="80"></div>
            <div class="field"><label for="plate">License plate</label><input type="text" id="plate" name="plate" value="<?= e($f['plate']) ?>" maxlength="20" style="text-transform:uppercase"></div>
            <div class="field"><label for="plate_state">Plate state</label><input type="text" id="plate_state" name="plate_state" value="<?= e($f['plate_state']) ?>" maxlength="10" placeholder="MI"></div>
            <div class="field"><label for="color">Color</label><input type="text" id="color" name="color" value="<?= e($f['color']) ?>" maxlength="40"></div>
            <div class="field"><label for="tag_number">Tag #</label><input type="text" id="tag_number" name="tag_number" value="<?= e($f['tag_number']) ?>" maxlength="40"></div>
        </div>

        <hr class="hr-gold">
        <h3>Subscription</h3>
        <div class="form-grid">
            <div class="field"><label for="location_id">Location *</label>
                <select id="location_id" name="location_id" required>
                    <option value="0">Choose…</option>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= (int) $l['id'] ?>"<?= (int) $f['location_id'] === (int) $l['id'] ? ' selected' : '' ?>>
                            <?= e($l['name']) ?><?= $l['monthly_rate_cents'] !== null ? ' — ' . e(money((int) $l['monthly_rate_cents'])) . '/mo' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label for="rate_dollars">Monthly rate ($)</label>
                <input type="number" id="rate_dollars" name="rate_dollars" value="<?= e($f['rate_dollars']) ?>" min="0" step="0.01">
                <div class="hint">Leave blank for "Contact for rates".</div>
            </div>
            <div class="field"><label for="start_date">Start date *</label><input type="date" id="start_date" name="start_date" value="<?= e($f['start_date']) ?>" required></div>
            <div class="field"><label for="end_date">End date *</label><input type="date" id="end_date" name="end_date" value="<?= e($f['end_date']) ?>" required></div>
            <div class="field"><label for="hours_allowed">Hours allowed</label><input type="text" id="hours_allowed" name="hours_allowed" value="<?= e($f['hours_allowed']) ?>" maxlength="120" placeholder="e.g. 24/7, Resident, Mon–Fri 7am–7pm"></div>
            <div class="field"><label for="spaces"># of spaces</label><input type="number" id="spaces" name="spaces" value="<?= e($f['spaces']) ?>" min="1" step="1"></div>
            <div class="field"><label for="category">Parker category</label>
                <select id="category" name="category">
                    <option value="monthly"<?= $f['category'] === 'monthly' ? ' selected' : '' ?>>Monthly</option>
                    <option value="resident"<?= $f['category'] === 'resident' ? ' selected' : '' ?>>Resident</option>
                </select>
            </div>
            <div class="field"><label for="sub_status">Subscription status</label>
                <select id="sub_status" name="sub_status">
                    <?php foreach (['active' => 'Active', 'expired' => 'Expired', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'] as $k => $lbl): ?>
                        <option value="<?= $k ?>"<?= $f['sub_status'] === $k ? ' selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label class="check-line" style="margin-top:28px"><input type="checkbox" name="auto_renew" value="1"<?= $f['auto_renew'] ? ' checked' : '' ?>> Auto-renew</label></div>
            <div class="field full"><label for="sub_notes">Subscription notes</label><textarea id="sub_notes" name="sub_notes" style="min-height:70px"><?= e($f['sub_notes']) ?></textarea></div>
        </div>
    <?php endif; ?>

    <div class="btn-row">
        <button type="submit" class="btn btn-primary"><?= $mode === 'new' ? 'Create parker' : 'Save changes' ?></button>
        <a class="btn btn-ghost" href="<?= e(site_url('admin/parkers.php')) ?>">Cancel</a>
    </div>
</form>

<?php if ($mode === 'edit_customer'): ?>
    <div class="section-title"><h2>Vehicles</h2></div>
    <div class="card">
        <?php if ($cust_vehicles === []): ?>
            <div class="empty"><strong>No vehicles</strong>Add the customer's first vehicle below.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Plate</th><th>Vehicle</th><th>Color</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($cust_vehicles as $vv): ?>
                            <tr>
                                <td><strong class="mono"><?= e($vv['plate']) ?></strong> <span class="muted small"><?= e($vv['plate_state']) ?></span></td>
                                <td><?= e(trim(($vv['make'] ?? '') . ' ' . ($vv['model'] ?? ''))) ?></td>
                                <td><?= e($vv['color'] ?: '—') ?></td>
                                <td>
                                    <form method="post" class="inline-form" data-confirm="Remove this vehicle? Only allowed when it has no subscriptions or permits.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="vehicle_op" value="remove">
                                        <input type="hidden" name="vehicle_id" value="<?= (int) $vv['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <h3>Add vehicle</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="vehicle_op" value="add">
            <div class="form-grid">
                <div class="field"><label for="v_make">Make</label><input type="text" id="v_make" name="v_make" maxlength="80"></div>
                <div class="field"><label for="v_model">Model</label><input type="text" id="v_model" name="v_model" maxlength="80"></div>
                <div class="field"><label for="v_plate">License plate *</label><input type="text" id="v_plate" name="v_plate" required maxlength="20" style="text-transform:uppercase"></div>
                <div class="field"><label for="v_plate_state">Plate state</label><input type="text" id="v_plate_state" name="v_plate_state" maxlength="10"></div>
                <div class="field"><label for="v_color">Color</label><input type="text" id="v_color" name="v_color" maxlength="40"></div>
                <div class="field"><label for="v_tag_number">Tag #</label><input type="text" id="v_tag_number" name="v_tag_number" maxlength="40"></div>
            </div>
            <div class="btn-row"><button type="submit" class="btn btn-outline">Add vehicle</button></div>
        </form>
    </div>

    <div class="section-title"><h2>Subscriptions</h2></div>
    <?php if ($cust_subs === []): ?>
        <div class="empty"><strong>No subscriptions</strong></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Location</th><th>Start</th><th>End</th><th>Days left</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($cust_subs as $ss): ?>
                        <?php $b = days_badge($ss['end_date']); ?>
                        <tr>
                            <td><?= e($ss['location_name'] ?: '—') ?></td>
                            <td><?= e(fmt_date($ss['start_date'])) ?></td>
                            <td><?= e(fmt_date($ss['end_date'])) ?></td>
                            <td><span class="badge badge-<?= e($b['color']) ?>"><?= status_dot($b['color'], $b['label']) ?></span></td>
                            <td><?= e(ucfirst($ss['status'])) ?></td>
                            <td><a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/parker_form.php?subscription_id=' . (int) $ss['id'])) ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
