<?php
// admin/roles.php — role list + permission matrix. Display for staff.manage;
// toggling the matrix is restricted to super admins.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Roles & Permissions';
$nav_section = 'roles';
$required_perm = 'staff.manage';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$is_super = has_role('super_admin');

$perm_groups = [
    'Parking Operations' => [
        'locations.view' => 'View locations',
        'locations.edit' => 'Edit locations',
        'parkers.view' => 'View monthly parkers',
        'parkers.edit' => 'Edit monthly parkers',
        'plates.lookup' => 'Plate lookup',
        'companies.view' => 'View corporate accounts',
        'companies.edit' => 'Edit corporate accounts',
        'renewals.view' => 'View renewals',
    ],
    'Financial' => [
        'invoices.view' => 'View invoices',
        'invoices.edit' => 'Create / edit invoices',
        'payments.record' => 'Record payments',
        'payments.refund' => 'Issue Stripe refunds',
        'reports.view' => 'View reports',
    ],
    'Business Development' => [
        'requests.manage' => 'Manage monthly requests',
        'leads.manage' => 'Manage valet leads',
    ],
    'Communications' => [
        'reminders.view' => 'View reminders',
        'reminders.send' => 'Send reminders',
        'templates.edit' => 'Edit email templates',
    ],
    'System' => [
        'staff.manage' => 'Manage staff & roles',
        'audit.view' => 'View audit log',
        'settings.edit' => 'Edit settings',
    ],
    'Careers' => [
        'careers.manage' => 'Manage job postings',
        'applications.manage' => 'Manage job applications',
    ],
];

// Auto-repair: super_admin must always keep full wildcard access.
// (An earlier matrix save could strip '*' since it's not a checkbox value.)
$repair = $db->prepare("SELECT id, permissions FROM roles WHERE name = 'super_admin' LIMIT 1");
$repair->execute();
if ($sr = $repair->fetch(PDO::FETCH_ASSOC)) {
    $sp = json_decode((string) ($sr['permissions'] ?? '[]'), true);
    if (!is_array($sp) || !in_array('*', $sp, true)) {
        $db->prepare("UPDATE roles SET permissions = ? WHERE id = ?")->execute([json_encode(['*']), (int) $sr['id']]);
        audit('role.permissions_repaired', 'role', (int) $sr['id'], ['restored' => '*']);
        // current_user() reads permissions fresh from the DB, so the menu returns immediately.
    }
}

$roles = $db->query('SELECT * FROM roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$role_perms = [];
foreach ($roles as $r) {
    $p = json_decode((string) ($r['permissions'] ?? '[]'), true);
    $role_perms[$r['id']] = is_array($p) ? $p : [];
}

// ---------- Save matrix (super admin only) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_matrix'])) {
    verify_csrf();
    if (!$is_super) {
        flash('Only a super admin can change the permission matrix.', 'error');
    } else {
        $posted = $_POST['perms'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }
        $valid = [];
        foreach ($perm_groups as $perms) {
            foreach ($perms as $k => $lbl) {
                $valid[$k] = true;
            }
        }
        $upd = $db->prepare('UPDATE roles SET permissions = ? WHERE id = ?');
        foreach ($roles as $r) {
            $rid = (int) $r['id'];
            $sel = isset($posted[$rid]) && is_array($posted[$rid]) ? $posted[$rid] : [];
            $clean = array_values(array_intersect(array_unique($sel), array_keys($valid)));
            // Safety: super_admin always keeps full wildcard access.
            if ($r['name'] === 'super_admin') {
                $clean = ['*'];
            }
            sort($clean);
            $upd->execute([json_encode($clean), $rid]);
        }
        audit('role.permissions_updated', 'role', null);
        flash('Permission matrix saved.', 'success');
    }
    redirect(site_url('admin/roles.php'));
}

$edit_mode = $is_super && isset($_GET['edit']) && $_GET['edit'] === '1';

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1>Roles &amp; Permissions</h1>
    <?php if ($is_super): ?>
        <div class="page-actions">
            <?php if ($edit_mode): ?>
                <a class="btn btn-outline btn-sm" href="<?= e(site_url('admin/roles.php')) ?>">Cancel</a>
            <?php else: ?>
                <a class="btn btn-primary btn-sm" href="<?= e(site_url('admin/roles.php?edit=1')) ?>">Edit matrix</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (!$is_super): ?>
    <p class="muted">Read-only view. Only a super admin can change permissions.</p>
<?php endif; ?>

<?php if ($edit_mode): ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="save_matrix" value="1">
<?php endif; ?>

<?php foreach ($perm_groups as $group => $perms): ?>
    <div class="card">
        <h3><?= e($group) ?></h3>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Permission</th>
                        <?php foreach ($roles as $r): ?>
                            <th><?= e($r['label'] ?? $r['name']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($perms as $key => $lbl): ?>
                        <tr>
                            <td><?= e($lbl) ?><br><code class="mono small muted"><?= e($key) ?></code></td>
                            <?php foreach ($roles as $r): ?>
                                <td style="text-align:center">
                                    <?php $has = in_array($key, $role_perms[$r['id']] ?? [], true); ?>
                                    <?php if ($edit_mode): ?>
                                        <input type="checkbox" name="perms[<?= (int) $r['id'] ?>][]" value="<?= e($key) ?>"<?= $has ? ' checked' : '' ?>
                                            <?= ($r['name'] === 'super_admin' && $key === 'staff.manage') ? ' disabled title="Always granted to super admin"' : '' ?>>
                                        <?php if ($r['name'] === 'super_admin' && $key === 'staff.manage'): ?>
                                            <input type="hidden" name="perms[<?= (int) $r['id'] ?>][]" value="staff.manage">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= $has
                                            ? '<span class="badge badge-green">' . status_dot('green', 'Yes') . '</span>'
                                            : '<span class="badge badge-gray">' . status_dot('gray', 'No') . '</span>' ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($edit_mode): ?>
    <div class="btn-row">
        <button type="submit" class="btn btn-primary">Save matrix</button>
        <a class="btn btn-ghost" href="<?= e(site_url('admin/roles.php')) ?>">Cancel</a>
    </div>
</form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
