<?php
// admin/companies.php — corporate accounts list with company-level payment standing.
// Includes trash system (soft delete, 7-day auto-delete) and bulk actions.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Corporate Accounts';
$nav_section = 'companies';
$required_perm = 'companies.view';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$can_edit = can('companies.edit');

$view = (string) ($_GET['view'] ?? 'active'); // active | trash
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

// ---------- Single trash / restore / permanent delete ----------
function mv_company_delete_cascade(PDO $db, int $company_id): void
{
    // Invoices (items + payments + invoice).
    $inv = $db->prepare('SELECT id FROM invoices WHERE company_id = ?');
    $inv->execute([$company_id]);
    foreach ($inv->fetchAll(PDO::FETCH_COLUMN) as $iid) {
        $iid = (int) $iid;
        $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$iid]);
        $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
        $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
    }
    // Subscriptions of this company (cascade their invoices too).
    $subs = $db->prepare('SELECT id, vehicle_id FROM subscriptions WHERE company_id = ?');
    $subs->execute([$company_id]);
    foreach ($subs->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $sid = (int) $s['id'];
        $inv2 = $db->prepare('SELECT id FROM invoices WHERE subscription_id = ?');
        $inv2->execute([$sid]);
        foreach ($inv2->fetchAll(PDO::FETCH_COLUMN) as $iid) {
            $iid = (int) $iid;
            $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$iid]);
            $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
            $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
        }
        $db->prepare('DELETE FROM subscriptions WHERE id = ?')->execute([$sid]);
        if (!empty($s['vehicle_id'])) {
            $db->prepare('DELETE FROM permits WHERE vehicle_id = ?')->execute([(int) $s['vehicle_id']]);
        }
    }
    // Vehicles, contacts, then the company.
    $db->prepare('DELETE FROM vehicles WHERE company_id = ?')->execute([$company_id]);
    $db->prepare('DELETE FROM company_contacts WHERE company_id = ?')->execute([$company_id]);
    $db->prepare('DELETE FROM companies WHERE id = ?')->execute([$company_id]);
}

if ($action === 'trash' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit) { flash('You do not have permission to trash companies.', 'error'); redirect(site_url('admin/companies.php')); }
    verify_csrf();
    $cid = (int) ($_POST['id'] ?? 0);
    $nm = $db->prepare('SELECT name FROM companies WHERE id = ?'); $nm->execute([$cid]);
    $who = $nm->fetchColumn() ?: ('#' . $cid);
    $db->prepare("UPDATE companies SET deleted_at = datetime('now') WHERE id = ?")->execute([$cid]);
    audit('company.trashed', 'company', $cid, ['name' => $who]);
    flash('Company "' . $who . '" moved to trash. It will be permanently deleted after 7 days.', 'success');
    redirect(site_url('admin/companies.php?view=trash'));
}
if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit) { flash('You do not have permission to restore companies.', 'error'); redirect(site_url('admin/companies.php?view=trash')); }
    verify_csrf();
    $cid = (int) ($_POST['id'] ?? 0);
    $db->prepare('UPDATE companies SET deleted_at = NULL WHERE id = ?')->execute([$cid]);
    audit('company.restored', 'company', $cid, []);
    flash('Company restored.', 'success');
    redirect(site_url('admin/companies.php?view=trash'));
}
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit) { flash('You do not have permission to delete companies.', 'error'); redirect(site_url('admin/companies.php?view=trash')); }
    verify_csrf();
    $cid = (int) ($_POST['id'] ?? 0);
    $db->beginTransaction();
    try {
        mv_company_delete_cascade($db, $cid);
        $db->commit();
        audit('company.deleted', 'company', $cid, []);
        flash('Company permanently deleted with all its records.', 'success');
    } catch (Throwable $e) {
        $db->rollBack();
        flash('Could not delete: ' . $e->getMessage(), 'error');
    }
    redirect(site_url('admin/companies.php?view=trash'));
}

// ---------- Bulk actions ----------
if (in_array($action, ['bulk_trash', 'bulk_restore', 'bulk_delete'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit) { flash('You do not have permission for bulk actions.', 'error'); redirect(site_url('admin/companies.php')); }
    verify_csrf();
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids === []) {
        flash('No companies selected.', 'error');
        redirect(site_url('admin/companies.php' . ($action === 'bulk_trash' ? '' : '?view=trash')));
    }
    if ($action === 'bulk_trash') {
        $db->prepare("UPDATE companies SET deleted_at = datetime('now') WHERE id IN (" . implode(',', $ids) . ")")->execute();
        audit('company.bulk_trashed', 'company', null, ['count' => count($ids), 'ids' => $ids]);
        flash(count($ids) . ' companie(s) moved to trash.', 'success');
        redirect(site_url('admin/companies.php'));
    }
    if ($action === 'bulk_restore') {
        $db->prepare('UPDATE companies SET deleted_at = NULL WHERE id IN (' . implode(',', $ids) . ')')->execute();
        audit('company.bulk_restored', 'company', null, ['count' => count($ids), 'ids' => $ids]);
        flash(count($ids) . ' companie(s) restored.', 'success');
        redirect(site_url('admin/companies.php?view=trash'));
    }
    // bulk_delete
    $done = 0;
    foreach ($ids as $cid) {
        $db->beginTransaction();
        try { mv_company_delete_cascade($db, $cid); $db->commit(); $done++; }
        catch (Throwable $e) { $db->rollBack(); }
    }
    audit('company.bulk_deleted', 'company', null, ['count' => $done]);
    flash($done . ' companie(s) permanently deleted.', 'success');
    redirect(site_url('admin/companies.php?view=trash'));
}

// ---------- List ----------
$search = trim((string) ($_GET['q'] ?? ''));
$f_status = (string) ($_GET['status'] ?? 'all');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;

$where = [];
$params = [];
if ($view === 'trash') {
    $where[] = 'co.deleted_at IS NOT NULL';
} else {
    $where[] = 'co.deleted_at IS NULL';
}
if ($search !== '') {
    $where[] = '(co.name LIKE ? OR co.contact_name LIKE ? OR co.email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if (in_array($f_status, ['active', 'inactive', 'prospect'], true)) {
    $where[] = 'co.status = ?';
    $params[] = $f_status;
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_st = $db->prepare("SELECT COUNT(*) FROM companies co $sql_where");
$count_st->execute($params);
$total = (int) $count_st->fetchColumn();

$trash_count = (int) $db->query('SELECT COUNT(*) FROM companies WHERE deleted_at IS NOT NULL')->fetchColumn();

$offset = ($page - 1) * $per;
$st = $db->prepare(
    "SELECT co.*,
            (SELECT COUNT(*) FROM vehicles v WHERE v.company_id = co.id) AS vehicle_count,
            (SELECT COUNT(*) FROM subscriptions s WHERE s.company_id = co.id AND s.status = 'active') AS active_subs,
            COALESCE((SELECT SUM(i.amount_cents - i.amount_paid_cents) FROM invoices i
                      WHERE i.company_id = co.id AND i.status IN ('unpaid','pending','partially_paid','overdue')), 0) AS outstanding_cents
     FROM companies co $sql_where
     ORDER BY co.name LIMIT $per OFFSET $offset"
);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$base = site_url('admin/companies.php');

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1>Corporate Accounts</h1>
    <?php if ($can_edit && $view !== 'trash'): ?>
        <div class="page-actions">
            <a class="btn btn-primary btn-sm" href="<?= e(site_url('admin/company_form.php')) ?>">+ Add company</a>
        </div>
    <?php endif; ?>
</div>

<div class="tabs" style="margin-bottom:14px">
    <a class="tab<?= $view !== 'trash' ? ' active' : '' ?>" href="<?= e(site_url('admin/companies.php')) ?>">Active</a>
    <a class="tab<?= $view === 'trash' ? ' active' : '' ?>" href="<?= e(site_url('admin/companies.php?view=trash')) ?>">Trash<?= $trash_count > 0 ? ' (' . $trash_count . ')' : '' ?></a>
</div>
<?php if ($view === 'trash'): ?>
    <div class="alert alert-info" style="margin-bottom:14px">Trashed companies are permanently deleted automatically after 7 days.</div>
<?php endif; ?>

<form method="get" action="<?= e(site_url('admin/companies.php')) ?>" class="filter-bar">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="field">
        <label for="fq">Search</label>
        <input type="text" id="fq" name="q" value="<?= e($search) ?>" placeholder="Company, contact, email…">
    </div>
    <div class="field narrow">
        <label for="fstatus">Status</label>
        <select id="fstatus" name="status">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'prospect' => 'Prospect'] as $k => $lbl): ?>
                <option value="<?= $k ?>"<?= $f_status === $k ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/companies.php' . ($view === 'trash' ? '?view=trash' : ''))) ?>">Clear</a>
</form>

<?php if ($rows === []): ?>
    <div class="empty"><strong><?= $view === 'trash' ? 'Trash is empty' : 'No companies found' ?></strong><?= ($can_edit && $view !== 'trash') ? ' Add your first corporate account to get started.' : '' ?></div>
<?php else: ?>
    <form method="post" id="bulkForm">
        <?= csrf_field() ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <?php if ($can_edit): ?><th style="width:36px"><input type="checkbox" id="checkAll" title="Select all"></th><?php endif; ?>
                        <th>Company</th>
                        <th>Contact</th>
                        <th>Spaces</th>
                        <th>Rate</th>
                        <th>Contract</th>
                        <th>Status</th>
                        <th>Outstanding</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $co): ?>
                        <tr>
                            <?php if ($can_edit): ?><td><input type="checkbox" name="ids[]" value="<?= (int) $co['id'] ?>" class="rowCheck"></td><?php endif; ?>
                            <td>
                                <strong><?= e($co['name']) ?></strong><br>
                                <span class="muted small"><?= (int) $co['vehicle_count'] ?> vehicles · <?= (int) $co['active_subs'] ?> active subs</span>
                            </td>
                            <td class="small"><?= e($co['contact_name'] ?: '—') ?><br><span class="muted"><?= e($co['email'] ?: '') ?></span></td>
                            <td><?= e($co['spaces'] ?? '—') ?></td>
                            <td><?= $co['rate_cents'] !== null ? e(money((int) $co['rate_cents'])) . '<span class="muted small">/sp</span>' : '<span class="muted">—</span>' ?></td>
                            <td class="small nowrap">
                                <?= $co['contract_start'] ? e(fmt_date($co['contract_start'])) : '—' ?>
                                → <?= $co['contract_end'] ? e(fmt_date($co['contract_end'])) : '—' ?>
                            </td>
                            <td>
                                <?php $sc = $co['status'] === 'active' ? 'green' : ($co['status'] === 'prospect' ? 'amber' : 'gray'); ?>
                                <span class="badge badge-<?= $sc ?>"><?= status_dot($sc, ucfirst($co['status'])) ?></span>
                            </td>
                            <td>
                                <?php $oc = (int) $co['outstanding_cents']; ?>
                                <?php if ($oc > 0): ?>
                                    <span class="badge badge-red"><?= status_dot('red', money($oc)) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-green"><?= status_dot('green', 'Clear') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <?php if ($view === 'trash'): ?>
                                        <?php if ($can_edit): ?>
                                            <form method="post" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="id" value="<?= (int) $co['id'] ?>">
                                                <button type="submit" class="btn btn-outline btn-xs">Restore</button>
                                            </form>
                                            <form method="post" style="display:inline" data-confirm="Permanently delete this company and ALL its records? This cannot be undone.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $co['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/company_form.php?id=' . (int) $co['id'])) ?>">
                                            <?= $can_edit ? 'Manage' : 'View' ?>
                                        </a>
                                        <?php if (can('invoices.view')): ?>
                                            <a class="btn btn-ghost btn-xs" href="<?= e(site_url('admin/invoices.php?company=' . (int) $co['id'])) ?>">Invoices</a>
                                        <?php endif; ?>
                                        <?php if ($can_edit): ?>
                                            <form method="post" style="display:inline" data-confirm="Move this company to trash? It will be permanently deleted after 7 days.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="trash">
                                                <input type="hidden" name="id" value="<?= (int) $co['id'] ?>">
                                                <button type="submit" class="btn btn-ghost btn-xs">Trash</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($can_edit): ?>
            <div class="bulk-bar" style="margin-top:10px;display:flex;gap:8px;align-items:center">
                <span class="muted small"><span id="selCount">0</span> selected</span>
                <?php if ($view === 'trash'): ?>
                    <button type="submit" name="action" value="bulk_restore" class="btn btn-outline btn-sm">Restore selected</button>
                    <button type="submit" name="action" value="bulk_delete" class="btn btn-danger btn-sm" data-confirm="Permanently delete selected companies and ALL their records? This cannot be undone.">Delete selected</button>
                <?php else: ?>
                    <button type="submit" name="action" value="bulk_trash" class="btn btn-outline btn-sm" data-confirm="Move selected companies to trash?">Move to trash</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </form>
    <script>
    (function(){
        var all = document.getElementById('checkAll');
        var boxes = document.querySelectorAll('.rowCheck');
        var cnt = document.getElementById('selCount');
        function upd(){ var n=0; boxes.forEach(function(b){ if(b.checked) n++; }); if(cnt) cnt.textContent=n; }
        if(all){ all.addEventListener('change', function(){ boxes.forEach(function(b){ b.checked=all.checked; }); upd(); }); }
        boxes.forEach(function(b){ b.addEventListener('change', upd); });
    })();
    </script>
    <?= paginate($total, $per, $page, $base) ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
