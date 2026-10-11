<?php
// admin/requests.php — monthly parking request queue: triage, approve (full provisioning), reject.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Monthly Requests';
$nav_section = 'requests';
$required_perm = 'requests.manage';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$today = today();

$status_f = (string) ($_GET['status'] ?? 'open');
$search = trim((string) ($_GET['q'] ?? ''));
$view_id = (int) ($_GET['view'] ?? 0);

function mv_request(PDO $db, int $id): ?array
{
    $st = $db->prepare(
        'SELECT r.*, l.name AS location_name, l.monthly_rate_cents
         FROM monthly_requests r LEFT JOIN parking_locations l ON l.id = r.location_id
         WHERE r.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ---------- Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rid = (int) ($_POST['id'] ?? 0);
    $req = mv_request($db, $rid);
    if (!$req) {
        flash('Request not found.', 'error');
        redirect(site_url('admin/requests.php'));
    }
    $op = (string) ($_POST['op'] ?? '');

    // Mark contacted / request info — status workflow + timestamped note.
    if (in_array($op, ['contacted', 'info_requested'], true)) {
        $note = trim((string) ($_POST['note'] ?? ''));
        $entry = date('Y-m-d H:i') . ' — ' . $user['name'] . ': marked ' . ($op === 'contacted' ? 'contacted' : 'info requested') . ($note !== '' ? ' — ' . $note : '');
        $db->prepare("UPDATE monthly_requests SET status = ?, admin_notes = TRIM(COALESCE(admin_notes,'') || '\n' || ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$op, $entry, $rid]);
        audit('monthly_request.' . $op, 'monthly_request', $rid, ['ref' => $req['ref_no']]);
        flash('Request ' . $req['ref_no'] . ' updated.', 'success');
        redirect(site_url('admin/requests.php?view=' . $rid));
    }

    // Reject — email the applicant.
    if ($op === 'reject') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $db->prepare("UPDATE monthly_requests SET status = 'rejected', admin_notes = TRIM(COALESCE(admin_notes,'') || '\n' || ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([date('Y-m-d H:i') . ' — ' . $user['name'] . ': rejected' . ($reason !== '' ? ' — ' . $reason : ''), $rid]);
        try {
            send_template('request_rejected', $req['email'], [
                'name' => $req['full_name'] ?: $req['contact_person'],
                'ref_no' => $req['ref_no'],
                'reason' => $reason,
                'business_name' => setting('business_name', 'Metro Valet Parking'),
            ], 'reqrej_' . $req['ref_no']);
        } catch (Exception $ex) {
            flash('Request rejected, but the notification email failed.', 'warning');
        }
        audit('monthly_request.rejected', 'monthly_request', $rid, ['ref' => $req['ref_no']]);
        flash('Request ' . $req['ref_no'] . ' rejected and applicant notified.', 'success');
        redirect(site_url('admin/requests.php'));
    }

    // Approve — full provisioning transaction.
    if ($op === 'approve') {
        $start = trim((string) ($_POST['start_date'] ?? ''));
        $end = trim((string) ($_POST['end_date'] ?? ''));
        $amount_d = trim((string) ($_POST['amount_dollars'] ?? ''));
        $send_creds = isset($_POST['send_credentials']);
        $errs = [];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $errs[] = 'Start and end dates are required.';
        } elseif ($end < $start) {
            $errs[] = 'End date cannot be before the start date.';
        }
        if ($amount_d === '' || !is_numeric($amount_d) || (float) $amount_d < 0) {
            $errs[] = 'Enter the first invoice amount in dollars (required — never invented).';
        }
        if ($req['status'] === 'approved') {
            $errs[] = 'This request was already approved.';
        }
        if ($errs !== []) {
            foreach ($errs as $er) {
                flash($er, 'error');
            }
            redirect(site_url('admin/requests.php?view=' . $rid . '&approve=1'));
        }
        $amount_cents = (int) round((float) $amount_d * 100);
        $temp_password = secure_token(10);
        $pw_hash = password_hash($temp_password, PASSWORD_DEFAULT);

        $db->beginTransaction();
        try {
            $is_company = $req['type'] === 'company';
            $customer_id = null;
            $company_id = null;
            $vehicle_id = null;
            $login_email = '';

            if ($is_company) {
                $spaces = ctype_digit((string) ($req['spaces'] ?? '')) ? (int) $req['spaces'] : 0;
                $rate_cents = $spaces > 0 ? (int) round($amount_cents / $spaces) : $amount_cents;
                $db->prepare(
                    'INSERT INTO companies (name, contact_name, billing_contact, billing_email, phone, email, location_id, spaces, rate_cents, contract_start, contract_end, status, notes, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
                )->execute([
                    $req['company_name'], $req['contact_person'], $req['billing_contact'],
                    $req['billing_email'], $req['phone'], $req['email'], $req['location_id'],
                    $spaces ?: null, $rate_cents, $start, $end, 'active',
                    'Created from monthly request ' . $req['ref_no'],
                ]);
                $company_id = (int) $db->lastInsertId();
                $login_email = $req['billing_email'] ?: $req['email'];
                $db->prepare('INSERT INTO company_contacts (company_id, name, email, phone, role, password_hash) VALUES (?,?,?,?,?,?)')
                    ->execute([$company_id, $req['contact_person'] ?: $req['company_name'], $login_email, $req['phone'], 'Primary', $pw_hash]);
                audit('company.created_from_request', 'company', $company_id, ['ref' => $req['ref_no']]);
            } else {
                $db->prepare(
                    'INSERT INTO customers (type, name, contact_name, email, phone, password_hash, location_id, notes, created_at)
                     VALUES (\'individual\',?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
                )->execute([
                    $req['full_name'], $req['full_name'], $req['email'], $req['phone'],
                    $pw_hash, $req['location_id'], 'Created from monthly request ' . $req['ref_no'],
                ]);
                $customer_id = (int) $db->lastInsertId();
                $login_email = $req['email'];
                if ($req['plate'] || $req['vehicle_make'] || $req['vehicle_model']) {
                    $norm = $req['plate'] ? normalize_plate($req['plate']) : '';
                    $db->prepare('INSERT INTO vehicles (customer_id, make, model, plate, plate_state, plate_norm, created_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP)')
                        ->execute([$customer_id, $req['vehicle_make'], $req['vehicle_model'], strtoupper((string) $req['plate']), $req['plate_state'], $norm]);
                    $vehicle_id = (int) $db->lastInsertId();
                }
                audit('customer.created_from_request', 'customer', $customer_id, ['ref' => $req['ref_no']]);
            }

            // Subscription.
            $db->prepare(
                'INSERT INTO subscriptions (customer_id, company_id, location_id, vehicle_id, start_date, end_date, monthly_rate_cents, status, auto_renew, notes, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,\'active\',1,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)'
            )->execute([$customer_id, $company_id, $req['location_id'], $vehicle_id, $start, $end, $amount_cents, 'From request ' . $req['ref_no']]);
            $sub_id = (int) $db->lastInsertId();

            if ($vehicle_id) {
                $db->prepare('INSERT INTO permits (vehicle_id, customer_id, company_id, location_id, start_date, end_date, status, created_at) VALUES (?,?,?,?,?,?,\'active\',CURRENT_TIMESTAMP)')
                    ->execute([$vehicle_id, $customer_id, $company_id, $req['location_id'], $start, $end]);
            }

            // First invoice.
            $number = new_invoice_number();
            $token = secure_token();
            $db->prepare(
                'INSERT INTO invoices (number, subscription_id, company_id, customer_id, issue_date, due_date, amount_cents, amount_paid_cents, status, payment_token, created_by, notes, created_at)
                 VALUES (?,?,?,?,?,?,?,0,\'unpaid\',?,?,?,CURRENT_TIMESTAMP)'
            )->execute([$number, $sub_id, $company_id, $customer_id, $today, date('Y-m-d', strtotime($today . ' +14 days')), $amount_cents, $token, $user['id'], 'First invoice — request ' . $req['ref_no']]);
            $inv_id = (int) $db->lastInsertId();
            $who = $is_company ? $req['company_name'] : $req['full_name'];
            $db->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents, amount_cents) VALUES (?,?,?,?,?)')
                ->execute([$inv_id, 'Monthly parking — ' . $req['location_name'] . ' — ' . $who . ' (' . fmt_date($start) . ' to ' . fmt_date($end) . ')', 1, $amount_cents, $amount_cents]);

            $db->prepare("UPDATE monthly_requests SET status = 'approved', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$rid]);
            $db->commit();

            audit('subscription.created_from_request', 'subscription', $sub_id, ['ref' => $req['ref_no']]);
            audit('invoice.created', 'invoice', $inv_id, ['number' => $number, 'ref' => $req['ref_no']]);
            audit('monthly_request.approved', 'monthly_request', $rid, ['ref' => $req['ref_no']]);

            if ($send_creds && $login_email) {
                try {
                    send_template('request_approved', $login_email, [
                        'name' => $who,
                        'ref_no' => $req['ref_no'],
                        'temp_password' => $temp_password,
                        'login_url' => site_url('portal/login.php'),
                        'invoice_number' => $number,
                        'amount' => money($amount_cents),
                        'business_name' => setting('business_name', 'Metro Valet Parking'),
                    ], 'reqappr_' . $req['ref_no']);
                } catch (Exception $ex) {
                    flash('Approved, but the credentials email failed — share the temp password manually.', 'warning');
                }
            }

            flash('Request ' . $req['ref_no'] . ' approved: ' . ($is_company ? 'company' : 'customer') . ' created, subscription active, invoice ' . $number . ' issued.', 'success');
            redirect(site_url('admin/invoice_view.php?id=' . $inv_id));
        } catch (Exception $ex) {
            $db->rollBack();
            flash('Approval failed: ' . $ex->getMessage(), 'error');
            redirect(site_url('admin/requests.php?view=' . $rid));
        }
    }

    redirect(site_url('admin/requests.php'));
}

// ---------- Detail / approve form view ----------
if ($view_id > 0) {
    $req = mv_request($db, $view_id);
    if (!$req) {
        flash('Request not found.', 'error');
        redirect(site_url('admin/requests.php'));
    }
    $show_approve = isset($_GET['approve']) && $_GET['approve'] === '1' && $req['status'] !== 'approved';
    $page_title = 'Request ' . $req['ref_no'];
    $def_start = $req['start_date'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $req['start_date']) ? $req['start_date'] : $today;
    $def_end = date('Y-m-d', strtotime($def_start . ' +1 month'));
    $def_amount = $req['monthly_rate_cents'] !== null ? number_format(((int) $req['monthly_rate_cents']) / 100, 2, '.', '') : '';
    // Null-safe for output: DB NULLs would break e(string).
    $req = array_map(fn($v) => $v === null ? '' : $v, $req);
    require __DIR__ . '/_layout.php';
    ?>
    <div class="page-head">
        <h1>Request <span class="mono"><?= e($req['ref_no']) ?></span></h1>
        <div class="page-actions"><a class="btn btn-outline" href="<?= e(site_url('admin/requests.php')) ?>">Back to queue</a></div>
    </div>

    <div class="card">
        <div class="section-title" style="margin-top:0">
            <h2><?= $req['type'] === 'company' ? 'Company request' : 'Individual request' ?></h2>
            <span class="badge badge-<?= $req['status'] === 'approved' ? 'green' : ($req['status'] === 'rejected' ? 'red' : 'amber') ?>">
                <?= status_dot($req['status'] === 'approved' ? 'green' : ($req['status'] === 'rejected' ? 'red' : 'amber'), ucfirst(str_replace('_', ' ', $req['status']))) ?>
            </span>
        </div>
        <dl class="dl">
            <?php if ($req['type'] === 'company'): ?>
                <dt>Company</dt><dd><?= e($req['company_name']) ?></dd>
                <dt>Contact person</dt><dd><?= e($req['contact_person']) ?></dd>
                <dt>Billing contact</dt><dd><?= e($req['billing_contact'] ?: '—') ?> <?= $req['billing_email'] ? '(' . e($req['billing_email']) . ')' : '' ?></dd>
                <dt>Spaces</dt><dd><?= e($req['spaces'] ?: '—') ?></dd>
            <?php else: ?>
                <dt>Name</dt><dd><?= e($req['full_name']) ?></dd>
                <dt>Vehicle</dt><dd><?= e(trim(($req['vehicle_make'] ?? '') . ' ' . ($req['vehicle_model'] ?? '')) ?: '—') ?> — <span class="mono"><?= e($req['plate'] ?: '—') ?></span> <?= e($req['plate_state'] ?: '') ?></dd>
            <?php endif; ?>
            <dt>Email</dt><dd><?= e($req['email']) ?></dd>
            <dt>Phone</dt><dd><?= e($req['phone'] ?: '—') ?></dd>
            <dt>Location</dt><dd><?= e($req['location_name'] ?: '—') ?></dd>
            <dt>Desired start</dt><dd><?= $req['start_date'] ? e(fmt_date($req['start_date'])) : '—' ?></dd>
            <dt>Contact method</dt><dd><?= e($req['contact_method'] ?: '—') ?></dd>
            <?php if ($req['comments']): ?><dt>Comments</dt><dd><?= e($req['comments']) ?></dd><?php endif; ?>
            <dt>Submitted</dt><dd><?= e($req['created_at']) ?></dd>
            <?php if ($req['admin_notes']): ?><dt>Admin notes</dt><dd style="white-space:pre-line"><?= e($req['admin_notes']) ?></dd><?php endif; ?>
        </dl>

        <?php if (!in_array($req['status'], ['approved', 'rejected'], true)): ?>
            <hr class="hr-gold">
            <div class="btn-row">
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                    <input type="hidden" name="op" value="contacted">
                    <button type="submit" class="btn btn-outline btn-sm">Mark contacted</button>
                </form>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                    <input type="hidden" name="op" value="info_requested">
                    <input type="text" name="note" placeholder="Note (optional)" style="width:220px">
                    <button type="submit" class="btn btn-outline btn-sm">Request info</button>
                </form>
                <a class="btn btn-primary btn-sm" href="<?= e(site_url('admin/requests.php?view=' . $req['id'] . '&approve=1')) ?>">Approve…</a>
                <form method="post" class="inline-form" data-confirm="Reject this request? The applicant will be notified by email.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                    <input type="hidden" name="op" value="reject">
                    <input type="text" name="reason" placeholder="Reason (optional)" style="width:220px">
                    <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($show_approve): ?>
        <div class="card" id="approve">
            <h2>Approve request — provisioning</h2>
            <p class="muted">This creates the <?= $req['type'] === 'company' ? 'company + contact' : 'customer + vehicle' ?>, subscription, permit, and first invoice in one transaction.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                <input type="hidden" name="op" value="approve">
                <div class="form-grid">
                    <div class="field"><label for="start_date">Subscription start *</label><input type="date" id="start_date" name="start_date" value="<?= e($def_start) ?>" required></div>
                    <div class="field"><label for="end_date">Subscription end *</label><input type="date" id="end_date" name="end_date" value="<?= e($def_end) ?>" required><div class="hint">Defaults to +1 month; editable.</div></div>
                    <div class="field">
                        <label for="amount_dollars">First invoice amount ($) *</label>
                        <input type="number" id="amount_dollars" name="amount_dollars" value="<?= e($def_amount) ?>" min="0" step="0.01" required>
                        <?php if ($def_amount === ''): ?>
                            <div class="hint"><strong>Required:</strong> this location has no set monthly rate. Enter the agreed amount — it is never invented.</div>
                        <?php else: ?>
                            <div class="hint">Prefilled from the location's monthly rate; adjust if needed.</div>
                        <?php endif; ?>
                    </div>
                    <div class="field"><label class="check-line" style="margin-top:28px"><input type="checkbox" name="send_credentials" value="1" checked> Email portal login + temp password</label></div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary" data-confirm="Approve this request and create the customer, subscription, and invoice?">Approve &amp; provision</button>
                    <a class="btn btn-ghost" href="<?= e(site_url('admin/requests.php?view=' . $req['id'])) ?>">Cancel</a>
                </div>
            </form>
        </div>
    <?php endif; ?>
    <?php
    require __DIR__ . '/_footer.php';
    return;
}

// ---------- Queue list ----------
$where = [];
$params = [];
if ($status_f === 'open') {
    $where[] = "r.status NOT IN ('approved','rejected','cancelled')";
} elseif (in_array($status_f, ['new', 'contacted', 'info_requested', 'approved', 'rejected'], true)) {
    $where[] = 'r.status = ?';
    $params[] = $status_f;
}
if ($search !== '') {
    $where[] = '(r.ref_no LIKE ? OR r.full_name LIKE ? OR r.company_name LIKE ? OR r.email LIKE ? OR r.plate LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;
$count_st = $db->prepare("SELECT COUNT(*) FROM monthly_requests r $sql_where");
$count_st->execute($params);
$total = (int) $count_st->fetchColumn();

$offset = ($page - 1) * $per;
$st = $db->prepare(
    "SELECT r.*, l.name AS location_name FROM monthly_requests r
     LEFT JOIN parking_locations l ON l.id = r.location_id
     $sql_where ORDER BY r.created_at DESC, r.id DESC LIMIT $per OFFSET $offset"
);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$base = site_url('admin/requests.php'); // paginate() preserves the current GET filters itself

require __DIR__ . '/_layout.php';
?>

<div class="page-head"><h1>Monthly Requests</h1></div>

<form method="get" action="<?= e(site_url('admin/requests.php')) ?>" class="filter-bar">
    <div class="field">
        <label for="fq">Search</label>
        <input type="text" id="fq" name="q" value="<?= e($search) ?>" placeholder="Ref #, name, company, email, plate…">
    </div>
    <div class="field narrow">
        <label for="fstatus">Status</label>
        <select id="fstatus" name="status">
            <?php foreach (['open' => 'Open (all unprocessed)', 'new' => 'New', 'contacted' => 'Contacted', 'info_requested' => 'Info requested', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $lbl): ?>
                <option value="<?= $k ?>"<?= $status_f === $k ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/requests.php')) ?>">Clear</a>
</form>

<?php if ($rows === []): ?>
    <div class="empty"><strong>No requests found</strong>New submissions from the public request form will appear here.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Ref #</th><th>Type</th><th>Applicant</th><th>Location</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong class="mono"><?= e($r['ref_no']) ?></strong></td>
                        <td><?= $r['type'] === 'company' ? 'Company' : 'Individual' ?></td>
                        <td><strong><?= e($r['type'] === 'company' ? $r['company_name'] : $r['full_name']) ?></strong><br>
                            <span class="muted small"><?= e($r['email']) ?></span></td>
                        <td><?= e($r['location_name'] ?: '—') ?></td>
                        <td class="nowrap small"><?= e(substr($r['created_at'], 0, 10)) ?></td>
                        <td>
                            <?php
                            $sc = $r['status'] === 'approved' ? 'green' : ($r['status'] === 'rejected' ? 'red' : 'amber');
                            ?>
                            <span class="badge badge-<?= $sc ?>"><?= status_dot($sc, ucfirst(str_replace('_', ' ', $r['status']))) ?></span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/requests.php?view=' . (int) $r['id'])) ?>">Review</a>
                                <?php if (!in_array($r['status'], ['approved', 'rejected'], true)): ?>
                                    <a class="btn btn-primary btn-xs" href="<?= e(site_url('admin/requests.php?view=' . (int) $r['id'] . '&approve=1')) ?>">Approve</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginate($total, $per, $page, $base) ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
