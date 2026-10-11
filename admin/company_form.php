<?php
// admin/company_form.php — corporate account: details, contacts, vehicles, invoices,
// consolidated invoicing.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Company';
$nav_section = 'companies';
$required_perm = 'companies.view';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$today = today();
$can_edit = can('companies.edit');
$can_invoice = can('invoices.edit');

$id = (int) ($_GET['id'] ?? 0);
$company = null;
if ($id > 0) {
    $st = $db->prepare('SELECT * FROM companies WHERE id = ?');
    $st->execute([$id]);
    $company = $st->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        flash('Company not found.', 'error');
        redirect(site_url('admin/companies.php'));
    }
}
$is_new = $company === null;
if ($is_new && !$can_edit) {
    flash('You do not have permission to add companies.', 'error');
    redirect(site_url('admin/companies.php'));
}
$page_title = $is_new ? 'Add Company' : 'Manage Company';

$locations = $db->query('SELECT id, name FROM parking_locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// ---------- POST handlers ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = (string) ($_POST['op'] ?? 'save');

    // ----- Save company -----
    if ($op === 'save' && $can_edit) {
        $f = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'contact_name' => trim((string) ($_POST['contact_name'] ?? '')),
            'billing_contact' => trim((string) ($_POST['billing_contact'] ?? '')),
            'billing_email' => trim((string) ($_POST['billing_email'] ?? '')),
            'billing_phone' => trim((string) ($_POST['billing_phone'] ?? '')),
            'billing_address' => trim((string) ($_POST['billing_address'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'location_id' => (int) ($_POST['location_id'] ?? 0) ?: null,
            'spaces' => trim((string) ($_POST['spaces'] ?? '')),
            'rate_dollars' => trim((string) ($_POST['rate_dollars'] ?? '')),
            'contract_start' => trim((string) ($_POST['contract_start'] ?? '')),
            'contract_end' => trim((string) ($_POST['contract_end'] ?? '')),
            'status' => in_array($_POST['status'] ?? '', ['active', 'inactive', 'prospect'], true) ? $_POST['status'] : 'active',
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];
        $errs = [];
        if ($f['name'] === '') {
            $errs[] = 'Company name is required.';
        }
        if ($f['billing_email'] !== '' && !filter_var($f['billing_email'], FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'Billing email is not valid.';
        }
        if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'Company email is not valid.';
        }
        $spaces = null;
        if ($f['spaces'] !== '') {
            if (!ctype_digit($f['spaces'])) {
                $errs[] = 'Spaces must be a whole number.';
            } else {
                $spaces = (int) $f['spaces'];
            }
        }
        $rate_cents = null;
        if ($f['rate_dollars'] !== '') {
            if (!is_numeric($f['rate_dollars']) || (float) $f['rate_dollars'] < 0) {
                $errs[] = 'Rate per space must be a non-negative number.';
            } else {
                $rate_cents = (int) round((float) $f['rate_dollars'] * 100);
            }
        }
        foreach (['contract_start' => $f['contract_start'], 'contract_end' => $f['contract_end']] as $k => $d) {
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $errs[] = 'Contract dates must be YYYY-MM-DD.';
            }
        }
        if ($errs === []) {
            $row = [$f['name'], $f['contact_name'], $f['billing_contact'], $f['billing_email'], $f['billing_phone'], $f['billing_address'], $f['phone'], $f['email'], $f['location_id'], $spaces, $rate_cents, $f['contract_start'] ?: null, $f['contract_end'] ?: null, $f['status'], $f['notes']];
            if ($is_new) {
                $db->prepare(
                    'INSERT INTO companies (name, contact_name, billing_contact, billing_email, billing_phone, billing_address, phone, email, location_id, spaces, rate_cents, contract_start, contract_end, status, notes, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
                )->execute($row);
                $id = (int) $db->lastInsertId();
                audit('company.created', 'company', $id, ['name' => $f['name']]);
                flash('Company created.', 'success');
            } else {
                $row[] = $id;
                $db->prepare(
                    'UPDATE companies SET name=?, contact_name=?, billing_contact=?, billing_email=?, billing_phone=?, billing_address=?, phone=?, email=?, location_id=?, spaces=?, rate_cents=?, contract_start=?, contract_end=?, status=?, notes=? WHERE id=?'
                )->execute($row);
                audit('company.updated', 'company', $id, ['name' => $f['name']]);
                flash('Company updated.', 'success');
            }
            redirect(site_url('admin/company_form.php?id=' . $id));
        } else {
            foreach ($errs as $er) {
                flash($er, 'error');
            }
            redirect(site_url('admin/company_form.php' . ($is_new ? '' : '?id=' . $id)));
        }
    }

    // ----- Contact add / delete -----
    if ($op === 'contact_add' && $can_edit && !$is_new) {
        $cn = trim((string) ($_POST['c_name'] ?? ''));
        $ce = trim((string) ($_POST['c_email'] ?? ''));
        $cp = trim((string) ($_POST['c_phone'] ?? ''));
        $cr = trim((string) ($_POST['c_role'] ?? ''));
        $pw = (string) ($_POST['c_password'] ?? '');
        if ($cn === '') {
            flash('Contact name is required.', 'error');
        } elseif ($ce !== '' && !filter_var($ce, FILTER_VALIDATE_EMAIL)) {
            flash('Contact email is not valid.', 'error');
        } else {
            $hash = $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : null;
            $db->prepare('INSERT INTO company_contacts (company_id, name, email, phone, role, password_hash) VALUES (?,?,?,?,?,?)')
                ->execute([$id, $cn, $ce, $cp, $cr, $hash]);
            $cid = (int) $db->lastInsertId();
            // Welcome email with set-password link (only if they have an email and no manual password was set).
            if ($ce !== '' && filter_var($ce, FILTER_VALIDATE_EMAIL) && $pw === '') {
                $wtoken = bin2hex(random_bytes(32));
                $db->prepare('INSERT INTO customer_resets (email, token_hash, kind, expires_at, created_at) VALUES (?,?,?,?,?)')
                   ->execute([strtolower($ce), hash('sha256', $wtoken), 'contact',
                              date('Y-m-d H:i:s', time() + 72 * 3600), date('Y-m-d H:i:s')]);
                $wlink = site_url('portal/reset.php?token=' . $wtoken);
                try {
                    send_mail_raw($ce, 'Welcome to Metro Valet Parking — set up your portal access',
                        '<p>Hi ' . htmlspecialchars($cn) . ',</p>'
                        . '<p>Your corporate parking account with Metro Valet Parking (' . htmlspecialchars($f['name'] ?? 'your company') . ') is ready. Set up your customer portal password to view invoices, make payments, and manage vehicles online:</p>'
                        . '<p><a href="' . htmlspecialchars($wlink) . '" style="display:inline-block;padding:12px 28px;background:#0B1B30;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold">Set My Password</a></p>'
                        . '<p style="color:#666;font-size:.9em">This link expires in 72 hours. Your login email is ' . htmlspecialchars($ce) . '.</p>'
                        . '<p>— Metro Valet Parking<br>(855)-442-6500</p>');
                } catch (Throwable $e) {}
            }
            audit('company_contact.created', 'company_contact', $cid, ['company_id' => $id, 'name' => $cn]);
            flash('Contact added.' . ($pw !== '' ? ' Portal password set.' : ''), 'success');
        }
        redirect(site_url('admin/company_form.php?id=' . $id));
    }
    if ($op === 'contact_delete' && $can_edit && !$is_new) {
        $cid = (int) ($_POST['contact_id'] ?? 0);
        $db->prepare('DELETE FROM company_contacts WHERE id = ? AND company_id = ?')->execute([$cid, $id]);
        audit('company_contact.deleted', 'company_contact', $cid, ['company_id' => $id]);
        flash('Contact removed.', 'success');
        redirect(site_url('admin/company_form.php?id=' . $id));
    }

    // ----- Vehicle add / remove -----
    if ($op === 'vehicle_add' && $can_edit && !$is_new) {
        $plate = trim((string) ($_POST['v_plate'] ?? ''));
        if ($plate === '') {
            flash('License plate is required.', 'error');
        } else {
            $norm = normalize_plate($plate);
            $dup = $db->prepare('SELECT id FROM vehicles WHERE plate_norm = ? LIMIT 1');
            $dup->execute([$norm]);
            if ($dup->fetchColumn()) {
                flash('A vehicle with that plate is already registered.', 'error');
            } else {
                $db->prepare('INSERT INTO vehicles (company_id, make, model, plate, plate_state, plate_norm, color, driver_name, driver_email, driver_phone, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')
                    ->execute([$id, trim((string) ($_POST['v_make'] ?? '')), trim((string) ($_POST['v_model'] ?? '')), strtoupper($plate), trim((string) ($_POST['v_plate_state'] ?? '')), $norm, trim((string) ($_POST['v_color'] ?? '')), trim((string) ($_POST['v_driver_name'] ?? '')), trim((string) ($_POST['v_driver_email'] ?? '')), trim((string) ($_POST['v_driver_phone'] ?? ''))]);
                $vid = (int) $db->lastInsertId();
                if ($company['location_id']) {
                    $db->prepare('INSERT INTO permits (vehicle_id, company_id, location_id, start_date, end_date, status, created_at) VALUES (?,?,?,?,?,\'active\',CURRENT_TIMESTAMP)')
                        ->execute([$vid, $id, $company['location_id'], $today, $company['contract_end'] ?: date('Y-m-d', strtotime($today . ' +1 month'))]);
                }
                audit('vehicle.created', 'vehicle', $vid, ['company_id' => $id, 'plate' => $plate]);
                flash('Vehicle added.', 'success');
            }
        }
        redirect(site_url('admin/company_form.php?id=' . $id));
    }
    if ($op === 'vehicle_remove' && $can_edit && !$is_new) {
        $vid = (int) ($_POST['vehicle_id'] ?? 0);
        $sc = $db->prepare('SELECT COUNT(*) FROM subscriptions WHERE vehicle_id = ?');
        $sc->execute([$vid]);
        $pc = $db->prepare('SELECT COUNT(*) FROM permits WHERE vehicle_id = ?');
        $pc->execute([$vid]);
        if ((int) $sc->fetchColumn() > 0 || (int) $pc->fetchColumn() > 0) {
            flash('Cannot remove: the vehicle has subscriptions or permits on record.', 'error');
        } else {
            $db->prepare('DELETE FROM vehicles WHERE id = ? AND company_id = ?')->execute([$vid, $id]);
            audit('vehicle.deleted', 'vehicle', $vid, ['company_id' => $id]);
            flash('Vehicle removed.', 'success');
        }
        redirect(site_url('admin/company_form.php?id=' . $id));
    }

    // ----- Consolidated invoice -----
    if ($op === 'consolidated_invoice' && $can_invoice && !$is_new) {
        $descs = $_POST['item_desc'] ?? [];
        $qtys = $_POST['item_qty'] ?? [];
        $units = $_POST['item_unit'] ?? [];
        $issue = trim((string) ($_POST['issue_date'] ?? $today));
        $due = trim((string) ($_POST['due_date'] ?? date('Y-m-d', strtotime($today . ' +14 days'))));
        $send_email = isset($_POST['send_email']);
        $items = [];
        $n = max(count($descs), count($qtys), count($units));
        for ($i = 0; $i < $n; $i++) {
            $d = trim((string) ($descs[$i] ?? ''));
            $q = (int) ($qtys[$i] ?? 0);
            $u = trim((string) ($units[$i] ?? ''));
            if ($d === '' || $q <= 0 || $u === '' || !is_numeric($u)) {
                continue;
            }
            $uc = (int) round((float) $u * 100);
            if ($uc < 0) {
                continue;
            }
            $items[] = ['desc' => $d, 'qty' => $q, 'unit_cents' => $uc, 'amount_cents' => $q * $uc];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            flash('Issue and due dates must be valid.', 'error');
            redirect(site_url('admin/company_form.php?id=' . $id));
        }
        if ($items === []) {
            flash('Add at least one line item with a description, quantity, and unit price.', 'error');
            redirect(site_url('admin/company_form.php?id=' . $id));
        }
        $total_cents = array_sum(array_column($items, 'amount_cents'));
        $db->beginTransaction();
        try {
            $number = new_invoice_number();
            $token = secure_token();
            $db->prepare(
                'INSERT INTO invoices (number, company_id, issue_date, due_date, amount_cents, amount_paid_cents, status, payment_token, created_by, notes, created_at)
                 VALUES (?,?,?, ?,?,0,\'unpaid\',?,?,?,CURRENT_TIMESTAMP)'
            )->execute([$number, $id, $issue, $due, $total_cents, $token, $user['id'], 'Consolidated invoice']);
            $inv_id = (int) $db->lastInsertId();
            $ii = $db->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents, amount_cents) VALUES (?,?,?,?,?)');
            foreach ($items as $it) {
                $ii->execute([$inv_id, $it['desc'], $it['qty'], $it['unit_cents'], $it['amount_cents']]);
            }
            $db->commit();
            audit('invoice.created', 'invoice', $inv_id, ['company_id' => $id, 'number' => $number, 'amount_cents' => $total_cents]);
            if ($send_email && $company['billing_email']) {
                try {
                    send_template('invoice_issued', $company['billing_email'], [
                        'company_name' => $company['name'],
                        'name' => $company['billing_contact'] ?: $company['contact_name'] ?: $company['name'],
                        'invoice_number' => $number,
                        'amount' => money($total_cents),
                        'due_date' => fmt_date($due),
                        'pay_url' => site_url('portal/pay.php?token=' . $token),
                        'business_name' => setting('business_name', 'Metro Valet Parking'),
                    ], 'inv_' . $number);
                } catch (Exception $ex) {
                    flash('Invoice created, but the email could not be sent.', 'warning');
                }
            }
            flash('Consolidated invoice ' . $number . ' created for ' . money($total_cents) . '.', 'success');
            redirect(site_url('admin/invoice_view.php?id=' . $inv_id));
        } catch (Exception $ex) {
            $db->rollBack();
            flash('Invoice creation failed: ' . $ex->getMessage(), 'error');
            redirect(site_url('admin/company_form.php?id=' . $id));
        }
    }

    flash('Nothing to do.', 'info');
    redirect(site_url('admin/company_form.php' . ($is_new ? '' : '?id=' . $id)));
}

// ---------- Load related data ----------
$contacts = [];
$vehicles = [];
$invoices = [];
$outstanding_cents = 0;
$active_subs = [];
if (!$is_new) {
    $cs = $db->prepare('SELECT * FROM company_contacts WHERE company_id = ? ORDER BY id');
    $cs->execute([$id]);
    $contacts = $cs->fetchAll(PDO::FETCH_ASSOC);

    $vs = $db->prepare(
        'SELECT v.*, (SELECT p.status FROM permits p WHERE p.vehicle_id = v.id ORDER BY p.end_date DESC LIMIT 1) AS permit_status
         FROM vehicles v WHERE v.company_id = ? ORDER BY v.id'
    );
    $vs->execute([$id]);
    $vehicles = $vs->fetchAll(PDO::FETCH_ASSOC);

    $is = $db->prepare('SELECT * FROM invoices WHERE company_id = ? ORDER BY created_at DESC, id DESC');
    $is->execute([$id]);
    $invoices = $is->fetchAll(PDO::FETCH_ASSOC);
    foreach ($invoices as $inv) {
        if (in_array($inv['status'], ['unpaid', 'pending', 'partially_paid', 'overdue'], true)) {
            $outstanding_cents += (int) $inv['amount_cents'] - (int) $inv['amount_paid_cents'];
        }
    }

    $ss = $db->prepare(
        'SELECT s.*, v.plate, l.name AS location_name FROM subscriptions s
         LEFT JOIN vehicles v ON v.id = s.vehicle_id
         LEFT JOIN parking_locations l ON l.id = s.location_id
         WHERE s.company_id = ? AND s.status = \'active\' ORDER BY s.end_date'
    );
    $ss->execute([$id]);
    $active_subs = $ss->fetchAll(PDO::FETCH_ASSOC);
}

$f = [
    'name' => $company['name'] ?? '', 'contact_name' => $company['contact_name'] ?? '',
    'billing_contact' => $company['billing_contact'] ?? '', 'billing_email' => $company['billing_email'] ?? '',
    'billing_phone' => $company['billing_phone'] ?? '', 'billing_address' => $company['billing_address'] ?? '',
    'phone' => $company['phone'] ?? '', 'email' => $company['email'] ?? '',
    'location_id' => $company['location_id'] ?? 0, 'spaces' => $company['spaces'] ?? '',
    'rate_dollars' => isset($company['rate_cents']) && $company['rate_cents'] !== null ? number_format(((int) $company['rate_cents']) / 100, 2, '.', '') : '',
    'contract_start' => $company['contract_start'] ?? '', 'contract_end' => $company['contract_end'] ?? '',
    'status' => $company['status'] ?? 'active', 'notes' => $company['notes'] ?? '',
];

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1><?= $is_new ? 'Add Company' : e($company['name']) ?></h1>
    <div class="page-actions"><a class="btn btn-outline" href="<?= e(site_url('admin/companies.php')) ?>">Back to list</a></div>
</div>

<?php if (!$is_new): ?>
    <div class="kpi-grid">
        <div class="kpi"><div class="kpi-label">Vehicles</div><div class="kpi-value"><?= count($vehicles) ?></div></div>
        <div class="kpi"><div class="kpi-label">Active subscriptions</div><div class="kpi-value"><?= count($active_subs) ?></div></div>
        <div class="kpi <?= $outstanding_cents > 0 ? 'red' : 'green' ?>">
            <div class="kpi-label">Outstanding</div><div class="kpi-value"><?= e(money($outstanding_cents)) ?></div>
            <div class="kpi-sub"><?= count($invoices) ?> invoice(s) total</div>
        </div>
    </div>
<?php endif; ?>

<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="save">
    <h3>Company details</h3>
    <div class="form-grid">
        <div class="field"><label for="name">Company name *</label><input type="text" id="name" name="name" value="<?= e($f['name']) ?>" required maxlength="200"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="contact_name">Primary contact</label><input type="text" id="contact_name" name="contact_name" value="<?= e($f['contact_name']) ?>" maxlength="200"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="email">Company email</label><input type="email" id="email" name="email" value="<?= e($f['email']) ?>" maxlength="200"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="phone">Company phone</label><input type="tel" id="phone" name="phone" value="<?= e($f['phone']) ?>" maxlength="60"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="billing_contact">Billing contact</label><input type="text" id="billing_contact" name="billing_contact" value="<?= e($f['billing_contact']) ?>" maxlength="200"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="billing_email">Billing email</label><input type="email" id="billing_email" name="billing_email" value="<?= e($f['billing_email']) ?>" maxlength="200"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="billing_phone">Billing phone</label><input type="tel" id="billing_phone" name="billing_phone" value="<?= e($f['billing_phone']) ?>" maxlength="60"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="billing_address">Billing address</label><input type="text" id="billing_address" name="billing_address" value="<?= e($f['billing_address']) ?>" maxlength="255"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="location_id">Primary location</label>
            <select id="location_id" name="location_id"<?= $can_edit ? '' : ' disabled' ?>>
                <option value="0">—</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= (int) $l['id'] ?>"<?= (int) $f['location_id'] === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label for="spaces">Spaces</label><input type="number" id="spaces" name="spaces" value="<?= e($f['spaces']) ?>" min="0" step="1"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="rate_dollars">Rate per space ($/mo)</label><input type="number" id="rate_dollars" name="rate_dollars" value="<?= e($f['rate_dollars']) ?>" min="0" step="0.01"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="contract_start">Contract start</label><input type="date" id="contract_start" name="contract_start" value="<?= e($f['contract_start']) ?>"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="contract_end">Contract end</label><input type="date" id="contract_end" name="contract_end" value="<?= e($f['contract_end']) ?>"<?= $can_edit ? '' : ' disabled' ?>></div>
        <div class="field"><label for="status">Status</label>
            <select id="status" name="status"<?= $can_edit ? '' : ' disabled' ?>>
                <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'prospect' => 'Prospect'] as $k => $lbl): ?>
                    <option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field full"><label for="notes">Notes</label><textarea id="notes" name="notes"<?= $can_edit ? '' : ' disabled' ?>><?= e($f['notes']) ?></textarea></div>
    </div>
    <?php if ($can_edit): ?>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary"><?= $is_new ? 'Create company' : 'Save changes' ?></button>
        </div>
    <?php endif; ?>
</form>

<?php if (!$is_new): ?>
    <div class="section-title"><h2>Contacts</h2></div>
    <div class="card">
        <?php if ($contacts === []): ?>
            <p class="muted">No contacts yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Portal access</th><?php if ($can_edit): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($contacts as $ct): ?>
                            <tr>
                                <td><strong><?= e($ct['name']) ?></strong></td>
                                <td><?= e($ct['email'] ?: '—') ?></td>
                                <td><?= e($ct['phone'] ?: '—') ?></td>
                                <td><?= e($ct['role'] ?: '—') ?></td>
                                <td><?= $ct['password_hash'] ? '<span class="badge badge-green">' . status_dot('green', 'Yes') . '</span>' : '<span class="badge badge-gray">' . status_dot('gray', 'No') . '</span>' ?></td>
                                <?php if ($can_edit): ?>
                                    <td>
                                        <form method="post" class="inline-form" data-confirm="Remove this contact?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="op" value="contact_delete">
                                            <input type="hidden" name="contact_id" value="<?= (int) $ct['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-xs">Remove</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($can_edit): ?>
            <h3>Add contact</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="contact_add">
                <div class="form-grid">
                    <div class="field"><label for="c_name">Name *</label><input type="text" id="c_name" name="c_name" required maxlength="200"></div>
                    <div class="field"><label for="c_email">Email</label><input type="email" id="c_email" name="c_email" maxlength="200"></div>
                    <div class="field"><label for="c_phone">Phone</label><input type="tel" id="c_phone" name="c_phone" maxlength="60"></div>
                    <div class="field"><label for="c_role">Role</label><input type="text" id="c_role" name="c_role" maxlength="100" placeholder="e.g. Billing, Fleet manager"></div>
                    <div class="field"><label for="c_password">Portal password</label><input type="password" id="c_password" name="c_password" autocomplete="new-password"><div class="hint">Optional — sets portal login for this contact.</div></div>
                </div>
                <div class="btn-row"><button type="submit" class="btn btn-outline">Add contact</button></div>
            </form>
        <?php endif; ?>
    </div>

    <div class="section-title"><h2>Vehicles &amp; permits</h2></div>
    <div class="card">
        <?php if ($vehicles === []): ?>
            <p class="muted">No vehicles registered for this company.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Plate</th><th>Vehicle</th><th>Driver</th><th>Permit</th><?php if ($can_edit): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($vehicles as $vv): ?>
                            <tr>
                                <td><strong class="mono"><?= e($vv['plate']) ?></strong> <span class="muted small"><?= e($vv['plate_state']) ?></span></td>
                                <td><?= e(trim(($vv['make'] ?? '') . ' ' . ($vv['model'] ?? ''))) ?></td>
                                <td class="small"><?php if (!empty($vv['driver_name']) || !empty($vv['driver_email']) || !empty($vv['driver_phone'])): ?>
                                    <?= e($vv['driver_name'] ?: '—') ?><br>
                                    <span class="muted"><?= e($vv['driver_email'] ?: '') ?><?= $vv['driver_email'] && $vv['driver_phone'] ? ' · ' : '' ?><?= e($vv['driver_phone'] ?: '') ?></span>
                                <?php else: ?><span class="muted">—</span><?php endif; ?></td>
                                <td><?= $vv['permit_status'] ? e(ucfirst($vv['permit_status'])) : '<span class="muted">None</span>' ?></td>
                                <?php if ($can_edit): ?>
                                    <td>
                                        <form method="post" class="inline-form" data-confirm="Remove this vehicle? Only allowed with no subscriptions or permits.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="op" value="vehicle_remove">
                                            <input type="hidden" name="vehicle_id" value="<?= (int) $vv['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-xs">Remove</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($can_edit): ?>
            <h3>Add vehicle</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="vehicle_add">
                <div class="form-grid">
                    <div class="field"><label for="v_make">Make</label><input type="text" id="v_make" name="v_make" maxlength="80"></div>
                    <div class="field"><label for="v_model">Model</label><input type="text" id="v_model" name="v_model" maxlength="80"></div>
                    <div class="field"><label for="v_plate">License plate *</label><input type="text" id="v_plate" name="v_plate" required maxlength="20" style="text-transform:uppercase"></div>
                    <div class="field"><label for="v_plate_state">Plate state</label><input type="text" id="v_plate_state" name="v_plate_state" maxlength="10"></div>
                    <div class="field"><label for="v_color">Color</label><input type="text" id="v_color" name="v_color" maxlength="40"></div>
                </div>
                <h4 style="margin-top:14px">Driver contact (optional)</h4>
                <div class="form-grid">
                    <div class="field"><label for="v_driver_name">Driver name</label><input type="text" id="v_driver_name" name="v_driver_name" maxlength="120"></div>
                    <div class="field"><label for="v_driver_email">Driver email</label><input type="email" id="v_driver_email" name="v_driver_email" maxlength="160"></div>
                    <div class="field"><label for="v_driver_phone">Driver phone</label><input type="tel" id="v_driver_phone" name="v_driver_phone" maxlength="40"></div>
                </div>
                <div class="btn-row"><button type="submit" class="btn btn-outline">Add vehicle</button></div>
            </form>
        <?php endif; ?>
    </div>

    <div class="section-title"><h2>Invoices</h2>
        <?php if ($can_invoice): ?>
            <a class="btn btn-primary btn-sm" href="#consolidated">Create consolidated invoice</a>
        <?php endif; ?>
    </div>
    <?php if ($invoices === []): ?>
        <div class="empty"><strong>No invoices</strong>Create the first consolidated invoice below.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Number</th><th>Issued</th><th>Due</th><th>Amount</th><th>Paid</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><strong class="mono"><?= e($inv['number']) ?></strong></td>
                            <td><?= e(fmt_date($inv['issue_date'])) ?></td>
                            <td><?= e(fmt_date($inv['due_date'])) ?></td>
                            <td><?= e(money((int) $inv['amount_cents'])) ?></td>
                            <td><?= e(money((int) $inv['amount_paid_cents'])) ?></td>
                            <td><?= e(ucfirst(str_replace('_', ' ', $inv['status']))) ?></td>
                            <td><a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/invoice_view.php?id=' . (int) $inv['id'])) ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($can_invoice): ?>
        <div class="section-title" id="consolidated"><h2>Create consolidated invoice</h2></div>
        <div class="card">
            <p class="muted">One invoice for the whole company, with a line item per vehicle or space. Amounts are entered by you — never invented.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="consolidated_invoice">
                <div class="form-grid">
                    <div class="field"><label for="issue_date">Issue date</label><input type="date" id="issue_date" name="issue_date" value="<?= e($today) ?>"></div>
                    <div class="field"><label for="due_date">Due date</label><input type="date" id="due_date" name="due_date" value="<?= e(date('Y-m-d', strtotime($today . ' +14 days'))) ?>"></div>
                </div>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Description</th><th style="width:90px">Qty</th><th style="width:140px">Unit price ($)</th></tr></thead>
                        <tbody>
                            <?php
                            $prefill = [];
                            foreach ($active_subs as $s) {
                                $lbl = 'Monthly parking — ' . ($s['plate'] ? 'plate ' . $s['plate'] : 'space') . ' — ' . ($s['location_name'] ?: '');
                                $unit = $s['monthly_rate_cents'] !== null ? number_format(((int) $s['monthly_rate_cents']) / 100, 2, '.', '') : ($f['rate_dollars'] ?: '');
                                $prefill[] = ['desc' => $lbl, 'unit' => $unit];
                            }
                            for ($i = 0; $i < 3; $i++) {
                                $prefill[] = ['desc' => '', 'unit' => ''];
                            }
                            foreach ($prefill as $pi):
                            ?>
                                <tr>
                                    <td><input type="text" name="item_desc[]" value="<?= e($pi['desc']) ?>" maxlength="255"></td>
                                    <td><input type="number" name="item_qty[]" value="<?= $pi['desc'] !== '' ? '1' : '' ?>" min="0" step="1"></td>
                                    <td><input type="number" name="item_unit[]" value="<?= e($pi['unit']) ?>" min="0" step="0.01"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <label class="check-line"><input type="checkbox" name="send_email" value="1" checked> Email invoice to billing contact<?= $company['billing_email'] ? ' (' . e($company['billing_email']) . ')' : ' (no billing email on file)' ?></label>
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary">Create invoice</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
