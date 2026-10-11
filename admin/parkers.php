<?php
// admin/parkers.php — Monthly Parker Table: search/filter/sort, renew, move, export.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Monthly Parkers';
$is_residents_view = (($_GET['category'] ?? '') === 'resident');
$nav_section = $is_residents_view ? 'residents' : 'parkers';
$page_title = $is_residents_view ? 'Resident Parking' : 'Monthly Parkers';
$required_perm = 'parkers.view';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$today = today();
$can_edit = can('parkers.edit');

// ---------- Renew / Move actions (forms + handlers) ----------
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$sub_id = (int) ($_GET['id'] ?? 0);

function mv_parker_sub(PDO $db, int $id): ?array
{
    $st = $db->prepare(
        'SELECT s.*, l.name AS location_name, l.monthly_rate_cents,
                c.name AS customer_name, c.email AS customer_email,
                co.name AS company_name, v.plate, v.make, v.model
         FROM subscriptions s
         LEFT JOIN parking_locations l ON l.id = s.location_id
         LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN companies co ON co.id = s.company_id
         LEFT JOIN vehicles v ON v.id = s.vehicle_id
         WHERE s.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Handle renew POST.
// (Invoice creation goes through create_invoice_for_subscription() in lib/billing.php,
// shared with parker creation and the missing-invoice batch.)
// Handle move POST.
if ($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    verify_csrf();
    $sub = mv_parker_sub($db, (int) ($_POST['id'] ?? 0));
    $new_loc = (int) ($_POST['location_id'] ?? 0);
    $ls = $db->prepare('SELECT id, name FROM parking_locations WHERE id = ?');
    $ls->execute([$new_loc]);
    $newloc = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$sub || !$newloc) {
        flash('Subscription or location not found.', 'error');
        redirect(site_url('admin/parkers.php'));
    }
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE subscriptions SET location_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$new_loc, $sub['id']]);
        $db->prepare('UPDATE permits SET location_id = ? WHERE vehicle_id = ? AND status = \'active\'')
            ->execute([$new_loc, $sub['vehicle_id']]);
        $db->commit();
        audit('subscription.location_changed', 'subscription', $sub['id'], ['from' => $sub['location_name'], 'to' => $newloc['name']]);
        flash('Parker moved to ' . $newloc['name'] . '.', 'success');
    } catch (Exception $ex) {
        $db->rollBack();
        flash('Move failed: ' . $ex->getMessage(), 'error');
    }
    redirect(site_url('admin/parkers.php'));
}

// Handle delete POST.
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit) {
        flash('You do not have permission to delete parkers (parkers.edit).', 'error');
        redirect(site_url('admin/parkers.php'));
    }
    verify_csrf();
    $sub = mv_parker_sub($db, (int) ($_POST['id'] ?? 0));
    if (!$sub) {
        flash('Subscription not found.', 'error');
    } else {
        $db->beginTransaction();
        try {
            $sid = (int) $sub['id'];
            $vid = $sub['vehicle_id'] ? (int) $sub['vehicle_id'] : null;
            $cid = $sub['customer_id'] ? (int) $sub['customer_id'] : null;
            $who = $sub['customer_name'] ?: $sub['company_name'] ?: ('#' . $sid);

            // Delete the subscription's invoices entirely (items + payments
            // first, then the invoice itself) — parker delete takes invoices
            // with it.
            $inv = $db->prepare('SELECT id FROM invoices WHERE subscription_id = ?');
            $inv->execute([$sid]);
            foreach ($inv->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                $iid = (int) $iid;
                $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$iid]);
                $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
                $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
            }
            $db->prepare('DELETE FROM subscriptions WHERE id = ?')->execute([$sid]);
            if ($vid) {
                $db->prepare('DELETE FROM permits WHERE vehicle_id = ?')->execute([$vid]);
                $c = $db->prepare('SELECT COUNT(*) FROM subscriptions WHERE vehicle_id = ?');
                $c->execute([$vid]);
                if ((int) $c->fetchColumn() === 0) {
                    $db->prepare('DELETE FROM vehicles WHERE id = ?')->execute([$vid]);
                }
            }
            if ($cid) {
                $c = $db->prepare('SELECT COUNT(*) FROM subscriptions WHERE customer_id = ?');
                $c->execute([$cid]);
                if ((int) $c->fetchColumn() === 0) {
                    // Delete any remaining invoices of this customer entirely.
                    $inv = $db->prepare('SELECT id FROM invoices WHERE customer_id = ?');
                    $inv->execute([$cid]);
                    foreach ($inv->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                        $iid = (int) $iid;
                        $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$iid]);
                        $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
                        $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
                    }
                    $db->prepare('DELETE FROM permits WHERE customer_id = ?')->execute([$cid]);
                    $db->prepare('DELETE FROM vehicles WHERE customer_id = ?')->execute([$cid]);
                    $db->prepare('UPDATE payments SET recorded_by_customer = NULL WHERE recorded_by_customer = ?')->execute([$cid]);
                    $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
                }
            }
            $db->commit();
            audit('subscription.deleted', 'subscription', $sid, ['name' => $who]);
            flash('Parker "' . $who . '" permanently deleted along with their invoices.', 'success');
        } catch (Exception $ex) {
            $db->rollBack();
            flash('Delete failed: ' . $ex->getMessage(), 'error');
        }
    }
    redirect(site_url('admin/parkers.php?view=trash'));
}

// Handle move-to-trash POST (soft delete).
if ($action === 'trash' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit) {
        flash('You do not have permission to trash parkers (parkers.edit).', 'error');
        redirect(site_url('admin/parkers.php'));
    }
    verify_csrf();
    $sub = mv_parker_sub($db, (int) ($_POST['id'] ?? 0));
    if (!$sub) {
        flash('Subscription not found.', 'error');
    } else {
        $db->prepare("UPDATE subscriptions SET deleted_at = datetime('now') WHERE id = ?")->execute([(int) $sub['id']]);
        $who = $sub['customer_name'] ?: $sub['company_name'] ?: ('#' . $sub['id']);
        audit('subscription.trashed', 'subscription', (int) $sub['id'], ['name' => $who]);
        flash('Parker "' . $who . '" moved to trash. It will be permanently deleted after 7 days.', 'success');
    }
    redirect(site_url('admin/parkers.php'));
}

// Handle restore-from-trash POST.
if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit) {
        flash('You do not have permission to restore parkers (parkers.edit).', 'error');
        redirect(site_url('admin/parkers.php?view=trash'));
    }
    verify_csrf();
    $sub = mv_parker_sub($db, (int) ($_POST['id'] ?? 0));
    if (!$sub) {
        flash('Subscription not found.', 'error');
    } else {
        $db->prepare('UPDATE subscriptions SET deleted_at = NULL WHERE id = ?')->execute([(int) $sub['id']]);
        $who = $sub['customer_name'] ?: $sub['company_name'] ?: ('#' . $sub['id']);
        audit('subscription.restored', 'subscription', (int) $sub['id'], ['name' => $who]);
        flash('Parker "' . $who . '" restored.', 'success');
    }
    redirect(site_url('admin/parkers.php?view=trash'));
}

// Handle bulk actions (trash / restore / delete).
if (in_array($action, ['bulk_trash', 'bulk_restore', 'bulk_delete'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit) {
        flash('You do not have permission for bulk actions (parkers.edit).', 'error');
        redirect(site_url('admin/parkers.php'));
    }
    verify_csrf();
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids === []) {
        flash('No parkers selected.', 'error');
        redirect(site_url('admin/parkers.php' . ($action === 'bulk_trash' ? '' : '?view=trash')));
    }
    $done = 0;
    if ($action === 'bulk_trash') {
        $db->prepare("UPDATE subscriptions SET deleted_at = datetime('now') WHERE id IN (" . implode(',', $ids) . ")")->execute();
        $done = count($ids);
        audit('subscription.bulk_trashed', 'subscription', null, ['count' => $done, 'ids' => $ids]);
        flash($done . ' parker(s) moved to trash.', 'success');
        redirect(site_url('admin/parkers.php'));
    }
    if ($action === 'bulk_restore') {
        $db->prepare('UPDATE subscriptions SET deleted_at = NULL WHERE id IN (' . implode(',', $ids) . ')')->execute();
        $done = count($ids);
        audit('subscription.bulk_restored', 'subscription', null, ['count' => $done, 'ids' => $ids]);
        flash($done . ' parker(s) restored.', 'success');
        redirect(site_url('admin/parkers.php?view=trash'));
    }
    // bulk_delete: full cascade per subscription.
    foreach ($ids as $sid) {
        $sub = mv_parker_sub($db, $sid);
        if (!$sub) continue;
        $db->beginTransaction();
        try {
            $vid = $sub['vehicle_id'] ? (int) $sub['vehicle_id'] : null;
            $cid = $sub['customer_id'] ? (int) $sub['customer_id'] : null;
            $inv = $db->prepare('SELECT id FROM invoices WHERE subscription_id = ?');
            $inv->execute([$sid]);
            foreach ($inv->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                $iid = (int) $iid;
                $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$iid]);
                $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
                $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
            }
            $db->prepare('DELETE FROM subscriptions WHERE id = ?')->execute([$sid]);
            if ($vid) {
                $db->prepare('DELETE FROM permits WHERE vehicle_id = ?')->execute([$vid]);
                $c = $db->prepare('SELECT COUNT(*) FROM subscriptions WHERE vehicle_id = ?');
                $c->execute([$vid]);
                if ((int) $c->fetchColumn() === 0) {
                    $db->prepare('DELETE FROM vehicles WHERE id = ?')->execute([$vid]);
                }
            }
            if ($cid) {
                $c = $db->prepare('SELECT COUNT(*) FROM subscriptions WHERE customer_id = ?');
                $c->execute([$cid]);
                if ((int) $c->fetchColumn() === 0) {
                    $inv = $db->prepare('SELECT id FROM invoices WHERE customer_id = ?');
                    $inv->execute([$cid]);
                    foreach ($inv->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                        $iid = (int) $iid;
                        $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$iid]);
                        $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
                        $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
                    }
                    $db->prepare('DELETE FROM permits WHERE customer_id = ?')->execute([$cid]);
                    $db->prepare('DELETE FROM vehicles WHERE customer_id = ?')->execute([$cid]);
                    $db->prepare('UPDATE payments SET recorded_by_customer = NULL WHERE recorded_by_customer = ?')->execute([$cid]);
                    $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
                }
            }
            $db->commit();
            $done++;
        } catch (Exception $ex) {
            $db->rollBack();
        }
    }
    audit('subscription.bulk_deleted', 'subscription', null, ['count' => $done]);
    flash($done . ' parker(s) permanently deleted.', 'success');
    redirect(site_url('admin/parkers.php?view=trash'));
}

// Render renew form.
// Render move form.
if ($action === 'move' && $can_edit) {
    $sub = mv_parker_sub($db, $sub_id);
    if (!$sub) {
        flash('Subscription not found.', 'error');
        redirect(site_url('admin/parkers.php'));
    }
    $locs = $db->query('SELECT id, name FROM parking_locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $page_title = 'Change Location';
    require __DIR__ . '/_layout.php';
    ?>
    <div class="page-head"><h1>Change location</h1>
        <div class="page-actions"><a class="btn btn-outline" href="<?= e(site_url('admin/parkers.php')) ?>">Back</a></div>
    </div>
    <div class="card">
        <p>Moving <strong><?= e($sub['customer_name'] ?: $sub['company_name']) ?></strong>
           (<?= e($sub['plate'] ?? 'no plate') ?>) from <strong><?= e($sub['location_name']) ?></strong>.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $sub['id'] ?>">
            <div class="field">
                <label for="location_id">New location *</label>
                <select id="location_id" name="location_id" required>
                    <?php foreach ($locs as $l): ?>
                        <option value="<?= (int) $l['id'] ?>"<?= (int) $l['id'] === (int) $sub['location_id'] ? ' selected' : '' ?>>
                            <?= e($l['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary">Move parker</button>
                <a class="btn btn-ghost" href="<?= e(site_url('admin/parkers.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>
    <?php
    require __DIR__ . '/_footer.php';
    return;
}

// ---------- Main table ----------
$search = trim((string) ($_GET['q'] ?? ''));
$f_location = (int) ($_GET['location'] ?? 0);
$f_standing = (string) ($_GET['standing'] ?? 'all'); // all|active|expired|suspended
$f_payment = (string) ($_GET['payment'] ?? 'all');   // all|paid|unpaid
$f_type = (string) ($_GET['type'] ?? 'all');         // all|individual|corporate
$f_category = (string) ($_GET['category'] ?? ($is_residents_view ? 'resident' : 'monthly'));   // all|monthly|resident
$f_expiring = (int) ($_GET['expiring'] ?? 0);        // days window
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;
$view = (string) ($_GET['view'] ?? 'active');
$is_trash_view = ($view === 'trash');

$where = [];
$params = [];
if ($is_trash_view) {
    $where[] = 's.deleted_at IS NOT NULL';
} else {
    $where[] = 's.deleted_at IS NULL';
}
if ($search !== '') {
    $where[] = '(c.name LIKE ? OR co.name LIKE ? OR v.plate LIKE ? OR v.plate_norm LIKE ?)';
    $like = '%' . $search . '%';
    $norm = '%' . normalize_plate($search) . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $norm;
}
if ($f_location > 0) {
    $where[] = 's.location_id = ?';
    $params[] = $f_location;
}
if ($f_standing === 'active') {
    $where[] = "s.status = 'active' AND s.end_date >= " . $db->quote($today);
} elseif ($f_standing === 'expired') {
    $where[] = 's.end_date < ' . $db->quote($today);
} elseif ($f_standing === 'suspended') {
    $where[] = "s.status IN ('suspended','cancelled')";
}
if ($f_expiring > 0) {
    $where[] = "s.status = 'active' AND s.end_date >= " . $db->quote($today) .
               ' AND s.end_date <= ' . $db->quote(date('Y-m-d', strtotime($today . " +$f_expiring days")));
}
// Payment standing derived from the latest invoice for the subscription.
$inv_status_sql = "(SELECT i.status FROM invoices i WHERE i.subscription_id = s.id ORDER BY i.created_at DESC, i.id DESC LIMIT 1)";
if ($f_payment === 'paid') {
    $where[] = "$inv_status_sql = 'paid'";
} elseif ($f_payment === 'unpaid') {
    $where[] = "($inv_status_sql IS NULL OR $inv_status_sql IN ('unpaid','pending','partially_paid','overdue'))";
}
if ($f_type === 'individual') {
    $where[] = 's.customer_id IS NOT NULL';
} elseif ($f_type === 'corporate') {
    $where[] = 's.company_id IS NOT NULL';
}
if (in_array($f_category, ['monthly', 'resident'], true)) {
    $where[] = "COALESCE(s.category, 'monthly') = " . $db->quote($f_category);
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_st = $db->prepare(
    "SELECT COUNT(*) FROM subscriptions s
     LEFT JOIN customers c ON c.id = s.customer_id
     LEFT JOIN companies co ON co.id = s.company_id
     LEFT JOIN vehicles v ON v.id = s.vehicle_id
     $sql_where"
);
$count_st->execute($params);
$total = (int) $count_st->fetchColumn();

$offset = ($page - 1) * $per;
$st = $db->prepare(
    "SELECT s.*, l.name AS location_name,
            c.name AS customer_name, c.id AS customer_id,
            co.name AS company_name, co.id AS company_id,
            v.plate, v.plate_state, v.make, v.model, v.tag_number,
            (SELECT COUNT(*) FROM vehicles vv WHERE vv.company_id = s.company_id AND s.company_id IS NOT NULL) AS company_vehicle_count,
            $inv_status_sql AS inv_status
     FROM subscriptions s
     LEFT JOIN parking_locations l ON l.id = s.location_id
     LEFT JOIN customers c ON c.id = s.customer_id
     LEFT JOIN companies co ON co.id = s.company_id
     LEFT JOIN vehicles v ON v.id = s.vehicle_id
     $sql_where
     ORDER BY l.name ASC, s.end_date ASC, s.id ASC
     LIMIT $per OFFSET $offset"
);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// CSV export (same filters, no pagination).
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $st = $db->prepare(
        "SELECT s.*, l.name AS location_name, c.name AS customer_name, co.name AS company_name,
                v.plate, v.plate_state, v.make, v.model, v.tag_number, $inv_status_sql AS inv_status
         FROM subscriptions s
         LEFT JOIN parking_locations l ON l.id = s.location_id
         LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN companies co ON co.id = s.company_id
         LEFT JOIN vehicles v ON v.id = s.vehicle_id
         $sql_where
         ORDER BY s.end_date ASC, s.id ASC"
    );
    $st->execute($params);
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="monthly-parkers-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Customer', 'Type', 'Category', 'Plate', 'Plate State', 'Tag #', 'Vehicle', 'Location', 'Start', 'End', 'Days Left', 'Payment', 'Status', 'Rate $/mo', 'Hours Allowed', 'Spaces']);
    foreach ($all as $r) {
        $b = days_badge($r['end_date']);
        fputcsv($out, [
            $r['customer_name'] ?: $r['company_name'],
            $r['customer_id'] ? 'Individual' : 'Corporate',
            ucfirst($r['category'] ?? 'monthly'),
            $r['plate'], $r['plate_state'], $r['tag_number'] ?? '',
            trim(($r['make'] ?? '') . ' ' . ($r['model'] ?? '')),
            $r['location_name'], $r['start_date'], $r['end_date'],
            $b['days'], mv_payment_label($r['inv_status']), $r['status'],
            $r['monthly_rate_cents'] !== null ? number_format(((int) $r['monthly_rate_cents']) / 100, 2) : '',
            $r['hours_allowed'] ?? '', $r['spaces'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

function mv_payment_label(?string $status): string
{
    return match ($status) {
        'paid' => 'Paid',
        'partially_paid' => 'Partial',
        'pending' => 'Pending',
        'overdue' => 'Overdue',
        'unpaid' => 'Unpaid',
        'refunded' => 'Refunded',
        'voided' => 'Void',
        default => 'No invoice',
    };
}

function mv_payment_badge(?string $status): string
{
    $label = mv_payment_label($status);
    $color = match ($status) {
        'paid' => 'green',
        'partially_paid' => 'amber',
        'pending' => 'amber',
        'overdue' => 'red',
        'unpaid' => 'gray',
        default => 'gray',
    };
    return '<span class="badge badge-' . $color . '">' . status_dot($color, $label) . '</span>';
}

$locations = $db->query('SELECT id, name FROM parking_locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$base = site_url('admin/parkers.php'); // paginate() preserves the current GET filters itself

require __DIR__ . '/_layout.php';
?>

<?php
// Trash count for tab badge.
$trash_count = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE deleted_at IS NOT NULL")->fetchColumn();
?>
<div class="page-head">
    <h1><?= e($page_title ?? 'Monthly Parkers') ?></h1>
    <div class="page-actions">
        <a class="btn btn-outline btn-sm" href="<?= e(site_url('admin/parkers.php') . '?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a>
        <?php if ($can_edit): ?>
            <a class="btn btn-outline btn-sm" href="<?= e(site_url('admin/parker_import.php')) ?>">Bulk Import</a>
            <a class="btn btn-primary btn-sm" href="<?= e(site_url('admin/parker_form.php')) ?>">+ Add parker</a>
        <?php endif; ?>
    </div>
</div>

<div class="tabs" style="margin-bottom:16px">
    <a class="tab<?= !$is_trash_view ? ' active' : '' ?>" href="<?= e(site_url('admin/parkers.php')) ?>">Parkers</a>
    <a class="tab<?= $is_trash_view ? ' active' : '' ?>" href="<?= e(site_url('admin/parkers.php?view=trash')) ?>">Trash<?= $trash_count > 0 ? ' (' . $trash_count . ')' : '' ?></a>
</div>
<?php if ($is_trash_view): ?>
<p class="muted">Items in trash are permanently deleted automatically after 7 days. You can restore them or delete them permanently now.</p>
<?php endif; ?>

<form method="get" action="<?= e(site_url('admin/parkers.php')) ?>" class="filter-bar">
    <input type="hidden" name="view" value="<?= $is_trash_view ? 'trash' : 'active' ?>">
    <div class="field">
        <label for="fq">Search</label>
        <input type="text" id="fq" name="q" value="<?= e($search) ?>" placeholder="Name, company, or plate…">
    </div>
    <div class="field narrow">
        <label for="floc">Location</label>
        <select id="floc" name="location">
            <option value="0">All</option>
            <?php foreach ($locations as $l): ?>
                <option value="<?= (int) $l['id'] ?>"<?= $f_location === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field narrow">
        <label for="fstand">Standing</label>
        <select id="fstand" name="standing">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'expired' => 'Expired', 'suspended' => 'Suspended/Cancelled'] as $k => $lbl): ?>
                <option value="<?= $k ?>"<?= $f_standing === $k ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field narrow">
        <label for="fpay">Payment</label>
        <select id="fpay" name="payment">
            <?php foreach (['all' => 'All', 'paid' => 'Paid', 'unpaid' => 'Unpaid'] as $k => $lbl): ?>
                <option value="<?= $k ?>"<?= $f_payment === $k ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field narrow">
        <label for="ftype">Type</label>
        <select id="ftype" name="type">
            <?php foreach (['all' => 'All', 'individual' => 'Individual', 'corporate' => 'Corporate'] as $k => $lbl): ?>
                <option value="<?= $k ?>"<?= $f_type === $k ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field narrow">
        <label for="fcat">Category</label>
        <select id="fcat" name="category">
            <?php foreach (['all' => 'All', 'monthly' => 'Monthly', 'resident' => 'Resident'] as $k => $lbl): ?>
                <option value="<?= $k ?>"<?= $f_category === $k ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/parkers.php')) ?>">Clear</a>
</form>

<p class="muted small"><?= $total ?> parker<?= $total === 1 ? '' : 's' ?> found &middot; sorted by days remaining.</p>

<?php if ($rows === []): ?>
    <div class="empty"><strong>No parkers found</strong>Try adjusting the filters, or add a parker.</div>
<?php else: ?>
    <?php if ($can_edit): ?>
    <div id="bulkBar" class="bulk-bar" style="display:none; margin-bottom:12px; padding:10px 14px; background:#f1f5f9; border-radius:8px; align-items:center; gap:10px;">
        <strong><span id="bulkCount">0</span> selected</strong>
        <?php if (!$is_trash_view): ?>
        <button type="button" class="btn btn-outline btn-sm" id="bulkTrashBtn" data-confirm="Move selected parkers to trash?">Move to Trash</button>
        <?php else: ?>
        <button type="button" class="btn btn-outline btn-sm" id="bulkRestoreBtn" data-confirm="Restore selected parkers?">Restore Selected</button>
        <button type="button" class="btn btn-danger btn-sm" id="bulkDeleteBtn" data-confirm="Permanently delete selected parkers and their invoices? This cannot be undone.">Delete Permanently</button>
        <?php endif; ?>
        <button type="button" class="btn btn-ghost btn-sm" id="bulkClearBtn">Clear</button>
    </div>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="data" id="parkersTable">
            <thead>
                <tr>
                    <?php if ($can_edit): ?><th style="width:36px"><input type="checkbox" id="selectAll" title="Select all"></th><?php endif; ?>
                    <th>Customer</th>
                    <th>License Plate</th>
                    <th>Location</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Days Left</th>
                    <th>Payment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Group rows by location for display.
                $loc_groups = [];
                foreach ($rows as $gr) { $loc_groups[$gr['location_name'] ?? 'Unassigned'] = ($loc_groups[$gr['location_name'] ?? 'Unassigned'] ?? 0) + 1; }
                $cur_loc = null;
                foreach ($rows as $r): ?>
                    <?php $badge = days_badge($r['end_date']); ?>
                    <?php $rloc = $r['location_name'] ?? 'Unassigned'; ?>
                    <?php if ($rloc !== $cur_loc): $cur_loc = $rloc; ?>
                    <tr class="loc-group"><td colspan="<?= $can_edit ? 9 : 8 ?>"><strong><?= e($rloc) ?></strong> <span class="muted small">(<?= (int) $loc_groups[$rloc] ?> on this page)</span></td></tr>
                    <?php endif; ?>
                    <tr>
                        <?php if ($can_edit): ?><td><input type="checkbox" class="row-check" value="<?= (int) $r['id'] ?>"></td><?php endif; ?>
                        <td>
                            <strong><?= e($r['customer_name'] ?: $r['company_name'] ?: '—') ?></strong><br>
                            <span class="badge badge-navy"><?= $r['customer_id'] ? 'Individual' : 'Corporate' ?></span>
                            <?php if (($r['category'] ?? 'monthly') === 'resident'): ?>
                                <span class="badge badge-green">Resident</span>
                            <?php endif; ?>
                            <?php if ($r['status'] !== 'active'): ?>
                                <span class="badge badge-gray"><?= status_dot('gray', ucfirst($r['status'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <strong class="mono"><?= e($r['plate'] ?: '—') ?></strong>
                            <?php if (!empty($r['plate_state'])): ?><span class="muted small"><?= e($r['plate_state']) ?></span><?php endif; ?>
                            <?php if (!empty($r['tag_number'])): ?><br><span class="muted small">Tag #<?= e($r['tag_number']) ?></span><?php endif; ?>
                            <?php if (!empty($r['make']) || !empty($r['model'])): ?>
                                <br><span class="muted small"><?= e(trim(($r['make'] ?? '') . ' ' . ($r['model'] ?? ''))) ?></span>
                            <?php endif; ?>
                            <?php if (!$r['customer_id'] && (int) ($r['company_vehicle_count'] ?? 0) > 1): ?>
                                <br><span class="muted small">+ <?= (int) $r['company_vehicle_count'] - 1 ?> more vehicle<?= ((int) $r['company_vehicle_count'] - 1) === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['location_name'] ?: '—') ?></td>
                        <td class="nowrap"><?= e(fmt_date($r['start_date'])) ?></td>
                        <td class="nowrap"><?= e(fmt_date($r['end_date'])) ?></td>
                        <td><span class="badge badge-<?= e($badge['color']) ?>"><?= status_dot($badge['color'], $badge['label']) ?></span></td>
                        <td><?= mv_payment_badge($r['inv_status']) ?></td>
                        <td>
                            <div class="row-actions">
                                <?php if ($can_edit): ?>
                                    <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/parker_form.php?subscription_id=' . (int) $r['id'])) ?>">Edit</a>
                                                                        <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/parkers.php?action=move&id=' . (int) $r['id'])) ?>">Move</a>
                                    <?php if ($is_trash_view): ?>
                                    <form method="post" class="inline-form" data-confirm="Restore this parker?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-xs">Restore</button>
                                    </form>
                                    <form method="post" class="inline-form" data-confirm="Permanently delete this parker? Their subscription, vehicle, permits AND invoices will be permanently removed. This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">Delete permanently</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" class="inline-form" data-confirm="Move this parker to trash? You can restore it within 7 days.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="trash">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-xs">Trash</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($r['customer_id']): ?>
                                        <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/parker_form.php?customer_id=' . (int) $r['customer_id'])) ?>">Vehicles</a>
                                    <?php elseif ($r['company_id'] && can('companies.view')): ?>
                                        <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/company_form.php?id=' . (int) $r['company_id'])) ?>">Company</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <a class="btn btn-ghost btn-xs" href="<?= e(site_url('admin/invoices.php?sub=' . (int) $r['id'])) ?>">Payments</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginate($total, $per, $page, $base) ?>
<?php endif; ?>

<script>
(function() {
    var selectAll = document.getElementById('selectAll');
    var bulkBar = document.getElementById('bulkBar');
    var bulkCount = document.getElementById('bulkCount');
    if (!bulkBar) return;

    function selectedIds() {
        var ids = [];
        document.querySelectorAll('.row-check:checked').forEach(function(cb) {
            ids.push(cb.value);
        });
        return ids;
    }
    function refresh() {
        var ids = selectedIds();
        bulkCount.textContent = ids.length;
        bulkBar.style.display = ids.length > 0 ? 'flex' : 'none';
        if (selectAll) {
            var all = document.querySelectorAll('.row-check');
            selectAll.checked = all.length > 0 && ids.length === all.length;
            selectAll.indeterminate = ids.length > 0 && ids.length < all.length;
        }
    }
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.row-check').forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
            refresh();
        });
    }
    document.querySelectorAll('.row-check').forEach(function(cb) {
        cb.addEventListener('change', refresh);
    });
    document.getElementById('bulkClearBtn').addEventListener('click', function() {
        document.querySelectorAll('.row-check:checked').forEach(function(cb) { cb.checked = false; });
        refresh();
    });
    function submitBulk(action, confirmMsg) {
        var ids = selectedIds();
        if (ids.length === 0) return;
        if (confirmMsg && !window.confirm(confirmMsg)) return;
        var form = document.createElement('form');
        form.method = 'post';
        form.innerHTML = '<?= csrf_field() ?>'
            + '<input type="hidden" name="action" value="' + action + '">'
            + ids.map(function(id) { return '<input type="hidden" name="ids[]" value="' + id + '">'; }).join('');
        document.body.appendChild(form);
        form.submit();
    }
    var trashBtn = document.getElementById('bulkTrashBtn');
    if (trashBtn) trashBtn.addEventListener('click', function() {
        submitBulk('bulk_trash', trashBtn.getAttribute('data-confirm'));
    });
    var restoreBtn = document.getElementById('bulkRestoreBtn');
    if (restoreBtn) restoreBtn.addEventListener('click', function() {
        submitBulk('bulk_restore', restoreBtn.getAttribute('data-confirm'));
    });
    var deleteBtn = document.getElementById('bulkDeleteBtn');
    if (deleteBtn) deleteBtn.addEventListener('click', function() {
        submitBulk('bulk_delete', deleteBtn.getAttribute('data-confirm'));
    });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
