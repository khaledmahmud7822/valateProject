<?php
// admin/jobs.php — job postings CRUD.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Job Postings';
$nav_section = 'careers';
$required_perm = 'careers.manage';
require __DIR__ . '/_guard.php';

$db = db();
$action = (string) ($_GET['action'] ?? '');
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = (string) ($_POST['op'] ?? '');
    if ($op === 'save') {
        $jid = (int) ($_POST['id'] ?? 0);
        $data = [
            trim((string) ($_POST['title'] ?? '')),
            (string) ($_POST['job_type'] ?? 'full-time'),
            trim((string) ($_POST['location'] ?? '')),
            trim((string) ($_POST['description'] ?? '')),
            trim((string) ($_POST['qualifications'] ?? '')),
            trim((string) ($_POST['pay_range'] ?? '')),
            (string) ($_POST['status'] ?? 'open'),
            (int) ($_POST['sort_order'] ?? 0),
        ];
        if ($data[0] === '') {
            flash('Job title is required.', 'error');
        } else {
            $now = date('Y-m-d H:i:s');
            if ($jid > 0) {
                $db->prepare('UPDATE jobs SET title=?, job_type=?, location=?, description=?, qualifications=?, pay_range=?, status=?, sort_order=?, updated_at=? WHERE id=?')
                   ->execute([...$data, $now, $jid]);
                audit('job.updated', 'job', $jid, ['title' => $data[0]]);
                flash('Job posting updated.', 'success');
            } else {
                $db->prepare('INSERT INTO jobs (title, job_type, location, description, qualifications, pay_range, status, sort_order, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([...$data, $_SESSION['admin_id'] ?? null, $now, $now]);
                $jid = (int) $db->lastInsertId();
                audit('job.created', 'job', $jid, ['title' => $data[0]]);
                flash('Job posting created.', 'success');
            }
        }
        redirect(site_url('admin/jobs.php'));
    }
    if ($op === 'delete' && $jid = (int) ($_POST['id'] ?? 0)) {
        // Keep applications, just detach.
        $db->prepare('UPDATE applications SET job_id = NULL WHERE job_id = ?')->execute([$jid]);
        $db->prepare('DELETE FROM jobs WHERE id = ?')->execute([$jid]);
        audit('job.deleted', 'job', $jid, []);
        flash('Job posting deleted. Applications were kept.', 'success');
        redirect(site_url('admin/jobs.php'));
    }
    if ($op === 'toggle' && $jid = (int) ($_POST['id'] ?? 0)) {
        $db->prepare("UPDATE jobs SET status = CASE WHEN status = 'open' THEN 'closed' ELSE 'open' END, updated_at = datetime('now') WHERE id = ?")->execute([$jid]);
        flash('Job status updated.', 'success');
        redirect(site_url('admin/jobs.php'));
    }
}

$edit = null;
if ($action === 'edit' && $id > 0) {
    $st = $db->prepare('SELECT * FROM jobs WHERE id = ?');
    $st->execute([$id]);
    $edit = $st->fetch(PDO::FETCH_ASSOC);
}
$is_form = ($action === 'new' || ($action === 'edit' && $edit));

$jobs = $db->query('SELECT j.*, (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS app_count FROM jobs j ORDER BY sort_order, created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1>Job Postings</h1>
    <div class="page-actions">
        <?php if (!$is_form): ?><a class="btn btn-primary btn-sm" href="<?= e(site_url('admin/jobs.php?action=new')) ?>">+ New posting</a><?php endif; ?>
    </div>
</div>

<?php if ($is_form): ?>
<div class="card">
    <h2><?= $edit ? 'Edit posting' : 'New posting' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save">
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <div class="form-grid">
            <div class="field"><label for="j_title">Job title *</label><input id="j_title" name="title" required value="<?= e($edit['title'] ?? '') ?>"></div>
            <div class="field"><label for="j_type">Job type</label>
                <select id="j_type" name="job_type">
                    <?php foreach (['full-time' => 'Full-time', 'part-time' => 'Part-time', 'contract' => 'Contract', 'seasonal' => 'Seasonal'] as $v => $l): ?>
                    <option value="<?= $v ?>"<?= ($edit['job_type'] ?? 'full-time') === $v ? ' selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field"><label for="j_loc">Work location</label><input id="j_loc" name="location" value="<?= e($edit['location'] ?? '') ?>" placeholder="e.g. Downtown Detroit"></div>
            <div class="field"><label for="j_pay">Pay range</label><input id="j_pay" name="pay_range" value="<?= e($edit['pay_range'] ?? '') ?>" placeholder="e.g. $16–$19/hr"></div>
            <div class="field"><label for="j_status">Status</label>
                <select id="j_status" name="status">
                    <option value="open"<?= ($edit['status'] ?? 'open') === 'open' ? ' selected' : '' ?>>Open</option>
                    <option value="closed"<?= ($edit['status'] ?? '') === 'closed' ? ' selected' : '' ?>>Closed</option>
                </select></div>
            <div class="field"><label for="j_sort">Sort order</label><input id="j_sort" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>"></div>
        </div>
        <div class="field"><label for="j_desc">Job description</label><textarea id="j_desc" name="description" rows="6"><?= e($edit['description'] ?? '') ?></textarea></div>
        <div class="field"><label for="j_qual">Qualifications</label><textarea id="j_qual" name="qualifications" rows="4"><?= e($edit['qualifications'] ?? '') ?></textarea></div>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Save posting</button>
            <a class="btn btn-ghost" href="<?= e(site_url('admin/jobs.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <?php if ($jobs === []): ?>
        <div class="empty"><strong>No job postings yet</strong>Create your first posting to list it on the careers page.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Title</th><th>Type</th><th>Location</th><th>Status</th><th>Applications</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
            <tr>
                <td><strong><?= e($j['title']) ?></strong></td>
                <td><?= e(ucfirst($j['job_type'])) ?></td>
                <td><?= e($j['location']) ?></td>
                <td><?= $j['status'] === 'open' ? '<span class="badge badge-green">Open</span>' : '<span class="badge badge-gray">Closed</span>' ?></td>
                <td><a href="<?= e(site_url('admin/applications.php?job=' . (int) $j['id'])) ?>"><?= (int) $j['app_count'] ?></a></td>
                <td><div class="row-actions">
                    <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/jobs.php?action=edit&id=' . (int) $j['id'])) ?>">Edit</a>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>"><button class="btn btn-outline btn-xs"><?= $j['status'] === 'open' ? 'Close' : 'Reopen' ?></button></form>
                    <form method="post" class="inline-form" data-confirm="Delete this posting? Applications are kept."><?= csrf_field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>"><button class="btn btn-danger btn-xs">Delete</button></form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
