<?php
// Bulk import monthly parkers (individual + corporate) from CSV.
require __DIR__ . '/../lib/bootstrap.php';

$required_perm = 'parkers.edit';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$today = date('Y-m-d');

$COLUMNS = ['type', 'name', 'company_name', 'email', 'phone', 'location', 'plate', 'plate_state', 'vehicle_make', 'vehicle_model', 'vehicle_color', 'tag_number', 'start_date', 'end_date', 'rate', 'hours_allowed', 'spaces', 'category', 'notes'];
$MAX_ROWS = 500;

// --- Template download -------------------------------------------------------
if (($_GET['action'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="monthly-parkers-import-template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $COLUMNS);
    fputcsv($out, ['individual', 'Jane Doe', '', 'jane@example.com', '313-555-0100', 'Downtown Garage', 'ABC1234', 'MI', 'Ford', 'Fusion', 'Blue', '2083', $today, date('Y-m-d', strtotime($today . ' +1 month')), '150.00', '24/7', '1', 'resident', '']);
    fputcsv($out, ['corporate', 'John Smith', 'Acme Corp', 'billing@acme.com', '313-555-0200', 'Downtown Garage', 'XYZ9876', 'MI', 'Toyota', 'Camry', 'Black', '3100', $today, date('Y-m-d', strtotime($today . ' +1 month')), '140.00', 'Mon - Fri 7am - 7pm', '1', 'monthly', 'Fleet vehicle 1']);
    fputcsv($out, ['corporate', 'John Smith', 'Acme Corp', 'billing@acme.com', '313-555-0200', 'Downtown Garage', 'XYZ9877', 'MI', 'Honda', 'Accord', 'White', '3101', $today, date('Y-m-d', strtotime($today . ' +1 month')), '140.00', 'Mon - Fri 7am - 7pm', '1', 'monthly', 'Fleet vehicle 2']);
    fclose($out);
    exit;
}

// Location lookup (name -> id, case-insensitive).
$loc_map = [];
foreach ($db->query('SELECT id, name FROM parking_locations')->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $loc_map[strtolower(trim($l['name']))] = (int) $l['id'];
    $loc_map[(string) (int) $l['id']] = (int) $l['id'];
}

function import_validate_row(array $r, int $num, array $loc_map, array &$seen_plates, PDO $db): array
{
    $errs = [];
    $type = strtolower(trim($r['type'] ?? ''));
    if (!in_array($type, ['individual', 'corporate'], true)) {
        $errs[] = "Row $num: type must be 'individual' or 'corporate'.";
    }
    if ($type === 'individual' && trim($r['name'] ?? '') === '') {
        $errs[] = "Row $num: name is required for individual parkers.";
    }
    if ($type === 'corporate' && trim($r['company_name'] ?? '') === '') {
        $errs[] = "Row $num: company_name is required for corporate parkers.";
    }
    $loc_key = strtolower(trim($r['location'] ?? ''));
    if ($loc_key === '' || !isset($loc_map[$loc_key])) {
        $errs[] = "Row $num: location '" . ($r['location'] ?? '') . "' not found.";
    }
    $plate = strtoupper(trim($r['plate'] ?? ''));
    if ($plate === '') {
        $errs[] = "Row $num: plate is required.";
    } else {
        $norm = normalize_plate($plate);
        if (isset($seen_plates[$norm])) {
            $errs[] = "Row $num: plate $plate is duplicated in this file.";
        } else {
            $st = $db->prepare('SELECT id FROM vehicles WHERE plate_norm = ? LIMIT 1');
            $st->execute([$norm]);
            if ($st->fetchColumn()) {
                $errs[] = "Row $num: plate $plate is already registered.";
            }
        }
        $seen_plates[$norm] = true;
    }
    foreach (['start_date', 'end_date'] as $dk) {
        $dv = trim($r[$dk] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dv) || strtotime($dv) === false) {
            $errs[] = "Row $num: $dk must be YYYY-MM-DD.";
        }
    }
    if (empty($errs) && $r['end_date'] < $r['start_date']) {
        $errs[] = "Row $num: end_date cannot be before start_date.";
    }
    $rate = trim($r['rate'] ?? '');
    if ($rate !== '' && (!is_numeric($rate) || (float) $rate < 0)) {
        $errs[] = "Row $num: rate must be a non-negative number.";
    }
    $email = trim($r['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errs[] = "Row $num: email is not valid.";
    }
    $spaces = trim($r['spaces'] ?? '');
    if ($spaces !== '' && (!ctype_digit($spaces) || (int) $spaces < 1)) {
        $errs[] = "Row $num: spaces must be a positive whole number.";
    }
    $cat = strtolower(trim($r['category'] ?? ''));
    if ($cat !== '' && !in_array($cat, ['monthly', 'resident'], true)) {
        $errs[] = "Row $num: category must be 'monthly' or 'resident'.";
    }
    return $errs;
}

// --- Upload + preview ---------------------------------------------------------
$preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'upload') {
    verify_csrf();
    $preview = ['rows' => [], 'errors' => []];
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $preview['errors'][] = 'Choose a CSV file to upload.';
    } else {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $preview['errors'][] = 'Could not read the uploaded file.';
        } else {
            // Strip UTF-8 BOM if present.
            $first = fgets($fh);
            if ($first === false) {
                $preview['errors'][] = 'The file is empty.';
            } else {
                $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
                $header = array_map(fn($h) => strtolower(trim($h)), str_getcsv($first));
                $missing = array_diff($COLUMNS, $header);
                if ($missing) {
                    $preview['errors'][] = 'Missing columns: ' . implode(', ', $missing) . '. Download the template.';
                } else {
                    $idx = array_flip($header);
                    $seen_plates = [];
                    $num = 1;
                    while (($data = fgetcsv($fh)) !== false && count($preview['rows']) < $MAX_ROWS) {
                        $num++;
                        if (count(array_filter($data, fn($v) => trim((string) $v) !== '')) === 0) {
                            continue; // skip blank lines
                        }
                        $row = [];
                        foreach ($COLUMNS as $c) {
                            $row[$c] = trim((string) ($data[$idx[$c]] ?? ''));
                        }
                        $row['_errors'] = import_validate_row($row, $num, $loc_map, $seen_plates, $db);
                        $row['_num'] = $num;
                        $preview['rows'][] = $row;
                    }
                    fclose($fh);
                    if (count($preview['rows']) === 0 && empty($preview['errors'])) {
                        $preview['errors'][] = 'No data rows found in the file.';
                    }
                    $_SESSION['parker_import'] = $preview['rows'];
                }
            }
        }
    }
}

// --- Confirm + import ----------------------------------------------------------
$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirm') {
    verify_csrf();
    $rows = $_SESSION['parker_import'] ?? [];
    unset($_SESSION['parker_import']);
    $results = ['imported' => 0, 'skipped' => 0, 'invoices' => 0, 'errors' => []];
    $company_cache = []; // lowercase company name => company id (within this batch)

    foreach ($rows as $r) {
        if (!empty($r['_errors'])) {
            $results['skipped']++;
            continue;
        }
        $db->beginTransaction();
        try {
            $type = strtolower($r['type']);
            $loc_id = $loc_map[strtolower($r['location'])];
            $rate_cents = $r['rate'] !== '' ? (int) round((float) $r['rate'] * 100) : null;
            $plate_norm = normalize_plate($r['plate']);
            $customer_id = null;
            $company_id = null;
            $who_name = '';

            if ($type === 'corporate') {
                $ckey = strtolower($r['company_name']);
                if (isset($company_cache[$ckey])) {
                    $company_id = $company_cache[$ckey];
                } else {
                    $st = $db->prepare('SELECT id FROM companies WHERE LOWER(name) = ? LIMIT 1');
                    $st->execute([$ckey]);
                    $found = $st->fetchColumn();
                    $company_id = $found ? (int) $found : null;
                    if (!$company_id) {
                        $db->prepare(
                            'INSERT INTO companies (name, contact_name, billing_email, phone, email, location_id, status, notes, created_at)
                             VALUES (?,?,?,?,?,?,?, ?,CURRENT_TIMESTAMP)'
                        )->execute([
                            $r['company_name'], $r['name'] ?: $r['company_name'], $r['email'],
                            $r['phone'], $r['email'], $loc_id, 'active', 'Created by bulk import',
                        ]);
                        $company_id = (int) $db->lastInsertId();
                        audit('company.created', 'company', $company_id, ['via' => 'bulk_import', 'name' => $r['company_name']]);
                    }
                    $company_cache[$ckey] = $company_id;
                }
                $who_name = $r['company_name'];
                $db->prepare('INSERT INTO vehicles (company_id, make, model, plate, plate_state, plate_norm, color, tag_number, created_at) VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')
                    ->execute([$company_id, $r['vehicle_make'], $r['vehicle_model'], strtoupper($r['plate']), $r['plate_state'], $plate_norm, $r['vehicle_color'], $r['tag_number'] ?: null]);
            } else {
                $who_name = $r['name'];
                $db->prepare(
                    'INSERT INTO customers (type, name, contact_name, email, phone, location_id, status, notes, created_at)
                     VALUES (\'individual\',?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
                )->execute([$r['name'], $r['name'], $r['email'], $r['phone'], $loc_id, 'active', 'Created by bulk import']);
                $customer_id = (int) $db->lastInsertId();
                audit('customer.created', 'customer', $customer_id, ['via' => 'bulk_import', 'name' => $r['name']]);
                $db->prepare('INSERT INTO vehicles (customer_id, make, model, plate, plate_state, plate_norm, color, tag_number, created_at) VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')
                    ->execute([$customer_id, $r['vehicle_make'], $r['vehicle_model'], strtoupper($r['plate']), $r['plate_state'], $plate_norm, $r['vehicle_color'], $r['tag_number'] ?: null]);
            }
            $vehicle_id = (int) $db->lastInsertId();

            $db->prepare(
                'INSERT INTO subscriptions (customer_id, company_id, location_id, vehicle_id, start_date, end_date, monthly_rate_cents, status, auto_renew, hours_allowed, spaces, category, notes, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,\'active\',1,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)'
            )->execute([$customer_id, $company_id, $loc_id, $vehicle_id, $r['start_date'], $r['end_date'], $rate_cents, $r['hours_allowed'] ?: null, $r['spaces'] !== '' ? (int) $r['spaces'] : null, in_array(strtolower($r['category']), ['monthly','resident'], true) ? strtolower($r['category']) : 'monthly', $r['notes'] !== '' ? $r['notes'] : 'Created by bulk import']);
            $sub_id = (int) $db->lastInsertId();

            $db->prepare('INSERT INTO permits (vehicle_id, customer_id, company_id, location_id, start_date, end_date, status, created_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')
                ->execute([$vehicle_id, $customer_id, $company_id, $loc_id, $r['start_date'], $r['end_date'], 'active']);

            if ($rate_cents !== null && $rate_cents > 0) {
                $loc_name = $db->prepare('SELECT name FROM parking_locations WHERE id = ?');
                $loc_name->execute([$loc_id]);
                create_invoice_for_subscription($db, [
                    'id' => $sub_id,
                    'customer_id' => $customer_id,
                    'company_id' => $company_id,
                    'customer_name' => $type === 'individual' ? $who_name : null,
                    'company_name' => $type === 'corporate' ? $who_name : null,
                    'location_name' => $loc_name->fetchColumn() ?: '',
                    'start_date' => $r['start_date'],
                    'end_date' => $r['end_date'],
                ], $rate_cents, $today, date('Y-m-d', strtotime($today . ' +14 days')), (int) $user['id'], 'First invoice — bulk import');
                $results['invoices']++;
            }

            $db->commit();
            audit('subscription.created', 'subscription', $sub_id, ['via' => 'bulk_import', 'type' => $type]);
            $results['imported']++;
        } catch (Throwable $ex) {
            $db->rollBack();
            $results['skipped']++;
            $results['errors'][] = 'Row ' . ($r['_num'] ?? '?') . ': ' . $ex->getMessage();
        }
    }
    audit('parkers.bulk_import', 'subscription', null, ['imported' => $results['imported'], 'skipped' => $results['skipped']]);
}

$valid_count = 0;
if ($preview) {
    foreach ($preview['rows'] as $r) {
        if (empty($r['_errors'])) {
            $valid_count++;
        }
    }
}

require __DIR__ . '/_layout.php';
?>
<div class="page-head">
    <h1>Bulk Import Monthly Parkers</h1>
    <div class="actions">
        <a class="btn" href="<?= e(site_url('admin/parker_import.php?action=template')) ?>">Download CSV template</a>
        <a class="btn btn-ghost" href="<?= e(site_url('admin/parkers.php')) ?>">Back to Monthly Parkers</a>
    </div>
</div>

<?php if ($results): ?>
    <div class="card">
        <h2>Import complete</h2>
        <p><strong><?= (int) $results['imported'] ?></strong> parkers imported,
           <strong><?= (int) $results['skipped'] ?></strong> skipped,
           <strong><?= (int) $results['invoices'] ?></strong> invoices auto-generated.</p>
        <?php if ($results['errors']): ?>
            <ul class="small" style="color:var(--red)">
                <?php foreach ($results['errors'] as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p><a class="btn" href="<?= e(site_url('admin/parkers.php')) ?>">View Monthly Parkers</a></p>
    </div>
<?php elseif ($preview): ?>
    <div class="card">
        <h2>Preview — <?= count($preview['rows']) ?> rows (<?= (int) $valid_count ?> valid)</h2>
        <?php if ($preview['errors']): ?>
            <ul style="color:var(--red)"><?php foreach ($preview['errors'] as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
        <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Row</th><th>Type</th><th>Name / Company</th><th>Plate</th><th>Location</th><th>Period</th><th>Rate</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($preview['rows'] as $r): ?>
                    <tr>
                        <td><?= (int) $r['_num'] ?></td>
                        <td><?= e(ucfirst($r['type'])) ?></td>
                        <td><?= e($r['type'] === 'corporate' ? $r['company_name'] : $r['name']) ?></td>
                        <td><?= e(strtoupper($r['plate'])) ?></td>
                        <td><?= e($r['location']) ?></td>
                        <td class="small"><?= e($r['start_date']) ?> → <?= e($r['end_date']) ?></td>
                        <td><?= e($r['rate'] !== '' ? '$' . $r['rate'] : '—') ?></td>
                        <td><?php if ($r['_errors']): ?>
                            <span class="badge badge-red">Skip</span>
                            <div class="small" style="color:var(--red)"><?= e(implode(' ', $r['_errors'])) ?></div>
                        <?php else: ?><span class="badge badge-green">Ready</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($valid_count > 0): ?>
            <form method="post" style="margin-top:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="step" value="confirm">
                <button class="btn btn-primary" type="submit">Import <?= (int) $valid_count ?> valid parkers</button>
                <a class="btn btn-ghost" href="<?= e(site_url('admin/parker_import.php')) ?>">Cancel</a>
            </form>
            <p class="hint">Corporate rows with the same company name are grouped under one company account. Invoices auto-generate for rows with a rate.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <h2>Upload CSV</h2>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="upload">
            <label>CSV file <input type="file" name="csv" accept=".csv" required></label>
            <button class="btn btn-primary" type="submit">Upload &amp; preview</button>
        </form>
        <h3>Columns</h3>
        <p class="small"><code>type,name,company_name,email,phone,location,plate,plate_state,vehicle_make,vehicle_model,vehicle_color,tag_number,start_date,end_date,rate,hours_allowed,spaces,category,notes</code></p>
        <ul class="small">
            <li><strong>type</strong> — <code>individual</code> or <code>corporate</code>.</li>
            <li><strong>name</strong> — required for individuals (contact person for corporate).</li>
            <li><strong>company_name</strong> — required for corporate. Rows sharing a company name become one corporate account with multiple vehicles.</li>
            <li><strong>location</strong> — must match a location name exactly.</li>
            <li><strong>plate</strong> — required; duplicates (in file or database) are skipped.</li>
            <li><strong>tag_number</strong> — permit/tag # from your sheet.</li>
            <li><strong>start_date / end_date</strong> — YYYY-MM-DD.</li>
            <li><strong>rate</strong> — monthly amount in dollars (empty = no auto-invoice).</li>
            <li><strong>hours_allowed</strong> — e.g. 24/7, Resident, Mon - Fri 7am - 7pm.</li>
            <li><strong>spaces</strong> — number of spaces (whole number).</li>
            <li><strong>category</strong> — <code>monthly</code> or <code>resident</code> (empty = monthly).</li>
        </ul>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
