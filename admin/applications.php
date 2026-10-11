<?php
// admin/applications.php — applications overview + table + CSV export.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Job Applications';
$nav_section = 'careers';
$required_perm = 'applications.manage';
require __DIR__ . '/_guard.php';

$db = db();

// Filters.
$f_q = trim((string) ($_GET['q'] ?? ''));
$f_job = (int) ($_GET['job'] ?? 0);
$f_status = (string) ($_GET['status'] ?? '');
$f_license = (string) ($_GET['license'] ?? '');
$f_valet = (string) ($_GET['valet'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;

// Overview counts.
$counts = [];
foreach (['new','under_review','interview_scheduled','hired','rejected'] as $st) {
    $counts[$st] = (int) $db->query("SELECT COUNT(*) FROM applications WHERE status = " . $db->quote($st))->fetchColumn();
}
$counts['total'] = (int) $db->query('SELECT COUNT(*) FROM applications')->fetchColumn();

// CSV export.
if (($_GET['export'] ?? '') === 'csv') {
    $where = []; $params = [];
    if ($f_status !== '') { $where[] = 'a.status = ?'; $params[] = $f_status; }
    if ($f_job > 0) { $where[] = 'a.job_id = ?'; $params[] = $f_job; }
    $sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $st = $db->prepare("SELECT a.*, j.title AS job_title FROM applications a LEFT JOIN jobs j ON j.id = a.job_id $sql_where ORDER BY a.created_at DESC");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="applications-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reference','Name','Email','Phone','City','State','Position','License','Valet Cert','Attendant Permit','Prev Valet Exp','Status','Applied']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($out, [$r['reference_id'],$r['full_name'],$r['email'],$r['phone'],$r['city'],$r['state'],
            $r['job_title'] ?: 'General',$r['has_license'],$r['has_valet_cert'],$r['has_attendant_permit'],
            $r['prev_valet_company'],$r['status'],$r['created_at']]);
    }
    fclose($out);
    exit;
}

$where = []; $params = [];
if ($f_q !== '') { $where[] = '(a.full_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ? OR a.reference_id LIKE ?)'; $like = "%$f_q%"; $params = array_merge($params, [$like,$like,$like,$like]); }
if ($f_job > 0) { $where[] = 'a.job_id = ?'; $params[] = $f_job; }
if ($f_status !== '') { $where[] = 'a.status = ?'; $params[] = $f_status; }
if ($f_license !== '') { $where[] = 'a.has_license = ?'; $params[] = $f_license; }
if ($f_valet !== '') { $where[] = 'a.prev_valet_company = ?'; $params[] = $f_valet; }
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) $db->prepare("SELECT COUNT(*) FROM applications a $sql_where")->execute($params) ?: 0;
// Re-run properly for count.
$cs = $db->prepare("SELECT COUNT(*) FROM applications a $sql_where"); $cs->execute($params); $total = (int) $cs->fetchColumn();
$offset = ($page - 1) * $per;
$st = $db->prepare("SELECT a.*, j.title AS job_title FROM applications a LEFT JOIN jobs j ON j.id = a.job_id $sql_where ORDER BY a.created_at DESC LIMIT $per OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$jobs = $db->query('SELECT id, title FROM jobs ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
$statuses = ['new'=>'New','under_review'=>'Under Review','interview_scheduled'=>'Interview Scheduled','interviewed'=>'Interviewed','offered'=>'Offered','hired'=>'Hired','rejected'=>'Rejected','withdrawn'=>'Withdrawn'];

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1>Job Applications</h1>
    <div class="page-actions">
        <a class="btn btn-outline btn-sm" href="<?= e(site_url('admin/applications.php?' . http_build_query(array_merge($_GET, ['export' => 'csv'])))) ?>">Export CSV</a>
    </div>
</div>

<div class="grid-4" style="margin-bottom:16px">
    <?php foreach ([['Total', $counts['total'], '#2b6cb0'], ['New', $counts['new'], '#b7791f'], ['Under Review', $counts['under_review'], '#6b46c1'], ['Interviews', $counts['interview_scheduled'], '#1e8e5a']] as [$lbl, $n, $c]): ?>
    <div class="kpi-card" style="--kpi-accent:<?= $c ?>"><div class="kpi-top"><span class="kpi-label"><?= $lbl ?></span></div><div class="kpi-value"><?= number_format($n) ?></div></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <form method="get" class="filter-bar">
        <div class="field"><label for="fq">Search</label><input id="fq" name="q" value="<?= e($f_q) ?>" placeholder="Name, email, phone, ref…"></div>
        <div class="field narrow"><label for="fjob">Position</label><select id="fjob" name="job"><option value="0">All</option><?php foreach ($jobs as $j): ?><option value="<?= (int)$j['id'] ?>"<?= $f_job === (int)$j['id'] ? ' selected' : '' ?>><?= e($j['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field narrow"><label for="fstat">Status</label><select id="fstat" name="status"><option value="">All</option><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"<?= $f_status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="field narrow"><label for="flic">License</label><select id="flic" name="license"><option value="">All</option><option value="yes"<?= $f_license==='yes'?' selected':'' ?>>Yes</option><option value="no"<?= $f_license==='no'?' selected':'' ?>>No</option></select></div>
        <div class="field narrow"><label for="fval">Valet exp.</label><select id="fval" name="valet"><option value="">All</option><option value="yes"<?= $f_valet==='yes'?' selected':'' ?>>Yes</option><option value="no"<?= $f_valet==='no'?' selected':'' ?>>No</option></select></div>
        <div class="field"><label>&nbsp;</label><button class="btn btn-primary btn-sm">Filter</button></div>
    </form>
</div>

<div class="card">
    <?php if ($rows === []): ?>
        <div class="empty"><strong>No applications found</strong>Try adjusting your filters.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Ref</th><th>Applicant</th><th>Position</th><th>Contact</th><th>License</th><th>Valet Cert</th><th>Permit</th><th>Prev Exp</th><th>Applied</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="mono small"><?= e($r['reference_id']) ?></td>
                <td><strong><?= e($r['full_name']) ?></strong></td>
                <td><?= e($r['job_title'] ?: 'General') ?></td>
                <td class="small"><?= e($r['phone']) ?><br><?= e($r['email']) ?></td>
                <td><?= $r['has_license'] === 'yes' ? 'Yes' : 'No' ?></td>
                <td><?= e(ucfirst($r['has_valet_cert'])) ?></td>
                <td><?= e(ucfirst($r['has_attendant_permit'])) ?></td>
                <td><?= $r['prev_valet_company'] === 'yes' ? 'Yes' : 'No' ?></td>
                <td class="nowrap small"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
                <td><span class="badge"><?= e($statuses[$r['status']] ?? $r['status']) ?></span></td>
                <td><a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/application_view.php?id=' . (int) $r['id'])) ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($total > $per): ?>
    <div class="pagination">
        <?php for ($p = 1; $p <= ceil($total / $per); $p++): ?>
            <a class="<?= $p === $page ? 'active' : '' ?>" href="<?= e(site_url('admin/applications.php?' . http_build_query(array_merge($_GET, ['page' => $p])))) ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
