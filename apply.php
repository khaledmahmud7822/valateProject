<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Job Application';
$page_desc = 'Apply to join Metro Valet Parking. Complete our online job application in a few minutes.';
$page_key = 'careers';

$job_id = (int) ($_GET['job'] ?? 0);
$job = null;
if ($job_id > 0) {
    try {
        $st = db()->prepare("SELECT * FROM jobs WHERE id = ? AND status = 'open' LIMIT 1");
        $st->execute([$job_id]);
        $job = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $job = null; }
}

// Open jobs for the position dropdown.
$jobs = [];
try {
    $jobs = db()->query("SELECT id, title FROM jobs WHERE status = 'open' ORDER BY sort_order, title")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Resume upload dir (private).
$resume_dir = __DIR__ . '/uploads/resumes';
if (!is_dir($resume_dir)) { @mkdir($resume_dir, 0750, true); }
@file_put_contents($resume_dir . '/.htaccess', "Require all denied\n");

$errors = [];
$success_ref = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // Honeypot + rate limit.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $errors[] = 'Submission rejected.';
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    try {
        $rl = db()->prepare("SELECT COUNT(*) FROM applications WHERE ip_address = ? AND created_at > datetime('now', '-1 hour')");
        $rl->execute([$ip]);
        if ((int) $rl->fetchColumn() >= 5) { $errors[] = 'Too many applications from this address. Please try again later.'; }
    } catch (Throwable $e) {}

    $v = function($k, $d = '') { return trim((string) ($_POST[$k] ?? $d)); };
    $f = [
        'job_id' => (int) ($_POST['job_id'] ?? 0),
        'full_name' => $v('full_name'), 'email' => $v('email'), 'phone' => $v('phone'),
        'city' => $v('city'), 'state' => $v('state'), 'contact_method' => $v('contact_method', 'email'),
        'has_license' => $v('has_license'), 'license_state' => $v('license_state'),
        'license_class' => $v('license_class'), 'has_valet_cert' => $v('has_valet_cert', 'notsure'),
        'has_attendant_permit' => $v('has_attendant_permit', 'notsure'),
        'comfortable_automatic' => $v('comfortable_automatic', 'yes'),
        'comfortable_manual' => $v('comfortable_manual', 'no'),
        'prev_valet_company' => $v('prev_valet_company', 'no'),
        'prev_company_name' => $v('prev_company_name'), 'prev_position' => $v('prev_position'),
        'prev_dates' => $v('prev_dates'), 'valet_years' => $v('valet_years'),
        'prev_responsibilities' => $v('prev_responsibilities'),
        'prev_industry_exp' => $v('prev_industry_exp'),
        'employment_type' => $v('employment_type', 'either'),
        'preferred_days' => implode(',', (array) ($_POST['preferred_days'] ?? [])),
        'preferred_shifts' => implode(',', (array) ($_POST['preferred_shifts'] ?? [])),
        'earliest_start' => $v('earliest_start'), 'preferred_location' => $v('preferred_location'),
        'can_perform_duties' => $v('can_perform_duties', 'yes'),
        'cover_letter' => $v('cover_letter'), 'comments' => $v('comments'),
    ];

    // Validation.
    if ($f['full_name'] === '') $errors[] = 'Full legal name is required.';
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if ($f['phone'] === '') $errors[] = 'Phone number is required.';
    if ($f['city'] === '' || $f['state'] === '') $errors[] = 'City and state are required.';
    if (!in_array($f['has_license'], ['yes','no'], true)) $errors[] = 'Please answer the driver\'s license question.';
    if ($f['has_license'] === 'yes' && $f['license_state'] === '') $errors[] = 'License issuing state is required.';
    if (empty($_POST['accuracy_confirmed'])) $errors[] = 'Please confirm your information is accurate.';
    if (empty($_POST['privacy_acknowledged'])) $errors[] = 'Please acknowledge the privacy notice.';

    // Resume upload.
    $resume_path = '';
    if (!empty($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
        $up = $_FILES['resume'];
        if ($up['size'] > 5 * 1048576) {
            $errors[] = 'Résumé must be under 5 MB.';
        } else {
            $ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf','doc','docx'], true)) {
                $errors[] = 'Résumé must be PDF, DOC, or DOCX.';
            } else {
                // Basic content check.
                $fh = fopen($up['tmp_name'], 'rb');
                $head = $fh ? fread($fh, 8) : '';
                if ($fh) fclose($fh);
                $ok = ($ext === 'pdf' && str_starts_with($head, '%PDF'))
                   || ($ext === 'doc' && str_starts_with($head, "\xD0\xCF\x11\xE0"))
                   || ($ext === 'docx' && str_starts_with($head, 'PK'));
                if (!$ok) { $errors[] = 'Résumé file appears to be corrupt or mislabeled.'; }
                else {
                    $resume_path = 'uploads/resumes/' . bin2hex(random_bytes(16)) . '.' . $ext;
                    if (!move_uploaded_file($up['tmp_name'], __DIR__ . '/' . $resume_path)) {
                        $errors[] = 'Could not save résumé. Please try again.';
                        $resume_path = '';
                    }
                }
            }
        }
    } elseif (!empty($_FILES['resume']) && $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Résumé upload failed. Please try again.';
    }

    if ($errors === []) {
        $ref = 'MVP-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $now = date('Y-m-d H:i:s');
        try {
            $db = db();
            $db->prepare(
                "INSERT INTO applications (reference_id, job_id, full_name, email, phone, city, state, contact_method,
                 has_license, license_state, license_class, has_valet_cert, has_attendant_permit,
                 comfortable_automatic, comfortable_manual, prev_valet_company, prev_company_name, prev_position,
                 prev_dates, valet_years, prev_responsibilities, prev_industry_exp, employment_type, preferred_days,
                 preferred_shifts, earliest_start, preferred_location, can_perform_duties, resume_path, cover_letter,
                 comments, accuracy_confirmed, privacy_acknowledged, status, ip_address, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $ref, $f['job_id'] ?: null, $f['full_name'], $f['email'], $f['phone'], $f['city'], $f['state'],
                $f['contact_method'], $f['has_license'], $f['license_state'], $f['license_class'],
                $f['has_valet_cert'], $f['has_attendant_permit'], $f['comfortable_automatic'], $f['comfortable_manual'],
                $f['prev_valet_company'], $f['prev_company_name'], $f['prev_position'], $f['prev_dates'],
                $f['valet_years'], $f['prev_responsibilities'], $f['prev_industry_exp'], $f['employment_type'],
                $f['preferred_days'], $f['preferred_shifts'], $f['earliest_start'], $f['preferred_location'],
                $f['can_perform_duties'], $resume_path, $f['cover_letter'], $f['comments'], 1, 1,
                'new', $ip, $now, $now,
            ]);
            $app_id = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO application_activity (application_id, actor, action, details, created_at) VALUES (?,?,?,?,?)")
               ->execute([$app_id, 'applicant', 'submitted', 'Reference ' . $ref, $now]);

            // Confirmation email to applicant.
            $job_title = 'General application';
            if ($f['job_id']) {
                $jt = $db->prepare('SELECT title FROM jobs WHERE id = ?');
                $jt->execute([$f['job_id']]);
                $job_title = $jt->fetchColumn() ?: $job_title;
            }
            try {
                send_mail_raw($f['email'], 'Application received — Metro Valet Parking',
                    '<p>Dear ' . htmlspecialchars($f['full_name']) . ',</p>'
                    . '<p>Thank you for your interest in joining Metro Valet Parking. Your application has been received successfully.</p>'
                    . '<p><strong>Reference ID:</strong> ' . htmlspecialchars($ref) . '<br>'
                    . '<strong>Position:</strong> ' . htmlspecialchars($job_title) . '</p>'
                    . '<p>Our hiring team will review your information and contact you if your qualifications match an available opportunity.</p>'
                    . '<p>— Metro Valet Parking Hiring Team</p>');
            } catch (Throwable $e) {}

            // Notify hiring team.
            try {
                $notify = trim((string) setting('hiring_email', ''));
                if ($notify === '') { $notify = trim((string) setting('admin_email', '')); }
                if ($notify !== '') {
                    send_mail_raw($notify, '[Careers] New application: ' . $f['full_name'] . ' (' . $ref . ')',
                        '<p>A new job application was submitted.</p>'
                        . '<ul><li><strong>Name:</strong> ' . htmlspecialchars($f['full_name']) . '</li>'
                        . '<li><strong>Position:</strong> ' . htmlspecialchars($job_title) . '</li>'
                        . '<li><strong>Email:</strong> ' . htmlspecialchars($f['email']) . '</li>'
                        . '<li><strong>Phone:</strong> ' . htmlspecialchars($f['phone']) . '</li>'
                        . '<li><strong>Reference:</strong> ' . htmlspecialchars($ref) . '</li></ul>'
                        . '<p><a href="' . htmlspecialchars(site_url('admin/application_view.php?id=' . $app_id)) . '">Review application</a></p>');
                }
            } catch (Throwable $e) {}

            $success_ref = $ref;
        } catch (Throwable $e) {
            $errors[] = 'Could not save your application. Please try again.';
        }
    }
}

include __DIR__.'/includes/site_header.php';
?>

<?php if ($success_ref !== ''): ?>
<section class="section"><div class="container" style="max-width:44rem">
  <div class="card" style="text-align:center;padding:3rem 2rem">
    <div style="font-size:3rem;color:#1e8e5a">&#10003;</div>
    <h2>Application Received</h2>
    <p class="lead">Thank you for your interest in joining Metro Valet Parking. Your application has been received successfully. Our hiring team will review your information and contact you if your qualifications match an available opportunity.</p>
    <p><strong>Your reference ID:</strong> <span class="mono"><?= e($success_ref) ?></span></p>
    <p class="muted small">A confirmation email has been sent to you. Please keep your reference ID for your records.</p>
    <p><a class="btn btn-gold" href="careers.php">Back to Careers</a></p>
  </div>
</div></section>
<?php include __DIR__.'/includes/site_footer.php'; return; endif; ?>

<section class="hero hero-small"><div class="container hero-inner reveal">
  <h1>Job Application</h1>
  <p class="sub"><?= $job ? 'Applying for: <strong>' . e($job['title']) . '</strong>' : 'General application — tell us about yourself and we\'ll match you to the right role.' ?></p>
</div></section>

<section class="section apply-hero" id="apply">
<div class="container" style="max-width:56rem">
<div class="apply-intro reveal">
  <span class="eyebrow">Join Our Team</span>
  <h2>Job Application</h2>
  <p class="lead"><?= $job ? 'You are applying for <strong>' . e($job['title']) . '</strong>.' : 'Tell us about yourself — it only takes a few minutes.' ?></p>
  <div class="apply-steps" aria-hidden="true">
    <div class="apply-step"><span class="step-dot">A</span><span>Personal</span></div>
    <div class="apply-step"><span class="step-dot">B</span><span>License</span></div>
    <div class="apply-step"><span class="step-dot">C</span><span>Experience</span></div>
    <div class="apply-step"><span class="step-dot">D</span><span>Availability</span></div>
    <div class="apply-step"><span class="step-dot">E</span><span>Résumé</span></div>
  </div>
</div>
<?php if ($errors !== []): ?>
  <div class="alert alert-error" role="alert"><strong>Please fix the following:</strong><ul style="margin:.5rem 0 0;padding-left:1.2rem"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="appForm" novalidate class="apply-form">
<?= csrf_field() ?>
<input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off" aria-hidden="true">
<input type="hidden" name="job_id" value="<?= (int) ($job['id'] ?? 0) ?>">

<?php if (!$job && $jobs !== []): ?>
<div class="apply-card reveal">
  <div class="apply-card-head"><span class="sec-badge">★</span><div><h3>Position</h3><p>Which role interests you?</p></div></div>
  <div class="field"><label for="f_job">Position applying for</label>
    <select id="f_job" name="job_id"><option value="0">General application</option>
    <?php foreach ($jobs as $j): ?><option value="<?= (int)$j['id'] ?>"><?= e($j['title']) ?></option><?php endforeach; ?>
    </select></div>
</div>
<?php endif; ?>

<div class="apply-card reveal">
  <div class="apply-card-head"><span class="sec-badge">A</span><div><h3>Personal Information</h3><p>How we reach you</p></div></div>
  <div class="form-grid">
    <div class="field"><label for="f_name">Full legal name <em>*</em></label><input id="f_name" name="full_name" required autocomplete="name" placeholder="Jane A. Smith"></div>
    <div class="field"><label for="f_email">Email address <em>*</em></label><input id="f_email" type="email" name="email" required autocomplete="email" placeholder="you@example.com"></div>
    <div class="field"><label for="f_phone">Phone number <em>*</em></label><input id="f_phone" type="tel" name="phone" required autocomplete="tel" placeholder="(313) 555-0100"></div>
    <div class="field"><label for="f_contact">Preferred contact method</label>
      <select id="f_contact" name="contact_method"><option value="email">Email</option><option value="phone">Phone call</option><option value="text">Text message</option></select></div>
    <div class="field"><label for="f_city">City <em>*</em></label><input id="f_city" name="city" required autocomplete="address-level2" placeholder="Detroit"></div>
    <div class="field"><label for="f_state">State <em>*</em></label><input id="f_state" name="state" required autocomplete="address-level1" maxlength="2" placeholder="MI"></div>
  </div>
</div>

<div class="apply-card reveal">
  <div class="apply-card-head"><span class="sec-badge">B</span><div><h3>Driver's License</h3><p>Driving qualifications</p></div></div>
  <div class="field"><label class="q-label">Do you have a valid driver's license? <em>*</em></label>
    <div class="pill-group">
      <label class="pill"><input type="radio" name="has_license" value="yes" required><span>Yes</span></label>
      <label class="pill"><input type="radio" name="has_license" value="no"><span>No</span></label>
    </div></div>
  <div class="form-grid" id="licenseDetails" style="display:none">
    <div class="field"><label for="f_lstate">Issuing state</label><input id="f_lstate" name="license_state" maxlength="2" placeholder="MI"></div>
    <div class="field"><label for="f_lclass">License class <span class="opt">(if relevant)</span></label><input id="f_lclass" name="license_class" placeholder="e.g. D, CDL"></div>
  </div>
  <div class="field"><label class="q-label">Valet parking license or certification?</label>
    <div class="pill-group">
      <label class="pill"><input type="radio" name="has_valet_cert" value="yes"><span>Yes</span></label>
      <label class="pill"><input type="radio" name="has_valet_cert" value="no"><span>No</span></label>
      <label class="pill"><input type="radio" name="has_valet_cert" value="notsure" checked><span>Not sure</span></label>
    </div></div>
  <div class="field"><label class="q-label">Parking attendant license or permit?</label>
    <div class="pill-group">
      <label class="pill"><input type="radio" name="has_attendant_permit" value="yes"><span>Yes</span></label>
      <label class="pill"><input type="radio" name="has_attendant_permit" value="no"><span>No</span></label>
      <label class="pill"><input type="radio" name="has_attendant_permit" value="notsure" checked><span>Not sure</span></label>
    </div></div>
  <div class="form-grid">
    <div class="field"><label class="q-label">Comfortable driving automatic?</label>
      <div class="pill-group">
        <label class="pill"><input type="radio" name="comfortable_automatic" value="yes" checked><span>Yes</span></label>
        <label class="pill"><input type="radio" name="comfortable_automatic" value="no"><span>No</span></label>
      </div></div>
    <div class="field"><label class="q-label">Comfortable driving manual?</label>
      <div class="pill-group">
        <label class="pill"><input type="radio" name="comfortable_manual" value="yes"><span>Yes</span></label>
        <label class="pill"><input type="radio" name="comfortable_manual" value="no" checked><span>No</span></label>
      </div></div>
  </div>
  <p class="form-note">We never ask for license numbers or photos at this stage.</p>
</div>

<div class="apply-card reveal">
  <div class="apply-card-head"><span class="sec-badge">C</span><div><h3>Experience</h3><p>Your background</p></div></div>
  <div class="field"><label class="q-label">Previously worked for a valet parking company?</label>
    <div class="pill-group">
      <label class="pill"><input type="radio" name="prev_valet_company" value="yes"><span>Yes</span></label>
      <label class="pill"><input type="radio" name="prev_valet_company" value="no" checked><span>No</span></label>
    </div></div>
  <div id="valetExp" style="display:none">
    <div class="form-grid">
      <div class="field"><label for="f_pcname">Previous company</label><input id="f_pcname" name="prev_company_name"></div>
      <div class="field"><label for="f_ppos">Job position</label><input id="f_ppos" name="prev_position"></div>
      <div class="field"><label for="f_pdates">Employment dates</label><input id="f_pdates" name="prev_dates" placeholder="e.g. 2022–2024"></div>
      <div class="field"><label for="f_vyears">Years of valet experience</label><input id="f_vyears" name="valet_years" placeholder="e.g. 3"></div>
    </div>
    <div class="field"><label for="f_presp">Key responsibilities</label><textarea id="f_presp" name="prev_responsibilities" rows="3" placeholder="What did you do day-to-day?"></textarea></div>
  </div>
  <div class="field"><label for="f_pind">Other relevant experience <span class="opt">(parking, hospitality, hotels, customer service)</span></label>
    <textarea id="f_pind" name="prev_industry_exp" rows="3" placeholder="Tell us about any related work…"></textarea></div>
</div>

<div class="apply-card reveal">
  <div class="apply-card-head"><span class="sec-badge">D</span><div><h3>Availability</h3><p>When you can work</p></div></div>
  <div class="field"><label class="q-label">Employment type</label>
    <div class="pill-group">
      <label class="pill"><input type="radio" name="employment_type" value="full-time"><span>Full-time</span></label>
      <label class="pill"><input type="radio" name="employment_type" value="part-time"><span>Part-time</span></label>
      <label class="pill"><input type="radio" name="employment_type" value="either" checked><span>Either</span></label>
    </div></div>
  <div class="field"><label class="q-label">Preferred days</label>
    <div class="pill-group wrap"><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
      <label class="pill pill-sm"><input type="checkbox" name="preferred_days[]" value="<?= $d ?>"><span><?= $d ?></span></label><?php endforeach; ?></div></div>
  <div class="field"><label class="q-label">Preferred shifts</label>
    <div class="pill-group wrap"><?php foreach (['Morning','Afternoon','Evening','Overnight','Weekends'] as $sh): ?>
      <label class="pill pill-sm"><input type="checkbox" name="preferred_shifts[]" value="<?= $sh ?>"><span><?= $sh ?></span></label><?php endforeach; ?></div></div>
  <div class="form-grid">
    <div class="field"><label for="f_start">Earliest start date</label><input id="f_start" type="date" name="earliest_start"></div>
    <div class="field"><label for="f_ploc">Preferred work area</label><input id="f_ploc" name="preferred_location" placeholder="e.g. Downtown Detroit"></div>
  </div>
  <div class="field"><label class="q-label">Able to perform essential duties with or without reasonable accommodation?</label>
    <div class="pill-group">
      <label class="pill"><input type="radio" name="can_perform_duties" value="yes" checked><span>Yes</span></label>
      <label class="pill"><input type="radio" name="can_perform_duties" value="no"><span>No</span></label>
    </div></div>
</div>

<div class="apply-card reveal">
  <div class="apply-card-head"><span class="sec-badge">E</span><div><h3>Résumé &amp; Final Details</h3><p>Almost done</p></div></div>
  <div class="field"><label for="f_resume">Résumé <span class="opt">(PDF, DOC, DOCX · max 5 MB)</span></label>
    <div class="file-drop"><input id="f_resume" type="file" name="resume" accept=".pdf,.doc,.docx"><span class="file-hint">Choose a file or drag it here</span></div></div>
  <div class="field"><label for="f_cover">Cover letter <span class="opt">(optional)</span></label><textarea id="f_cover" name="cover_letter" rows="4" placeholder="Why do you want to join Metro Valet?"></textarea></div>
  <div class="field"><label for="f_comments">Anything else we should know?</label><textarea id="f_comments" name="comments" rows="3"></textarea></div>
  <div class="consent-box">
    <label class="check-line"><input type="checkbox" name="accuracy_confirmed" value="1" required><span>I confirm the information I've provided is accurate and complete. <em>*</em></span></label>
    <label class="check-line"><input type="checkbox" name="privacy_acknowledged" value="1" required><span>I've read the <a href="privacy.php" target="_blank">privacy notice</a> and consent to Metro Valet Parking processing my application. <em>*</em></span></label>
  </div>
</div>

<div class="apply-submit reveal">
  <button type="submit" class="btn btn-gold btn-xl" id="submitBtn">Submit Application →</button>
  <p class="form-note">We'll review your application and reach out if there's a match. No spam, ever.</p>
</div>
</form>
</div></section>

<script>
(function(){
  var form = document.getElementById('appForm');
  var btn = document.getElementById('submitBtn');
  form.addEventListener('submit', function(){ btn.disabled = true; btn.textContent = 'Submitting…'; });
  function toggle(name, id) {
    document.querySelectorAll('input[name="'+name+'"]').forEach(function(r){
      r.addEventListener('change', function(){
        document.getElementById(id).style.display =
          document.querySelector('input[name="'+name+'"]:checked').value === 'yes' ? '' : 'none';
      });
    });
  }
  toggle('has_license', 'licenseDetails');
  toggle('prev_valet_company', 'valetExp');
})();
</script>

<?php include __DIR__.'/includes/site_footer.php'; ?>
