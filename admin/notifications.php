<?php
// admin/notifications.php — every send attempt, with status, template filter, dedupe keys.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Notification History';
$nav_section = 'notifications';
$required_perm = 'reminders.view';
require __DIR__ . '/_guard.php';

$db = db();

$f_status = (string) ($_GET['status'] ?? 'all');
$f_template = (string) ($_GET['template'] ?? 'all');
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;

$where = [];
$params = [];
if (in_array($f_status, ['sent', 'failed'], true)) {
    $where[] = 'status = ?';
    $params[] = $f_status;
}
if ($f_template !== 'all' && $f_template !== '') {
    $where[] = 'template_key = ?';
    $params[] = $f_template;
}
if ($search !== '') {
    $where[] = '(recipient LIKE ? OR subject LIKE ? OR dedupe_key LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_st = $db->prepare("SELECT COUNT(*) FROM notification_logs $sql_where");
$count_st->execute($params);
$total = (int) $count_st->fetchColumn();

$offset = ($page - 1) * $per;
$st = $db->prepare("SELECT * FROM notification_logs $sql_where ORDER BY sent_at DESC, id DESC LIMIT $per OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$tpls = $db->query('SELECT DISTINCT template_key FROM notification_logs ORDER BY template_key')->fetchAll(PDO::FETCH_COLUMN);

$day_ago = date('Y-m-d H:i:s', strtotime('-1 day'));
$sent_24 = $db->prepare("SELECT COUNT(*) FROM notification_logs WHERE status = 'sent' AND sent_at >= ?");
$sent_24->execute([$day_ago]);
$sent_24 = (int) $sent_24->fetchColumn();
$fail_24 = $db->prepare("SELECT COUNT(*) FROM notification_logs WHERE status = 'failed' AND sent_at >= ?");
$fail_24->execute([$day_ago]);
$fail_24 = (int) $fail_24->fetchColumn();

$base = site_url('admin/notifications.php'); // paginate() preserves the current GET filters itself

require __DIR__ . '/_layout.php';
?>

<div class="page-head"><h1>Notification History</h1></div>

<div class="kpi-grid">
    <div class="kpi green"><div class="kpi-label">Sent (24h)</div><div class="kpi-value"><?= $sent_24 ?></div></div>
    <div class="kpi red"><div class="kpi-label">Failed (24h)</div><div class="kpi-value"><?= $fail_24 ?></div></div>
    <div class="kpi"><div class="kpi-label">Total logged</div><div class="kpi-value"><?= $total ?></div>
        <div class="kpi-sub">dedupe keys prevent double-sends</div></div>
</div>

<form method="get" action="<?= e(site_url('admin/notifications.php')) ?>" class="filter-bar">
    <div class="field">
        <label for="fq">Search</label>
        <input type="text" id="fq" name="q" value="<?= e($search) ?>" placeholder="Recipient, subject, dedupe key…">
    </div>
    <div class="field narrow">
        <label for="fstatus">Status</label>
        <select id="fstatus" name="status">
            <?php foreach (['all' => 'All', 'sent' => 'Sent', 'failed' => 'Failed'] as $k => $lbl): ?>
                <option value="<?= $k ?>"<?= $f_status === $k ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field narrow">
        <label for="ftemplate">Template</label>
        <select id="ftemplate" name="template">
            <option value="all">All</option>
            <?php foreach ($tpls as $t): ?>
                <option value="<?= e($t) ?>"<?= $f_template === $t ? ' selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/notifications.php')) ?>">Clear</a>
</form>

<?php if ($rows === []): ?>
    <div class="empty"><strong>No notifications logged</strong>Send attempts (including test sends) are recorded here.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Sent at</th><th>Template</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Dedupe key</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="nowrap small"><?= e($r['sent_at']) ?></td>
                        <td class="mono small"><?= e($r['template_key']) ?></td>
                        <td class="small"><?= e($r['recipient']) ?></td>
                        <td class="small"><?= e(mb_strimwidth($r['subject'], 0, 60, '…')) ?></td>
                        <td>
                            <?php if ($r['status'] === 'sent'): ?>
                                <span class="badge badge-green"><?= status_dot('green', 'Sent') ?></span>
                            <?php else: ?>
                                <span class="badge badge-red" title="<?= e($r['error'] ?: '') ?>"><?= status_dot('red', 'Failed') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mono small"><?= e($r['dedupe_key'] ?: '—') ?></td>
                    </tr>
                    <?php if ($r['status'] === 'failed' && $r['error']): ?>
                        <tr><td></td><td colspan="5" class="small" style="color:var(--red)">Error: <?= e($r['error']) ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginate($total, $per, $page, $base) ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
