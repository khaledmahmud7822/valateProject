<?php
// admin/application_view.php — application details, status, notes, emails.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Application Details';
$nav_section = 'careers';
$required_perm = 'applications.manage';
require __DIR__ . '/_guard.php';

$db = db();
$id = (int) ($_GET['id'] ?? 0);
$st = $db->prepare('SELECT a.*, j.title AS job_title FROM applications a LEFT JOIN jobs j ON j.id = a.job_id WHERE a.id = ?');
$st->execute([$id]);
$app = $st->fetch(PDO::FETCH_ASSOC);
if (!$app) { flash('Application not found.', 'error'); redirect(site_url('admin/applications.php')); }

$statuses = ['new'=>'New','under_review'=>'Under Review','interview_scheduled'=>'Interview Scheduled','interviewed'=>'Interviewed','offered'=>'Offered','hired'=>'Hired','rejected'=>'Rejected','withdrawn'=>'Withdrawn'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = (string) ($_POST['op'] ?? '');
    $now = date('Y-m-d H:i:s');
    $admin_id = $_SESSION['admin_id'] ?? null;

    if ($op === 'status') {
        $ns = (string) ($_POST['status'] ?? '');
        if (!isset($statuses[$ns])) { flash('Invalid status.', 'error'); }
        else {
            $interview = trim((string) ($_POST['interview_date'] ?? ''));
            $db->prepare('UPDATE applications SET status = ?, interview_date = ?, updated_at = ? WHERE id = ?')
               ->execute([$ns, $interview, $now, $id]);
            $db->prepare("INSERT INTO application_activity (application_id, actor, action, details, created_at) VALUES (?,?,?,?,?)")
               ->execute([$id, 'admin', 'status_changed', $statuses[$ns] . ($interview ? ' — interview: ' . $interview : ''), $now]);
            audit('application.status', 'application', $id, ['status' => $ns]);
            flash('Status updated to ' . $statuses[$ns] . '.', 'success');
        }
        redirect(site_url('admin/application_view.php?id=' . $id));
    }
    if ($op === 'note') {
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note !== '') {
            $db->prepare('INSERT INTO application_notes (application_id, admin_id, note, created_at) VALUES (?,?,?,?)')
               ->execute([$id, $admin_id, $note, $now]);
            flash('Note added.', 'success');
        }
        redirect(site_url('admin/application_view.php?id=' . $id));
    }
    if ($op === 'email') {
        // Human-reviewed email to applicant — confirm via data-confirm on the button.
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($subject === '' || $body === '') { flash('Subject and message are required.', 'error'); }
        else {
            $html = '<p>Dear ' . htmlspecialchars($app['full_name']) . ',</p>' . nl2br(htmlspecialchars($body))
                  . '<p>— Metro Valet Parking Hiring Team<br>Reference: ' . htmlspecialchars($app['reference_id']) . '</p>';
            try {
                $ok = send_mail_raw($app['email'], $subject, $html);
                $db->prepare("INSERT INTO application_activity (application_id, actor, action, details, created_at) VALUES (?,?,?,?,?)")
                   ->execute([$id, 'admin', 'email_sent', $subject, $now]);
                flash($ok ? 'Email sent.' : 'Email failed to send.', $ok ? 'success' : 'error');
            } catch (Throwable $e) { flash('Email failed: ' . $e->getMessage(), 'error'); }
        }
        redirect(site_url('admin/application_view.php?id=' . $id));
    }
    if ($op === 'delete') {
        if (!empty($app['resume_path']) && file_exists(__DIR__ . '/../' . $app['resume_path'])) {
            @unlink(__DIR__ . '/../' . $app['resume_path']);
        }
        $db->prepare('DELETE FROM application_notes WHERE application_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM application_activity WHERE application_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM applications WHERE id = ?')->execute([$id]);
        audit('application.deleted', 'application', $id, ['ref' => $app['reference_id']]);
        flash('Application deleted.', 'success');
        redirect(site_url('admin/applications.php'));
    }
}

$notes = $db->prepare('SELECT n.*, u.name AS admin_name FROM application_notes n LEFT JOIN users u ON u.id = n.admin_id WHERE n.application_id = ? ORDER BY n.created_at DESC');
$notes->execute([$id]); $notes = $notes->fetchAll(PDO::FETCH_ASSOC);
$activity = $db->prepare('SELECT * FROM application_activity WHERE application_id = ? ORDER BY created_at DESC');
$activity->execute([$id]); $activity = $activity->fetchAll(PDO::FETCH_ASSOC);

$yn = function($v) { return $v === 'yes' ? 'Yes' : ($v === 'no' ? 'No' : ucfirst((string)$v)); };

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1><?= e($app['full_name']) ?></h1>
    <div class="page-actions"><a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/applications.php')) ?>">← All applications</a></div>
</div>

<div class="grid-2">
<div>
    <div class="card">
        <h2>Application <span class="mono"><?= e($app['reference_id']) ?></span></h2>
        <dl class="dl">
            <dt>Position</dt><dd><?= e($app['job_title'] ?: 'General application') ?></dd>
            <dt>Status</dt><dd><span class="badge"><?= e($statuses[$app['status']] ?? $app['status']) ?></span></dd>
            <dt>Applied</dt><dd><?= e($app['created_at']) ?></dd>
            <?php if ($app['interview_date'] !== ''): ?><dt>Interview</dt><dd><?= e($app['interview_date']) ?></dd><?php endif; ?>
        </dl>
        <h3>Personal Information</h3>
        <dl class="dl">
            <dt>Name</dt><dd><?= e($app['full_name']) ?></dd>
            <dt>Email</dt><dd><?= e($app['email']) ?></dd>
            <dt>Phone</dt><dd><?= e($app['phone']) ?></dd>
            <dt>Location</dt><dd><?= e($app['city']) ?>, <?= e($app['state']) ?></dd>
            <dt>Contact via</dt><dd><?= e(ucfirst($app['contact_method'])) ?></dd>
        </dl>
        <h3>Driver's License</h3>
        <dl class="dl">
            <dt>Valid license</dt><dd><?= $yn($app['has_license']) ?></dd>
            <?php if ($app['has_license'] === 'yes'): ?>
            <dt>State / Class</dt><dd><?= e($app['license_state']) ?><?= $app['license_class'] !== '' ? ' / ' . e($app['license_class']) : '' ?></dd>
            <?php endif; ?>
            <dt>Valet cert</dt><dd><?= $yn($app['has_valet_cert']) ?></dd>
            <dt>Attendant permit</dt><dd><?= $yn($app['has_attendant_permit']) ?></dd>
            <dt>Automatic</dt><dd><?= $yn($app['comfortable_automatic']) ?></dd>
            <dt>Manual</dt><dd><?= $yn($app['comfortable_manual']) ?></dd>
        </dl>
        <h3>Experience</h3>
        <dl class="dl">
            <dt>Prior valet co.</dt><dd><?= $yn($app['prev_valet_company']) ?></dd>
            <?php if ($app['prev_valet_company'] === 'yes'): ?>
            <dt>Company</dt><dd><?= e($app['prev_company_name']) ?></dd>
            <dt>Position</dt><dd><?= e($app['prev_position']) ?></dd>
            <dt>Dates</dt><dd><?= e($app['prev_dates']) ?></dd>
            <dt>Years</dt><dd><?= e($app['valet_years']) ?></dd>
            <dt>Duties</dt><dd><?= nl2br(e($app['prev_responsibilities'])) ?></dd>
            <?php endif; ?>
            <dt>Other experience</dt><dd><?= nl2br(e($app['prev_industry_exp'])) ?: '—' ?></dd>
        </dl>
        <h3>Availability</h3>
        <dl class="dl">
            <dt>Type</dt><dd><?= e(ucfirst($app['employment_type'])) ?></dd>
            <dt>Days</dt><dd><?= e($app['preferred_days']) ?: '—' ?></dd>
            <dt>Shifts</dt><dd><?= e($app['preferred_shifts']) ?: '—' ?></dd>
            <dt>Start date</dt><dd><?= e($app['earliest_start']) ?: '—' ?></dd>
            <dt>Location</dt><dd><?= e($app['preferred_location']) ?: '—' ?></dd>
            <dt>Can perform duties</dt><dd><?= $yn($app['can_perform_duties']) ?></dd>
        </dl>
        <h3>Additional</h3>
        <dl class="dl">
            <?php if ($app['resume_path'] !== '' && file_exists(__DIR__ . '/../' . $app['resume_path'])): ?>
            <dt>Résumé</dt><dd><a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/resume_download.php?id=' . $id)) ?>">Download résumé</a></dd>
            <?php endif; ?>
            <dt>Cover letter</dt><dd><?= nl2br(e($app['cover_letter'])) ?: '—' ?></dd>
            <dt>Comments</dt><dd><?= nl2br(e($app['comments'])) ?: '—' ?></dd>
        </dl>
    </div>

    <div class="card">
        <h2>Activity History</h2>
        <?php if ($activity === []): ?><p class="muted">No activity yet.</p><?php else: ?>
        <ul class="activity-list"><?php foreach ($activity as $a): ?>
            <li><strong><?= e($a['action']) ?></strong> <span class="muted small"><?= e($a['actor']) ?> · <?= e($a['created_at']) ?></span><br><?= e($a['details']) ?></li>
        <?php endforeach; ?></ul><?php endif; ?>
    </div>
</div>

<div>
    <div class="card">
        <h2>Status</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="op" value="status">
            <div class="field"><label for="s_status">Change status</label>
                <select id="s_status" name="status"><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"<?= $app['status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="s_int">Interview date/time (optional)</label><input id="s_int" name="interview_date" value="<?= e($app['interview_date']) ?>" placeholder="2026-10-15 10:00 AM"></div>
            <button class="btn btn-primary btn-sm">Update status</button>
        </form>
    </div>

    <div class="card">
        <h2>Email Applicant</h2>
        <p class="muted small">Reviewed by you before sending. Use for interview invites, updates, offers, or rejections.</p>
        <form method="post" data-confirm="Send this email to the applicant?">
            <?= csrf_field() ?><input type="hidden" name="op" value="email">
            <div class="field"><label for="e_subj">Subject</label><input id="e_subj" name="subject" required placeholder="Interview invitation — Metro Valet Parking"></div>
            <div class="field"><label for="e_body">Message</label><textarea id="e_body" name="body" rows="6" required></textarea></div>
            <button class="btn btn-outline btn-sm">Send email</button>
        </form>
    </div>

    <div class="card">
        <h2>Internal Notes</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="op" value="note">
            <div class="field"><label for="n_note">Add note (only visible to staff)</label><textarea id="n_note" name="note" rows="3" required></textarea></div>
            <button class="btn btn-outline btn-sm">Add note</button>
        </form>
        <?php foreach ($notes as $n): ?>
            <div class="note"><p><?= nl2br(e($n['note'])) ?></p><p class="muted small"><?= e($n['admin_name'] ?: 'Staff') ?> · <?= e($n['created_at']) ?></p></div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Danger Zone</h2>
        <form method="post" data-confirm="Permanently delete this application and its résumé? This cannot be undone.">
            <?= csrf_field() ?><input type="hidden" name="op" value="delete">
            <button class="btn btn-danger btn-sm">Delete application</button>
        </form>
    </div>
</div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
