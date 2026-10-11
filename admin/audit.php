<?php
// admin/audit.php — activity log with actor/action/entity/date filters.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Audit Log';
$nav_section = 'audit';
$required_perm = 'audit.view';
require __DIR__ . '/_guard.php';

$db = db();

$f_actor = trim((string) ($_GET['actor'] ?? ''));
$f_action = trim((string) ($_GET['action'] ?? ''));
$f_entity = (string) ($_GET['entity'] ?? '');
$f_from = trim((string) ($_GET['from'] ?? ''));
$f_to = trim((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 30;

$where = [];
$params = [];
if ($f_actor !== '') {
    $actor_conds = ['u.name LIKE ?', 'a.actor_type LIKE ?'];
    $actor_params = ['%' . $f_actor . '%', '%' . $f_actor . '%'];
    if (ctype_digit($f_actor)) {
        $actor_conds[] = 'a.actor_id = ?';
        $actor_params[] = (int) $f_actor;
    }
    $where[] = '(' . implode(' OR ', $actor_conds) . ')';
    $params = array_merge($params, $actor_params);
}
if ($f_action !== '') {
    $where[] = 'a.action LIKE ?';
    $params[] = '%' . $f_action . '%';
}
if ($f_entity !== '') {
    $where[] = 'a.entity = ?';
    $params[] = $f_entity;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) {
    $where[] = 'a.created_at >= ?';
    $params[] = $f_from . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to)) {
    $where[] = 'a.created_at <= ?';
    $params[] = $f_to . ' 23:59:59';
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_st = $db->prepare("SELECT COUNT(*) FROM activity_logs a LEFT JOIN users u ON u.id = a.actor_id $sql_where");
$count_st->execute($params);
$total = (int) $count_st->fetchColumn();

$offset = ($page - 1) * $per;
$st = $db->prepare(
    "SELECT a.*, u.name AS actor_name FROM activity_logs a
     LEFT JOIN users u ON u.id = a.actor_id
     $sql_where
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT $per OFFSET $offset"
);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$entities = $db->query('SELECT DISTINCT entity FROM activity_logs ORDER BY entity')->fetchAll(PDO::FETCH_COLUMN);

$base = site_url('admin/audit.php'); // paginate() preserves the current GET filters itself

require __DIR__ . '/_layout.php';
?>

<div class="page-head"><h1>Audit Log</h1></div>

<form method="get" action="<?= e(site_url('admin/audit.php')) ?>" class="filter-bar">
    <div class="field">
        <label for="factor">Actor</label>
        <input type="text" id="factor" name="actor" value="<?= e($f_actor) ?>" placeholder="Name, type, or ID…">
    </div>
    <div class="field">
        <label for="faction">Action</label>
        <input type="text" id="faction" name="action" value="<?= e($f_action) ?>" placeholder="e.g. login, created…">
    </div>
    <div class="field narrow">
        <label for="fentity">Entity</label>
        <select id="fentity" name="entity">
            <option value="">All</option>
            <?php foreach ($entities as $en): ?>
                <option value="<?= e($en) ?>"<?= $f_entity === $en ? ' selected' : '' ?>><?= e($en) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field narrow"><label for="ffrom">From</label><input type="date" id="ffrom" name="from" value="<?= e($f_from) ?>"></div>
    <div class="field narrow"><label for="fto">To</label><input type="date" id="fto" name="to" value="<?= e($f_to) ?>"></div>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/audit.php')) ?>">Clear</a>
</form>

<?php if ($rows === []): ?>
    <div class="empty"><strong>No activity found</strong>Actions taken in the admin are recorded here.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>ID</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="nowrap small"><?= e($r['created_at']) ?></td>
                        <td><?= e($r['actor_name'] ?: ($r['actor_type'] === 'system' ? 'System' : '—')) ?><br>
                            <span class="muted small"><?= e($r['actor_type'] ?: '') ?></span></td>
                        <td><code class="mono small"><?= e($r['action']) ?></code></td>
                        <td><?= e($r['entity'] ?: '—') ?></td>
                        <td class="small"><?= e($r['entity_id'] !== null && $r['entity_id'] !== '' ? (string) $r['entity_id'] : '—') ?></td>
                        <td class="small">
                            <?php
                            $det = trim((string) ($r['details'] ?? ''));
                            if ($det !== '') {
                                $dec = json_decode($det, true);
                                echo e($dec !== null ? json_encode($dec) : mb_strimwidth($det, 0, 160, '…'));
                            } else {
                                echo '<span class="muted">—</span>';
                            }
                            ?>
                        </td>
                        <td class="mono small"><?= e($r['ip'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginate($total, $per, $page, $base) ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
