<?php
// admin/invoices.php — invoice list (status/location filters) + new invoice form.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Invoices';
$nav_section = 'invoices';
$required_perm = 'invoices.view';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$today = today();
$can_edit = can('invoices.edit');

$action = (string) ($_GET['action'] ?? '');
$status_f = (string) ($_GET['status'] ?? 'all');
$location_f = (int) ($_GET['location'] ?? 0);
$sub_f = (int) ($_GET['sub'] ?? $_GET['subscription_id'] ?? 0);
$company_f = (int) ($_GET['company'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));

if ($status_f === 'unpaid') {
    $nav_section = 'outstanding';
    $page_title = 'Outstanding Invoices';
}

// ---------- Batch: auto-generate invoices for active subscriptions that have none ----------
// Only fills gaps (subscriptions with zero invoices) — never duplicates.
$missing_count = 0;
$missing_no_rate = 0;
if ($can_edit) {
    $missing_count = (int) $db->query(
        "SELECT COUNT(*) FROM subscriptions s
         WHERE s.status = 'active' AND s.monthly_rate_cents IS NOT NULL AND s.monthly_rate_cents > 0
           AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.subscription_id = s.id)"
    )->fetchColumn();
    $missing_no_rate = (int) $db->query(
        "SELECT COUNT(*) FROM subscriptions s
         WHERE s.status = 'active' AND (s.monthly_rate_cents IS NULL OR s.monthly_rate_cents <= 0)
           AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.subscription_id = s.id)"
    )->fetchColumn();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch'] ?? '') === 'missing' && $can_edit) {
    verify_csrf();
    $st = $db->query(
        "SELECT s.*, c.name AS customer_name, co.name AS company_name, l.name AS location_name
         FROM subscriptions s
         LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN companies co ON co.id = s.company_id
         LEFT JOIN parking_locations l ON l.id = s.location_id
         WHERE s.status = 'active' AND s.monthly_rate_cents IS NOT NULL AND s.monthly_rate_cents > 0
           AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.subscription_id = s.id)"
    );
    $made = 0;
    $db->beginTransaction();
    try {
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ms) {
            create_invoice_for_subscription($db, $ms, (int) $ms['monthly_rate_cents'], $today, date('Y-m-d', strtotime($today . ' +14 days')), (int) $user['id'], 'First invoice — batch generation');
            $made++;
        }
        $db->commit();
    } catch (Exception $ex) {
        $db->rollBack();
        flash('Batch invoice generation failed: ' . $ex->getMessage(), 'error');
        redirect(site_url('admin/invoices.php'));
    }
    flash('Auto-generated ' . $made . ' invoice(s).' . ($missing_no_rate > 0 ? ' ' . $missing_no_rate . ' skipped (no monthly rate set).' : ''), 'success');
    redirect(site_url('admin/invoices.php'));
}

// ---------- Void invoice from the list (moves to trash) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['list_op'] ?? '') === 'void' && $can_edit) {
    verify_csrf();
    $void_id = (int) ($_POST['invoice_id'] ?? 0);
    $st = $db->prepare('SELECT number, status FROM invoices WHERE id = ?');
    $st->execute([$void_id]);
    $vinv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$vinv) {
        flash('Invoice not found.', 'error');
    } elseif (in_array($vinv['status'], ['voided', 'refunded', 'paid'], true)) {
        flash('Invoice ' . $vinv['number'] . ' cannot be voided (status: ' . $vinv['status'] . ').', 'error');
    } else {
        $db->prepare("UPDATE invoices SET status = 'voided', deleted_at = datetime('now') WHERE id = ?")->execute([$void_id]);
        audit('invoice.voided', 'invoice', $void_id, ['number' => $vinv['number'], 'from' => 'list']);
        flash('Invoice ' . $vinv['number'] . ' voided and moved to trash.', 'success');
    }
    redirect(site_url('admin/invoices.php'));
}

// ---------- Restore invoice from trash ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['list_op'] ?? '') === 'restore' && $can_edit) {
    verify_csrf();
    $rid = (int) ($_POST['invoice_id'] ?? 0);
    $st = $db->prepare('SELECT number FROM invoices WHERE id = ? AND deleted_at IS NOT NULL');
    $st->execute([$rid]);
    $rinv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rinv) {
        flash('Invoice not found in trash.', 'error');
    } else {
        $db->prepare('UPDATE invoices SET deleted_at = NULL WHERE id = ?')->execute([$rid]);
        audit('invoice.restored', 'invoice', $rid, ['number' => $rinv['number'], 'from' => 'trash']);
        flash('Invoice ' . $rinv['number'] . ' restored.', 'success');
    }
    redirect(site_url('admin/invoices.php?view=trash'));
}

// ---------- Bill missed months (auto-calculates from expiry) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['list_op'] ?? '') === 'bill_missed' && $can_edit) {
    verify_csrf();
    $bm_id = (int) ($_POST['subscription_id'] ?? 0);
    $st = $db->prepare(
        'SELECT s.*, c.name AS customer_name, co.name AS company_name, l.name AS location_name
         FROM subscriptions s
         LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN companies co ON co.id = s.company_id
         LEFT JOIN parking_locations l ON l.id = s.location_id
         WHERE s.id = ? AND s.deleted_at IS NULL'
    );
    $st->execute([$bm_id]);
    $bsub = $st->fetch(PDO::FETCH_ASSOC);
    if (!$bsub) {
        flash('Subscription not found.', 'error');
        redirect(site_url('admin/invoices.php'));
    }
    if ($bsub['monthly_rate_cents'] === null || (int) $bsub['monthly_rate_cents'] <= 0) {
        flash('This subscription has no monthly rate set — cannot auto-calculate.', 'error');
        redirect(site_url('admin/invoices.php?subscription_id=' . $bm_id));
    }
    // Bill to become current: missed months + 1 (current month) so the period
    // extends past today. E.g. end=Jun 11, today=Oct 11 → bill 5 months → Nov 11.
    $today = date('Y-m-d');
    $end = $bsub['end_date'] ?: $today;
    $missed = $end < $today ? max(1, (int) floor((strtotime($today) - strtotime($end)) / (30 * 86400))) : 0;
    $bill_months = $missed + 1;
    $uc = (int) $bsub['monthly_rate_cents'];
    $who = ($bsub['customer_name'] ?? null) ?: ($bsub['company_name'] ?? null) ?: 'parker';
    $p_start = date('Y-m-d', strtotime($end . ' +1 day'));
    $p_end = date('Y-m-d', strtotime($end . ' +' . $bill_months . ' month'));
    $desc = 'Monthly parking (' . $bill_months . ' months) — ' . ($bsub['location_name'] ?? '') . ' — ' . $who
        . ' (' . fmt_date($p_start) . ' to ' . fmt_date($p_end) . ')';
    $db->beginTransaction();
    try {
        // Single invoice: update the existing unpaid one if there is one, else create.
        $ex = $db->prepare("SELECT id FROM invoices WHERE subscription_id = ? AND status NOT IN ('paid', 'voided', 'refunded') AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $ex->execute([$bm_id]);
        $inv_id = $ex->fetchColumn();
        if ($inv_id) {
            $db->prepare('UPDATE invoices SET amount_cents = ?, extend_months = ?, notes = ? WHERE id = ?')
                ->execute([$bill_months * $uc, $bill_months, 'Catch-up billing', $inv_id]);
            $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$inv_id]);
            $db->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents, amount_cents) VALUES (?,?,?,?,?)')
                ->execute([$inv_id, $desc, $bill_months, $uc, $bill_months * $uc]);
            $db->prepare("UPDATE invoices SET status = 'unpaid' WHERE id = ? AND status != 'unpaid'")->execute([$inv_id]);
            recalc_invoice((int) $inv_id);
            audit('invoice.missed_updated', 'invoice', $inv_id, ['subscription_id' => $bm_id, 'months' => $bill_months]);
            flash('Invoice updated to ' . $bill_months . ' months (' . money($bill_months * $uc) . ').', 'success');
        } else {
            $inv_id = create_invoice_for_subscription($db, $bsub, $bill_months * $uc, $today, date('Y-m-d', strtotime($today . ' +14 days')), (int) $user['id'], 'Catch-up billing');
            $db->prepare('UPDATE invoices SET extend_months = ? WHERE id = ?')->execute([$bill_months, $inv_id]);
            $db->prepare('UPDATE invoice_items SET description = ?, qty = ?, unit_cents = ?, amount_cents = ? WHERE invoice_id = ?')
                ->execute([$desc, $bill_months, $uc, $bill_months * $uc, $inv_id]);
            audit('invoice.missed_billed', 'invoice', $inv_id, ['subscription_id' => $bm_id, 'months' => $bill_months]);
            flash('Invoice created for ' . $bill_months . ' months (' . money($bill_months * $uc) . '). Extends automatically when paid.', 'success');
        }
        $db->commit();
        redirect(site_url('admin/invoice_view.php?id=' . (int) $inv_id));
    } catch (Exception $ex) {
        $db->rollBack();
        flash('Could not create invoice: ' . $ex->getMessage(), 'error');
        redirect(site_url('admin/invoices.php?subscription_id=' . $bm_id));
    }
}

// ---------- Permanently delete invoice from trash (cascade) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['list_op'] ?? '') === 'delete' && $can_edit) {
    verify_csrf();
    $did = (int) ($_POST['invoice_id'] ?? 0);
    $st = $db->prepare('SELECT number FROM invoices WHERE id = ? AND deleted_at IS NOT NULL');
    $st->execute([$did]);
    $dinv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$dinv) {
        flash('Invoice not found in trash.', 'error');
    } else {
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$did]);
            $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$did]);
            $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$did]);
            $db->commit();
            audit('invoice.deleted', 'invoice', $did, ['number' => $dinv['number'], 'permanent' => true]);
            flash('Invoice ' . $dinv['number'] . ' permanently deleted.', 'success');
        } catch (Exception $ex) {
            $db->rollBack();
            flash('Could not delete: ' . $ex->getMessage(), 'error');
        }
    }
    redirect(site_url('admin/invoices.php?view=trash'));
}

// ---------- New invoice form + handler ----------
if ($action === 'new') {
    if (!$can_edit) {
        flash('You do not have permission to create invoices.', 'error');
        redirect(site_url('admin/invoices.php'));
    }
    $page_title = 'New Invoice';

    // Dropdown options: active subscriptions (for picker) and companies.
    $sub_options = $db->query(
        "SELECT s.id, COALESCE(c.name, co.name, '—') AS who, l.name AS loc, v.plate
         FROM subscriptions s
         LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN companies co ON co.id = s.company_id
         LEFT JOIN parking_locations l ON l.id = s.location_id
         LEFT JOIN vehicles v ON v.id = s.vehicle_id
         WHERE s.deleted_at IS NULL AND s.status != 'cancelled'
         ORDER BY who, l.name"
    )->fetchAll(PDO::FETCH_ASSOC);
    $co_options = $db->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Prefill source: subscription or company.
    $src_sub = null;
    $src_company = null;
    if ($sub_f > 0) {
        $st = $db->prepare(
            'SELECT s.*, c.name AS customer_name, co.name AS company_name, l.name AS location_name
             FROM subscriptions s
             LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN companies co ON co.id = s.company_id
             LEFT JOIN parking_locations l ON l.id = s.location_id
             WHERE s.id = ?'
        );
        $st->execute([$sub_f]);
        $src_sub = $st->fetch(PDO::FETCH_ASSOC);
    }
    if ($company_f > 0) {
        $st = $db->prepare('SELECT * FROM companies WHERE id = ?');
        $st->execute([$company_f]);
        $src_company = $st->fetch(PDO::FETCH_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf(); // dies with 403 on mismatch
        $sub_id = (int) ($_POST['subscription_id'] ?? 0) ?: null;
        $co_id = (int) ($_POST['company_id'] ?? 0) ?: null;
        $bill_name = trim((string) ($_POST['bill_name'] ?? ''));
        $bill_email = trim((string) ($_POST['bill_email'] ?? ''));
        $issue = trim((string) ($_POST['issue_date'] ?? $today));
        $due = trim((string) ($_POST['due_date'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $descs = $_POST['item_desc'] ?? [];
        $qtys = $_POST['item_qty'] ?? [];
        $units = $_POST['item_unit'] ?? [];
        $errs = [];
        if (!$sub_id && !$co_id && $bill_name === '') {
            $errs[] = 'Bill this invoice to a subscription, a company, or enter a custom bill-to name.';
        }
        if ($bill_email !== '' && !filter_var($bill_email, FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'Bill-to email is not a valid email address.';
        }
        $bill_months = max(1, min(24, (int) ($_POST['bill_months'] ?? 1)));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) {
            $errs[] = 'Issue date is required.';
        }
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            $errs[] = 'Due date must be YYYY-MM-DD.';
        }
        $items = [];
        $n = max(count($descs), count($qtys), count($units));
        for ($i = 0; $i < $n; $i++) {
            $d = trim((string) ($descs[$i] ?? ''));
            $q = (int) ($qtys[$i] ?? 0);
            $u = trim((string) ($units[$i] ?? ''));
            if ($d === '' && $q <= 0 && $u === '') {
                continue;
            }
            if ($d === '' || $q <= 0 || $u === '' || !is_numeric($u) || (float) $u < 0) {
                $errs[] = 'Each line item needs a description, a quantity of at least 1, and a non-negative unit price.';
                break;
            }
            $uc = (int) round((float) $u * 100);
            $items[] = ['desc' => $d, 'qty' => $q, 'unit_cents' => $uc, 'amount_cents' => $q * $uc];
        }
        if ($items === [] && $errs === []) {
            // Auto-generate from subscription monthly rate × months when no manual items.
            if ($sub_id) {
                $rs = $db->prepare('SELECT s.monthly_rate_cents, s.end_date, l.name AS location_name, COALESCE(c.name, co.name, ?) AS who FROM subscriptions s LEFT JOIN customers c ON c.id = s.customer_id LEFT JOIN companies co ON co.id = s.company_id LEFT JOIN parking_locations l ON l.id = s.location_id WHERE s.id = ?');
                $rs->execute(['parker', $sub_id]);
                $subinfo = $rs->fetch(PDO::FETCH_ASSOC);
                if ($subinfo && $subinfo['monthly_rate_cents'] !== null && (int) $subinfo['monthly_rate_cents'] > 0) {
                    $uc = (int) $subinfo['monthly_rate_cents'];
                    $base = !empty($subinfo['end_date']) && $subinfo['end_date'] >= $today ? $subinfo['end_date'] : $today;
                    $p_start = date('Y-m-d', strtotime($base . ' +1 day'));
                    $p_end = date('Y-m-d', strtotime($base . ' +' . $bill_months . ' month'));
                    $items[] = ['desc' => 'Monthly parking' . ($bill_months > 1 ? " ({$bill_months} months)" : '') . ' — ' . ($subinfo['location_name'] ?? '') . ' — ' . $subinfo['who'] . ' (' . fmt_date($p_start) . ' to ' . fmt_date($p_end) . ')', 'qty' => $bill_months, 'unit_cents' => $uc, 'amount_cents' => $bill_months * $uc];
                } else {
                    $errs[] = 'Add at least one line item (this subscription has no monthly rate set).';
                }
            } else {
                $errs[] = 'Add at least one line item.';
            }
        }

        // Resolve customer/company for the invoice header.
        $cust_id = null;
        if ($sub_id) {
            $rs = $db->prepare('SELECT customer_id, company_id FROM subscriptions WHERE id = ?');
            $rs->execute([$sub_id]);
            $rr = $rs->fetch(PDO::FETCH_ASSOC);
            if (!$rr) {
                $errs[] = 'Subscription not found.';
            } else {
                $cust_id = $rr['customer_id'] ?: null;
                if (!$co_id) {
                    $co_id = $rr['company_id'] ?: null;
                }
            }
        }

        if ($errs === []) {
            $total_cents = array_sum(array_column($items, 'amount_cents'));
            $db->beginTransaction();
            try {
                $number = new_invoice_number();
                $token = secure_token();
                $db->prepare(
                    'INSERT INTO invoices (number, subscription_id, company_id, customer_id, bill_name, bill_email, issue_date, due_date, amount_cents, amount_paid_cents, status, payment_token, created_by, notes, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,0,\'unpaid\',?,?,?,CURRENT_TIMESTAMP)'
                )->execute([$number, $sub_id, $co_id, $cust_id, $bill_name ?: null, $bill_email ?: null, $issue, $due ?: null, $total_cents, $token, $user['id'], $notes]);
                $inv_id = (int) $db->lastInsertId();
                $ii = $db->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents, amount_cents) VALUES (?,?,?,?,?)');
                foreach ($items as $it) {
                    $ii->execute([$inv_id, $it['desc'], $it['qty'], $it['unit_cents'], $it['amount_cents']]);
                }
                // Link extension: subscription invoices auto-extend by bill_months when paid.
                if ($sub_id && isset($bill_months) && $bill_months > 0) {
                    $db->prepare('UPDATE invoices SET extend_months = ? WHERE id = ?')->execute([$bill_months, $inv_id]);
                }
                $db->commit();
                audit('invoice.created', 'invoice', $inv_id, ['number' => $number, 'amount_cents' => $total_cents]);
                flash('Invoice ' . $number . ' created.', 'success');
                redirect(site_url('admin/invoice_view.php?id=' . $inv_id));
            } catch (Exception $ex) {
                $db->rollBack();
                flash('Invoice creation failed: ' . $ex->getMessage(), 'error');
                redirect(site_url('admin/invoices.php?action=new'));
            }
        } else {
            foreach ($errs as $er) {
                flash($er, 'error');
            }
            redirect(site_url('admin/invoices.php?action=new' . ($sub_f ? '&sub=' . $sub_f : '') . ($company_f ? '&company=' . $company_f : '')));
        }
    }

require __DIR__ . '/_layout.php';
    $def_desc = '';
    $def_unit = '';
    if ($src_sub) {
        $who = $src_sub['customer_name'] ?: $src_sub['company_name'];
        $def_desc = 'Monthly parking — ' . $src_sub['location_name'] . ' — ' . $who . ' (' . fmt_date($src_sub['start_date']) . ' to ' . fmt_date($src_sub['end_date']) . ')';
        $def_unit = $src_sub['monthly_rate_cents'] !== null ? number_format(((int) $src_sub['monthly_rate_cents']) / 100, 2, '.', '') : '';
    } elseif ($src_company) {
        $def_desc = 'Monthly parking — ' . $src_company['name'];
        $def_unit = $src_company['rate_cents'] !== null ? number_format(((int) $src_company['rate_cents']) / 100, 2, '.', '') : '';
    }
    ?>
    <div class="page-head">
        <h1>New Invoice</h1>
        <div class="page-actions"><a class="btn btn-outline" href="<?= e(site_url('admin/invoices.php')) ?>">Back to list</a></div>
    </div>
    <form method="post" class="card">
        <?= csrf_field() ?>
        <h3 style="margin-top:0">Bill to</h3>
        <p class="muted small">Pick a parker or a company — or leave both blank and enter a custom name below for a one-off invoice.</p>
        <div class="form-grid">
            <div class="field">
                <label for="subscription_id">Parker / subscription</label>
                <select id="subscription_id" name="subscription_id">
                    <option value="0">— Select parker —</option>
                    <?php foreach ($sub_options as $o): ?>
                        <option value="<?= (int) $o['id'] ?>"<?= ($src_sub && (int) $src_sub['id'] === (int) $o['id']) ? ' selected' : '' ?>><?= e($o['who'] . ' — ' . ($o['loc'] ?: 'no location') . ($o['plate'] ? ' (' . $o['plate'] . ')' : '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="company_id">Company</label>
                <select id="company_id" name="company_id">
                    <option value="0">— Select company —</option>
                    <?php foreach ($co_options as $o): ?>
                        <option value="<?= (int) $o['id'] ?>"<?= ($src_company && (int) $src_company['id'] === (int) $o['id']) ? ' selected' : '' ?>><?= e($o['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="bill_name">Custom bill-to name</label>
                <input type="text" id="bill_name" name="bill_name" maxlength="120" placeholder="e.g. John Smith">
                <div class="hint">For one-off invoices not tied to a parker or company.</div>
            </div>
            <div class="field">
                <label for="bill_email">Custom bill-to email</label>
                <input type="email" id="bill_email" name="bill_email" maxlength="160" placeholder="Where to send the invoice">
            </div>
        </div>
        <hr class="hr-gold">
        <div class="form-grid">
            <div class="field">
                <label for="bill_months">Billing months</label>
                <input type="number" id="bill_months" name="bill_months" value="1" min="1" max="24" step="1">
                <div class="hint">For a parker: covers this many months at their monthly rate, and extends the subscription by this many months when paid. <span id="missed_hint"></span></div>
            </div>
            <div class="field"><label for="issue_date">Issue date *</label><input type="date" id="issue_date" name="issue_date" value="<?= e($today) ?>" required></div>
            <div class="field"><label for="due_date">Due date</label><input type="date" id="due_date" name="due_date" value="<?= e(date('Y-m-d', strtotime($today . ' +14 days'))) ?>"></div>
            <div class="field full"><label for="notes">Notes</label><input type="text" id="notes" name="notes" maxlength="500"></div>
        </div>
        <script>
        (function() {
            var subs = <?= json_encode(array_map(function($o) { return ['id' => (int)$o['id'], 'rate' => $o['monthly_rate_cents'] !== null ? ((int)$o['monthly_rate_cents']/100) : null, 'end' => $o['end_date'] ?? '']; }, $db->query("SELECT s.id, s.monthly_rate_cents, s.end_date FROM subscriptions s WHERE s.deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC))) ?>;
            var subSel = document.getElementById('subscription_id');
            var monthsEl = document.getElementById('bill_months');
            var hint = document.getElementById('missed_hint');
            function upd() {
                var id = parseInt(subSel.value || '0', 10);
                var s = null;
                for (var i = 0; i < subs.length; i++) { if (subs[i].id === id) { s = subs[i]; break; } }
                if (!s || !s.end) { hint.textContent = ''; return; }
                var end = new Date(s.end + 'T12:00:00');
                var now = new Date();
                if (end >= now) { hint.textContent = ''; return; }
                var missed = Math.max(1, Math.round((now - end) / (30.44 * 86400000)));
                hint.textContent = 'This parker missed ~' + missed + ' month(s).';
                if (monthsEl && (monthsEl.value === '1' || monthsEl.value === '')) { monthsEl.value = missed; }
            }
            if (subSel) { subSel.addEventListener('change', upd); upd(); }
        })();
        </script>
        <h3>Line items</h3>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Description</th><th style="width:90px">Qty</th><th style="width:150px">Unit price ($)</th></tr></thead>
                <tbody>
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <tr>
                            <td><input type="text" name="item_desc[]" value="<?= $i === 0 ? e($def_desc) : '' ?>" maxlength="255"></td>
                            <td><input type="number" name="item_qty[]" value="<?= $i === 0 && $def_desc !== '' ? '1' : '' ?>" min="0" step="1"></td>
                            <td><input type="number" name="item_unit[]" value="<?= $i === 0 ? e($def_unit) : '' ?>" min="0" step="0.01"></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">Amounts are taken from the subscription/company rate where known, or entered by you — never invented.</p>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Create invoice</button>
            <a class="btn btn-ghost" href="<?= e(site_url('admin/invoices.php')) ?>">Cancel</a>
        </div>
    </form>
    <?php
    require __DIR__ . '/_footer.php';
    return;
}

// ---------- List ----------
$view = ($_GET['view'] ?? '') === 'trash' ? 'trash' : 'active';
$where = [];
$params = [];
if ($view === 'trash') {
    $where[] = 'i.deleted_at IS NOT NULL';
} else {
    $where[] = 'i.deleted_at IS NULL';
}
if ($status_f === 'unpaid') {
    $where[] = "i.status IN ('unpaid','pending','partially_paid','overdue')";
} elseif (in_array($status_f, ['paid', 'partially_paid', 'pending', 'overdue', 'refunded', 'voided'], true)) {
    $where[] = 'i.status = ?';
    $params[] = $status_f;
}
if ($location_f > 0) {
    $where[] = 's.location_id = ?';
    $params[] = $location_f;
}
if ($sub_f > 0) {
    $where[] = 'i.subscription_id = ?';
    $params[] = $sub_f;
}
if ($company_f > 0) {
    $where[] = 'i.company_id = ?';
    $params[] = $company_f;
}
if ($search !== '') {
    $where[] = '(i.number LIKE ? OR c.name LIKE ? OR co.name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;

$count_st = $db->prepare(
    "SELECT COUNT(*) FROM invoices i
     LEFT JOIN subscriptions s ON s.id = i.subscription_id
     LEFT JOIN customers c ON c.id = i.customer_id
     LEFT JOIN companies co ON co.id = i.company_id
     $sql_where"
);
$count_st->execute($params);
$total = (int) $count_st->fetchColumn();

$sum_where = ($sql_where !== '' ? $sql_where . ' AND ' : 'WHERE ') . "i.status IN ('unpaid','pending','partially_paid','overdue')";
$sum_st = $db->prepare(
    "SELECT COALESCE(SUM(i.amount_cents - i.amount_paid_cents),0) FROM invoices i
     LEFT JOIN subscriptions s ON s.id = i.subscription_id
     LEFT JOIN customers c ON c.id = i.customer_id
     LEFT JOIN companies co ON co.id = i.company_id
     $sum_where"
);
$sum_st->execute($params);
$outstanding = (int) $sum_st->fetchColumn();

$offset = ($page - 1) * $per;
$st = $db->prepare(
    "SELECT i.*, c.name AS customer_name, co.name AS company_name, l.name AS location_name
     FROM invoices i
     LEFT JOIN subscriptions s ON s.id = i.subscription_id
     LEFT JOIN parking_locations l ON l.id = s.location_id
     LEFT JOIN customers c ON c.id = i.customer_id
     LEFT JOIN companies co ON co.id = i.company_id
     $sql_where
     ORDER BY i.created_at DESC, i.id DESC
     LIMIT $per OFFSET $offset"
);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$locations = $db->query('SELECT id, name FROM parking_locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// ---------- Billing context card: when opened from a parker's "Payments" link,
// show the individual/company details above their invoices. ----------
$bill_ctx = null;
if ($sub_f > 0) {
    $st = $db->prepare(
        'SELECT s.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                co.name AS company_name, co.billing_email AS company_billing_email, co.billing_phone AS company_billing_phone,
                co.contact_name AS company_contact, l.name AS location_name
         FROM subscriptions s
         LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN companies co ON co.id = s.company_id
         LEFT JOIN parking_locations l ON l.id = s.location_id
         WHERE s.id = ?'
    );
    $st->execute([$sub_f]);
    $sub = $st->fetch(PDO::FETCH_ASSOC);
    if ($sub) {
        $veh_st = $db->prepare(
            'SELECT make, model, plate, plate_state FROM vehicles
             WHERE customer_id = ? OR company_id = ?
             ORDER BY id LIMIT 10'
        );
        $veh_st->execute([$sub['customer_id'] ?: 0, $sub['company_id'] ?: 0]);
        $bill_ctx = [
            'kind'      => $sub['company_id'] ? 'company' : 'individual',
            'sub_id'    => (int) $sub['id'],
            'name'      => $sub['company_name'] ?: $sub['customer_name'],
            'email'     => $sub['company_billing_email'] ?: $sub['customer_email'],
            'phone'     => $sub['company_billing_phone'] ?: $sub['customer_phone'],
            'contact'   => $sub['company_contact'] ?: null,
            'location'  => $sub['location_name'],
            'start'     => $sub['start_date'],
            'end'       => $sub['end_date'],
            'rate'      => $sub['monthly_rate_cents'],
            'status'    => $sub['status'],
            'vehicles'  => $veh_st->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
} elseif ($company_f > 0) {
    $st = $db->prepare(
        'SELECT co.*, l.name AS location_name,
                (SELECT COUNT(*) FROM vehicles v WHERE v.company_id = co.id) AS vehicle_count
         FROM companies co
         LEFT JOIN parking_locations l ON l.id = co.location_id
         WHERE co.id = ?'
    );
    $st->execute([$company_f]);
    $co = $st->fetch(PDO::FETCH_ASSOC);
    if ($co) {
        $bill_ctx = [
            'kind'      => 'company',
            'name'      => $co['name'],
            'email'     => $co['billing_email'] ?: $co['email'],
            'phone'     => $co['billing_phone'] ?: $co['phone'],
            'contact'   => $co['contact_name'] ?: $co['billing_contact'],
            'location'  => $co['location_name'],
            'start'     => $co['contract_start'],
            'end'       => $co['contract_end'],
            'rate'      => $co['rate_cents'],
            'status'    => $co['status'],
            'vehicles'  => [],
            'vehicle_count' => (int) $co['vehicle_count'],
            'spaces'    => $co['spaces'],
        ];
    }
}

$base = site_url('admin/invoices.php'); // paginate() preserves the current GET filters itself

function mv_inv_badge(string $status): string
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
    $label = ucfirst(str_replace('_', ' ', $status));
    return '<span class="badge badge-' . $color . '">' . status_dot($color, $label) . '</span>';
}

// ---------- Summary cards ----------
$stats = [
    'invoiced'    => (int) $db->query("SELECT COALESCE(SUM(amount_cents),0) FROM invoices WHERE status NOT IN ('voided','refunded')")->fetchColumn(),
    'collected'   => (int) $db->query("SELECT COALESCE(SUM(amount_paid_cents),0) FROM invoices WHERE status NOT IN ('voided','refunded')")->fetchColumn(),
    'outstanding' => (int) $db->query("SELECT COALESCE(SUM(amount_cents - amount_paid_cents),0) FROM invoices WHERE status IN ('unpaid','pending','partially_paid','overdue')")->fetchColumn(),
    'overdue_n'   => (int) $db->query("SELECT COUNT(*) FROM invoices WHERE status = 'overdue'")->fetchColumn(),
    'month_n'     => 0,
    'month_cents' => 0,
    'renewals'    => 0,
];
$mr = $db->prepare("SELECT COUNT(*), COALESCE(SUM(amount_cents),0) FROM payments WHERE paid_at >= ? AND amount_cents > 0");
$mr->execute([substr($today, 0, 7) . '-01 00:00:00']);
[$stats['month_n'], $stats['month_cents']] = array_map('intval', (array) $mr->fetch(PDO::FETCH_NUM));
$stats['renewals'] = (int) $db->query(
    "SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND end_date BETWEEN " . $db->quote($today) . " AND date(" . $db->quote($today) . ", '+14 days')"
)->fetchColumn();

// ---------- CSV export of the current page of invoices ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fname = 'invoices-' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Invoice #', 'Customer/Company', 'Location', 'Issue date', 'Due date', 'Amount', 'Paid', 'Balance', 'Status']);
    foreach ($rows as $r) {
        $who = ($r['customer_name'] ?? '') !== '' ? (string) $r['customer_name'] : (string) ($r['company_name'] ?? '');
        fputcsv($o, [
            $r['number'], $who, $r['location_name'] ?? '', $r['issue_date'] ?? '', $r['due_date'] ?? '',
            number_format(((int) $r['amount_cents']) / 100, 2),
            number_format(((int) $r['amount_paid_cents']) / 100, 2),
            number_format(((int) $r['amount_cents'] - (int) $r['amount_paid_cents']) / 100, 2),
            $r['status'],
        ]);
    }
    fclose($o);
    exit;
}

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1><?= e($page_title) ?></h1>
    <div class="page-actions">
        <?php $trash_count = (int) $db->query("SELECT COUNT(*) FROM invoices WHERE deleted_at IS NOT NULL")->fetchColumn(); ?>
        <a class="btn btn-sm <?= $view === 'active' ? 'btn-primary' : 'btn-outline' ?>" href="<?= e(site_url('admin/invoices.php')) ?>">Active</a>
        <a class="btn btn-sm <?= $view === 'trash' ? 'btn-primary' : 'btn-outline' ?>" href="<?= e(site_url('admin/invoices.php?view=trash')) ?>">Trash<?= $trash_count > 0 ? ' (' . $trash_count . ')' : '' ?></a>
        <?php if ($can_edit && $missing_count > 0): ?>
            <form method="post" action="<?= e(site_url('admin/invoices.php')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="batch" value="missing">
                <button type="submit" class="btn btn-outline btn-sm" data-confirm="Auto-generate <?= (int) $missing_count ?> missing invoice(s)?">Generate missing (<?= (int) $missing_count ?>)</button>
            </form>
        <?php endif; ?>
        <?php if ($can_edit): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(site_url('admin/invoices.php?action=new')) ?>">+ New invoice</a>
        <?php endif; ?>
        <a class="btn btn-outline btn-sm" href="<?= e(site_url('admin/invoices.php') . '?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a>
    </div>
</div>

<div class="kpi-grid" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-label">Total invoiced</div><div class="kpi-value"><?= e(money($stats['invoiced'])) ?></div></div>
    <div class="kpi green"><div class="kpi-label">Total collected</div><div class="kpi-value"><?= e(money($stats['collected'])) ?></div></div>
    <div class="kpi gold"><div class="kpi-label">Outstanding balance</div><div class="kpi-value"><?= e(money($stats['outstanding'])) ?></div></div>
    <div class="kpi"><div class="kpi-label">Overdue invoices</div><div class="kpi-value"><?= (int) $stats['overdue_n'] ?></div></div>
    <div class="kpi"><div class="kpi-label">Collected this month</div><div class="kpi-value"><?= e(money($stats['month_cents'])) ?></div><div class="kpi-sub"><?= (int) $stats['month_n'] ?> payments</div></div>
    <div class="kpi"><div class="kpi-label">Renewals due (14 days)</div><div class="kpi-value"><?= (int) $stats['renewals'] ?></div></div>
</div>

<?php if ($bill_ctx): ?>
    <div class="card">
        <div class="section-title" style="margin-top:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <h2 style="margin:0">Billing — <?= e($bill_ctx['name'] ?: '—') ?></h2>
            <span class="badge badge-navy"><?= e(ucfirst($bill_ctx['kind'])) ?></span>
            <?php if (!empty($bill_ctx['end'])): ?>
                <?php $bdg = days_badge($bill_ctx['end']); ?>
                <span class="badge badge-<?= e($bdg['color']) ?>"><?= status_dot($bdg['color'], $bdg['label']) ?></span>
            <?php endif; ?>
        </div>
        <div class="grid-2">
            <div>
                <dl class="dl">
                    <?php if ($bill_ctx['contact']): ?><dt>Contact</dt><dd><?= e($bill_ctx['contact']) ?></dd><?php endif; ?>
                    <?php if ($bill_ctx['email']): ?><dt>Email</dt><dd><?= e($bill_ctx['email']) ?></dd><?php endif; ?>
                    <?php if ($bill_ctx['phone']): ?><dt>Phone</dt><dd><?= e($bill_ctx['phone']) ?></dd><?php endif; ?>
                    <?php if ($bill_ctx['location']): ?><dt>Location</dt><dd><?= e($bill_ctx['location']) ?></dd><?php endif; ?>
                </dl>
            </div>
            <div>
                <dl class="dl">
                    <?php if ($bill_ctx['start'] || $bill_ctx['end']): ?>
                        <dt>Period</dt><dd><?= e(fmt_date($bill_ctx['start']) . ($bill_ctx['end'] ? ' → ' . fmt_date($bill_ctx['end']) : '')) ?></dd>
                    <?php endif; ?>
                    <?php if ($bill_ctx['rate'] !== null): ?><dt>Monthly rate</dt><dd><?= e(money((int) $bill_ctx['rate'])) ?></dd><?php endif; ?>
                    <?php if (!empty($bill_ctx['end']) && $bill_ctx['end'] < date('Y-m-d')):
                        $miss_n = max(1, (int) floor((strtotime(date('Y-m-d')) - strtotime($bill_ctx['end'])) / (30 * 86400)));
                        $next_end = date('Y-m-d', strtotime($bill_ctx['end'] . ' +1 month')); ?>
                        <dt>Missed months</dt><dd><strong><?= $miss_n ?></strong> <span class="muted">(<?= e(fmt_date($bill_ctx['end'])) ?> → <?= e(fmt_date(date('Y-m-d'))) ?>)</span></dd>
                        <dt>Next payment extends to</dt><dd><?= e(fmt_date($next_end)) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($bill_ctx['vehicles'])): ?>
                        <dt>Vehicle<?= count($bill_ctx['vehicles']) > 1 ? 's' : '' ?></dt>
                        <dd><?php foreach ($bill_ctx['vehicles'] as $vv): ?>
                            <span class="mono"><?= e($vv['plate']) ?></span><?= $vv['plate_state'] ? ' ' . e($vv['plate_state']) : '' ?><?= ($vv['make'] || $vv['model']) ? ' — ' . e(trim($vv['make'] . ' ' . $vv['model'])) : '' ?><br>
                        <?php endforeach; ?></dd>
                    <?php elseif (isset($bill_ctx['vehicle_count'])): ?>
                        <dt>Vehicles</dt><dd><?= (int) $bill_ctx['vehicle_count'] ?><?php if (!empty($bill_ctx['spaces'])): ?> <span class="muted">/ <?= (int) $bill_ctx['spaces'] ?> spaces</span><?php endif; ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($bill_ctx['status'])): ?><dt>Account status</dt><dd><?= e(ucfirst($bill_ctx['status'])) ?></dd><?php endif; ?>
                </dl>
            </div>
        </div>
        <?php if ($can_edit && !empty($bill_ctx['sub_id']) && !empty($bill_ctx['end']) && $bill_ctx['end'] < date('Y-m-d') && $bill_ctx['rate'] !== null && (int) $bill_ctx['rate'] > 0):
            $bm_missed = max(1, (int) floor((strtotime(date('Y-m-d')) - strtotime($bill_ctx['end'])) / (30 * 86400)));
            $bm_total = $bm_missed + 1; // missed + current month to be valid through next month
            $bm_owed = $bm_missed * (int) $bill_ctx['rate'];
            $bm_total_amt = $bm_total * (int) $bill_ctx['rate'];
            $bm_valid_thru = date('Y-m-d', strtotime($bill_ctx['end'] . ' +' . $bm_total . ' month')); ?>
            <div style="margin-top:12px">
                <form method="post" style="display:inline" data-confirm="Missed <?= $bm_missed ?> month(s) (<?= e(money($bm_owed)) ?>). To park with a valid period through <?= e(fmt_date($bm_valid_thru)) ?>, bill <?= $bm_total ?> month(s) (<?= e(money($bm_total_amt)) ?>)?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="list_op" value="bill_missed">
                    <input type="hidden" name="subscription_id" value="<?= (int) $bill_ctx['sub_id'] ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Bill <?= $bm_total ?> months (<?= e(money($bm_total_amt)) ?>) — valid through <?= e(fmt_date($bm_valid_thru)) ?></button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($status_f === 'unpaid'): ?>
    <div class="kpi-grid">
        <div class="kpi red"><div class="kpi-label">Total outstanding</div><div class="kpi-value"><?= e(money($outstanding)) ?></div>
            <div class="kpi-sub"><?= $total ?> open invoice(s)</div></div>
    </div>
<?php endif; ?>

<form method="get" action="<?= e(site_url('admin/invoices.php')) ?>" class="filter-bar">
    <div class="field">
        <label for="fq">Search</label>
        <input type="text" id="fq" name="q" value="<?= e($search) ?>" placeholder="Invoice #, customer, company…">
    </div>
    <div class="field narrow">
        <label for="fstatus">Status</label>
        <select id="fstatus" name="status">
            <?php
            $opts = ['all' => 'All', 'unpaid' => 'Outstanding (all open)', 'pending' => 'Pending', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'overdue' => 'Overdue', 'refunded' => 'Refunded', 'voided' => 'Voided'];
            foreach ($opts as $k => $lbl):
            ?>
                <option value="<?= $k ?>"<?= $status_f === $k ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field narrow">
        <label for="floc">Location</label>
        <select id="floc" name="location">
            <option value="0">All</option>
            <?php foreach ($locations as $l): ?>
                <option value="<?= (int) $l['id'] ?>"<?= $location_f === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/invoices.php')) ?>">Clear</a>
</form>

<?php if ($rows === []): ?>
    <div class="empty"><strong>No invoices found</strong>Try adjusting the filters.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Number</th><th>Bill to</th><th>Location</th><th>Issued</th><th>Due</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong class="mono"><?= e($r['number']) ?></strong></td>
                        <td><?= e($r['customer_name'] ?: $r['company_name'] ?: '—') ?></td>
                        <td><?= e($r['location_name'] ?: '—') ?></td>
                        <td class="nowrap"><?= e(fmt_date($r['issue_date'])) ?></td>
                        <td class="nowrap"><?= e(fmt_date($r['due_date'])) ?></td>
                        <td class="nowrap"><?= e(money((int) $r['amount_cents'])) ?></td>
                        <td class="nowrap"><?= e(money((int) $r['amount_paid_cents'])) ?></td>
                        <td class="nowrap"><strong><?= e(money((int) $r['amount_cents'] - (int) $r['amount_paid_cents'])) ?></strong></td>
                        <td><?= mv_inv_badge($r['status']) ?></td>
                        <td class="nowrap"><a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/invoice_view.php?id=' . (int) $r['id'])) ?>">View</a><?php if ($can_edit && $view === 'trash'): ?>
                            <form method="post" style="display:inline" data-confirm="Restore invoice <?= e($r['number']) ?>?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="list_op" value="restore">
                                <input type="hidden" name="invoice_id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-xs">Restore</button>
                            </form>
                            <form method="post" style="display:inline" data-confirm="PERMANENTLY delete invoice <?= e($r['number']) ?>? This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="list_op" value="delete">
                                <input type="hidden" name="invoice_id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-xs" style="color:#b91c1c">Delete</button>
                            </form><?php elseif ($can_edit && !in_array($r['status'], ['voided', 'refunded', 'paid'], true)): ?>
                            <form method="post" style="display:inline" data-confirm="Void invoice <?= e($r['number']) ?>? It moves to trash but stays in history.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="list_op" value="void">
                                <input type="hidden" name="invoice_id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-xs">Void</button>
                            </form><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginate($total, $per, $page, $base) ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
