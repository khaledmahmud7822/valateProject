<?php
// admin/locations.php — parking location list with publish/deactivate/delete.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Parking Locations';
$nav_section = 'locations';
$required_perm = 'locations.view';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$can_edit = can('locations.edit');

// ---- Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    verify_csrf();
    {
        $id = (int) ($_POST['id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        $st = $db->prepare('SELECT * FROM parking_locations WHERE id = ?');
        $st->execute([$id]);
        $loc = $st->fetch(PDO::FETCH_ASSOC);

        if (!$loc) {
            flash('Location not found.', 'error');
        } elseif ($action === 'toggle_publish') {
            $new = $loc['is_published'] ? 0 : 1;
            $db->prepare('UPDATE parking_locations SET is_published = ? WHERE id = ?')->execute([$new, $id]);
            audit('location.' . ($new ? 'published' : 'unpublished'), 'parking_location', $id, ['name' => $loc['name']]);
            flash('Location ' . ($new ? 'published.' : 'unpublished.'), 'success');
        } elseif ($action === 'delete') {
            // Block deletion when dependents exist.
            $deps = [];
            foreach (['customers' => 'location_id', 'subscriptions' => 'location_id', 'companies' => 'location_id'] as $tbl => $col) {
                $c = $db->prepare("SELECT COUNT(*) FROM $tbl WHERE $col = ?");
                $c->execute([$id]);
                if ((int) $c->fetchColumn() > 0) {
                    $deps[] = $tbl;
                }
            }
            if ($deps !== []) {
                flash('Cannot delete "' . $loc['name'] . '": it still has ' . implode(', ', $deps) . '. Unpublish it instead.', 'error');
            } else {
                $db->beginTransaction();
                try {
                    $db->prepare('DELETE FROM location_photos WHERE location_id = ?')->execute([$id]);
                    $db->prepare('DELETE FROM parking_locations WHERE id = ?')->execute([$id]);
                    $db->commit();
                    audit('location.deleted', 'parking_location', $id, ['name' => $loc['name']]);
                    flash('Location deleted.', 'success');
                } catch (Exception $ex) {
                    $db->rollBack();
                    flash('Delete failed: ' . $ex->getMessage(), 'error');
                }
            }
        }
    }
    redirect(site_url('admin/locations.php'));
}

// ---- Filters ----
$search = trim((string) ($_GET['q'] ?? ''));
$needs_review = isset($_GET['needs_review']) && $_GET['needs_review'] === '1';
$pub_filter = (string) ($_GET['published'] ?? '');

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(l.name LIKE ? OR l.city LIKE ? OR l.address LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($needs_review) {
    $where[] = 'l.needs_review = 1';
}
if ($pub_filter === '1') {
    $where[] = 'l.is_published = 1';
} elseif ($pub_filter === '0') {
    $where[] = 'l.is_published = 0';
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $db->prepare(
    "SELECT l.*, (SELECT COUNT(*) FROM location_photos p WHERE p.location_id = l.id) AS photo_count,
            (SELECT COUNT(*) FROM subscriptions s WHERE s.location_id = l.id AND s.status = 'active') AS active_subs
     FROM parking_locations l $sql_where ORDER BY l.name"
);
$st->execute($params);
$locations = $st->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1>Parking Locations</h1>
    <?php if ($can_edit): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= e(site_url('admin/location_form.php')) ?>">+ Add location</a>
        </div>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(site_url('admin/locations.php')) ?>" class="filter-bar">
    <div class="field">
        <label for="fq">Search</label>
        <input type="text" id="fq" name="q" value="<?= e($search) ?>" placeholder="Name, city, address…">
    </div>
    <div class="field narrow">
        <label for="fpub">Published</label>
        <select id="fpub" name="published">
            <option value="">All</option>
            <option value="1"<?= $pub_filter === '1' ? ' selected' : '' ?>>Published</option>
            <option value="0"<?= $pub_filter === '0' ? ' selected' : '' ?>>Unpublished</option>
        </select>
    </div>
    <div class="field narrow">
        <label for="frev">&nbsp;</label>
        <label class="check-line"><input type="checkbox" id="frev" name="needs_review" value="1"<?= $needs_review ? ' checked' : '' ?>> Needs review only</label>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    <?php if ($search !== '' || $needs_review || $pub_filter !== ''): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/locations.php')) ?>">Clear</a>
    <?php endif; ?>
</form>

<?php if ($locations === []): ?>
    <div class="empty">
        <strong>No locations found</strong>
        <?php if ($can_edit): ?>Add your first parking location to get started.<?php else: ?>Try adjusting the filters.<?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Services</th>
                    <th>Monthly</th>
                    <th>Rate</th>
                    <th>Published</th>
                    <th>Active subs</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locations as $l): ?>
                    <tr>
                        <td>
                            <strong><?= e($l['name']) ?></strong><br>
                            <span class="muted small"><?= e(trim($l['city'] . ', ' . $l['state'], ', ')) ?></span>
                            <?php if ((int) $l['needs_review'] === 1): ?>
                                <br><span class="badge badge-amber"><?= status_dot('amber', 'Needs review') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($l['type'] ?: '—') ?></td>
                        <td class="small"><?= e($l['services'] ? mb_strimwidth($l['services'], 0, 60, '…') : '—') ?></td>
                        <td><?= (int) $l['monthly_available'] === 1 ? 'Yes' : 'No' ?></td>
                        <td><?= $l['monthly_rate_cents'] === null || $l['monthly_rate_cents'] === '' ? '<span class="muted">Contact for rates</span>' : e(money((int) $l['monthly_rate_cents'])) . '<span class="muted small">/mo</span>' ?></td>
                        <td>
                            <?php if ((int) $l['is_published'] === 1): ?>
                                <span class="badge badge-green"><?= status_dot('green', 'Published') ?></span>
                            <?php else: ?>
                                <span class="badge badge-gray"><?= status_dot('gray', 'Unpublished') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $l['active_subs'] ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/parkers.php?location=' . (int) $l['id'] . '&category=all')) ?>">View</a>
                                <?php if ($can_edit): ?>
                                    <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/location_form.php?id=' . (int) $l['id'])) ?>">Edit</a>
                                    <form method="post" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                        <input type="hidden" name="action" value="toggle_publish">
                                        <button type="submit" class="btn btn-outline btn-xs">
                                            <?= (int) $l['is_published'] === 1 ? 'Unpublish' : 'Publish' ?>
                                        </button>
                                    </form>
                                    <form method="post" class="inline-form" data-confirm="Delete this location? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted small">View only</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
