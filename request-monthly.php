<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Request Monthly Parking';
$page_desc = 'Request monthly parking as an individual or a company at Metro Valet Parking managed locations across Metro Detroit.';
$page_key = 'monthly';

/* ---- local anti-spam helpers ---- */
function mv_captcha_new() {
    $a = random_int(3, 9); $b = random_int(2, 9);
    $_SESSION['math_q'] = $a . ' + ' . $b;
    $_SESSION['math_a'] = $a + $b;
}
if (!isset($_SESSION['math_a'])) { mv_captcha_new(); }

// Locations open to monthly requests
$locations = [];
try {
    $st = db()->prepare("SELECT id, name, city FROM parking_locations WHERE is_published = 1 AND accepts_requests = 1 AND monthly_available = 1 ORDER BY name");
    $st->execute();
    $locations = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $locations = []; }
$validLocationIds = array_map(fn($l) => (string)$l['id'], $locations);

$preselect = trim($_GET['location'] ?? '');
if ($preselect !== '' && !in_array($preselect, $validLocationIds, true)) { $preselect = ''; }

$rtype = ($_SERVER['REQUEST_METHOD'] === 'POST') ? trim((string)($_POST['request_type'] ?? 'individual')) : 'individual';
if (!in_array($rtype, ['individual', 'company'], true)) { $rtype = 'individual'; }

$errors = [];
$old = [
    // individual
    'full_name' => '', 'email' => '', 'phone' => '', 'location_id' => $preselect, 'start_date' => '',
    'vehicle_make' => '', 'vehicle_model' => '', 'plate' => '', 'plate_state' => '',
    'contact_method' => '', 'comments' => '',
    // company (distinct field names so panels never collide)
    'company_name' => '', 'contact_person' => '', 'c_email' => '', 'c_phone' => '',
    'c_location_id' => $preselect, 'spaces' => '', 'c_start_date' => '',
    'billing_contact' => '', 'billing_email' => '', 'c_comments' => '',
];
$termsAccepted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['website_url'])) { redirect('thank-you.php'); }

    foreach (array_keys($old) as $k) {
        $old[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $termsAccepted = !empty($_POST['terms']);

    verify_csrf(); // dies with 403 on mismatch

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rkey = 'form:request-monthly:' . $ip;
    if (!rate_ok($rkey, 20, 3600)) { $errors[] = 'Too many submissions from your address. Please try again later.'; }

    $captcha = trim((string)($_POST['captcha'] ?? ''));
    if (!isset($_SESSION['math_a']) || $captcha === '' || (int)$captcha !== (int)$_SESSION['math_a']) {
        $errors[] = 'Please answer the math question correctly.';
    }
    mv_captcha_new();

    // Normalize to shared names for validation/storage
    if ($rtype === 'company') {
        $email = $old['c_email']; $phone = $old['c_phone'];
        $locationId = $old['c_location_id']; $startDate = $old['c_start_date'];
        $comments = $old['c_comments'];
    } else {
        $email = $old['email']; $phone = $old['phone'];
        $locationId = $old['location_id']; $startDate = $old['start_date'];
        $comments = $old['comments'];
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'A valid email address is required.'; }
    if ($phone === '') { $errors[] = 'Phone number is required.'; }
    if (!in_array($locationId, $validLocationIds, true)) { $errors[] = 'Please select a valid parking location.'; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || $startDate < today()) {
        $errors[] = 'Please choose a start date of today or later.';
    }

    $data = [];
    if ($rtype === 'individual') {
        if ($old['full_name'] === '') { $errors[] = 'Full name is required.'; }
        if ($old['vehicle_make'] === '') { $errors[] = 'Vehicle make is required.'; }
        if ($old['vehicle_model'] === '') { $errors[] = 'Vehicle model is required.'; }
        if ($old['plate'] === '') { $errors[] = 'License plate is required.'; }
        if ($old['plate_state'] === '') { $errors[] = 'Plate state is required.'; }
        if (!$termsAccepted) { $errors[] = 'You must accept the terms to submit your request.'; }
        if (!in_array($old['contact_method'], ['', 'Email', 'Phone', 'Text'], true)) { $errors[] = 'Contact method is not valid.'; }
        $data = [
            'full_name' => $old['full_name'],
            'vehicle_make' => $old['vehicle_make'],
            'vehicle_model' => $old['vehicle_model'],
            'plate' => normalize_plate($old['plate']),
            'plate_state' => strtoupper($old['plate_state']),
            'contact_method' => $old['contact_method'] !== '' ? $old['contact_method'] : null,
        ];
    } else {
        if ($old['company_name'] === '') { $errors[] = 'Company name is required.'; }
        if ($old['contact_person'] === '') { $errors[] = 'Contact person is required.'; }
        if ($old['spaces'] === '' || !ctype_digit($old['spaces']) || (int)$old['spaces'] < 1) { $errors[] = 'Number of spaces must be at least 1.'; }
        if ($old['billing_contact'] === '') { $errors[] = 'Billing contact is required.'; }
        if ($old['billing_email'] === '' || !filter_var($old['billing_email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'A valid billing email is required.'; }
        $data = [
            'company_name' => $old['company_name'],
            'contact_person' => $old['contact_person'],
            'spaces' => (int)$old['spaces'],
            'billing_contact' => $old['billing_contact'],
            'billing_email' => $old['billing_email'],
        ];
    }

    if (empty($errors)) {
        rate_hit($rkey);
        $ref = ref_no('MVP');

        $cols = ['ref_no', 'type', 'email', 'phone', 'location_id', 'start_date', 'comments', 'status', 'created_at', 'updated_at'];
        $vals = [
            ':ref_no' => $ref, ':type' => $rtype, ':email' => $email, ':phone' => $phone,
            ':location_id' => (int)$locationId, ':start_date' => $startDate,
            ':comments' => $comments !== '' ? $comments : null,
            ':status' => 'new', ':created_at' => date('Y-m-d H:i:s'), ':updated_at' => date('Y-m-d H:i:s'),
        ];
        foreach ($data as $c => $v) { $cols[] = $c; $vals[':' . $c] = $v; }

        $sql = 'INSERT INTO monthly_requests (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_keys($vals)) . ')';
        $st = db()->prepare($sql);
        $st->execute($vals);
        $reqId = (int)db()->lastInsertId();

        $locName = '';
        foreach ($locations as $l) { if ((string)$l['id'] === $locationId) { $locName = $l['name']; break; } }

        $flat = [
            'ref_no' => $ref, 'request_type' => $rtype, 'email' => $email, 'phone' => $phone,
            'location_name' => $locName, 'start_date' => $startDate, 'comments' => $comments,
            'full_name' => $rtype === 'individual' ? $old['full_name'] : $old['contact_person'],
            'company_name' => $old['company_name'], 'spaces' => $old['spaces'],
            'vehicle' => trim($old['vehicle_make'] . ' ' . $old['vehicle_model']),
            'plate' => $rtype === 'individual' ? normalize_plate($old['plate']) : '',
        ];

        $adminEmail = setting('admin_email') ?: setting('business_email');
        if (!empty($adminEmail)) { send_template('request_received', $adminEmail, $flat); }
        send_template('request_received', $email, $flat);

        audit('public.monthly_requested', 'monthly_requests', $reqId, ['ref_no' => $ref, 'type' => $rtype]);

        redirect('thank-you.php?ref=' . urlencode($ref));
    }
}

include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; <a href="monthly-parking.php">Monthly Parking</a> &rsaquo; Request</p>
    <h1>Request Monthly Parking</h1>
    <p>Choose whether you're requesting as an individual or a company, then complete the form. We'll review and respond.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="form-card">
      <?php if (!empty($errors)): ?>
        <div class="form-errors" role="alert">
          <strong>Please fix the following:</strong>
          <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <?php if (empty($locations)): ?>
        <div class="empty-state">
          <h3>Requests temporarily unavailable</h3>
          <p>No locations are currently accepting online monthly requests. Please call <a href="tel:+18554426500">(855)-442-6500</a>.</p>
        </div>
      <?php else: ?>
      <div class="toggle-tabs" role="tablist" aria-label="Request type">
        <button type="button" class="toggle-tab" data-form-toggle="individual">Individual</button>
        <button type="button" class="toggle-tab" data-form-toggle="company">Company</button>
      </div>

      <form method="post" action="request-monthly.php" data-once novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="request_type" id="requestType" value="<?= e($rtype) ?>">
        <input type="text" name="website_url" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true" value="">

        <div id="panelIndividual">
          <div class="form-grid">
            <div class="form-group">
              <label for="m_full_name">Full name <span class="req">*</span></label>
              <input type="text" id="m_full_name" name="full_name" required maxlength="150" value="<?= e($old['full_name']) ?>">
            </div>
            <div class="form-group">
              <label for="m_email">Email <span class="req">*</span></label>
              <input type="email" id="m_email" name="email" required maxlength="150" value="<?= e($old['email']) ?>">
            </div>
            <div class="form-group">
              <label for="m_phone">Phone <span class="req">*</span></label>
              <input type="tel" id="m_phone" name="phone" required maxlength="40" value="<?= e($old['phone']) ?>">
            </div>
            <div class="form-group">
              <label for="m_location_id">Parking location <span class="req">*</span></label>
              <select id="m_location_id" name="location_id" required>
                <option value="">Select&hellip;</option>
                <?php foreach ($locations as $l): ?>
                  <option value="<?= e((string)$l['id']) ?>"<?= $old['location_id'] === (string)$l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?><?= !empty($l['city']) ? ' — ' . e($l['city']) : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="m_start_date">Start date <span class="req">*</span></label>
              <input type="date" id="m_start_date" name="start_date" required min="<?= e(today()) ?>" value="<?= e($old['start_date']) ?>">
            </div>
            <div class="form-group">
              <label for="m_contact_method">Preferred contact method <span class="hint">(optional)</span></label>
              <select id="m_contact_method" name="contact_method">
                <option value="">No preference</option>
                <?php foreach (['Email','Phone','Text'] as $cm): ?>
                  <option value="<?= e($cm) ?>"<?= $old['contact_method'] === $cm ? ' selected' : '' ?>><?= e($cm) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="m_vehicle_make">Vehicle make <span class="req">*</span></label>
              <input type="text" id="m_vehicle_make" name="vehicle_make" required maxlength="60" value="<?= e($old['vehicle_make']) ?>">
            </div>
            <div class="form-group">
              <label for="m_vehicle_model">Vehicle model <span class="req">*</span></label>
              <input type="text" id="m_vehicle_model" name="vehicle_model" required maxlength="60" value="<?= e($old['vehicle_model']) ?>">
            </div>
            <div class="form-group">
              <label for="m_plate">License plate <span class="req">*</span></label>
              <input type="text" id="m_plate" name="plate" required maxlength="20" value="<?= e($old['plate']) ?>">
            </div>
            <div class="form-group">
              <label for="m_plate_state">Plate state <span class="req">*</span></label>
              <input type="text" id="m_plate_state" name="plate_state" required maxlength="10" placeholder="e.g. MI" value="<?= e($old['plate_state']) ?>">
            </div>
            <div class="form-group full">
              <label for="m_comments">Comments <span class="hint">(optional)</span></label>
              <textarea id="m_comments" name="comments"><?= e($old['comments']) ?></textarea>
            </div>
          </div>
          <div class="check-row">
            <input type="checkbox" id="m_terms" name="terms" value="1" required<?= $termsAccepted ? ' checked' : '' ?>>
            <label for="m_terms">I agree to the <a href="terms.php" target="_blank" rel="noopener">Terms of Service</a> and <a href="privacy.php" target="_blank" rel="noopener">Privacy Policy</a>, and I understand my request is subject to review and approval. <span class="req">*</span></label>
          </div>
        </div>

        <div id="panelCompany" hidden>
          <div class="form-grid">
            <div class="form-group">
              <label for="c_company_name">Company name <span class="req">*</span></label>
              <input type="text" id="c_company_name" name="company_name" required maxlength="150" value="<?= e($old['company_name']) ?>">
            </div>
            <div class="form-group">
              <label for="c_contact_person">Contact person <span class="req">*</span></label>
              <input type="text" id="c_contact_person" name="contact_person" required maxlength="150" value="<?= e($old['contact_person']) ?>">
            </div>
            <div class="form-group">
              <label for="c_email">Email <span class="req">*</span></label>
              <input type="email" id="c_email" name="c_email" required maxlength="150" value="<?= e($old['c_email']) ?>">
            </div>
            <div class="form-group">
              <label for="c_phone">Phone <span class="req">*</span></label>
              <input type="tel" id="c_phone" name="c_phone" required maxlength="40" value="<?= e($old['c_phone']) ?>">
            </div>
            <div class="form-group">
              <label for="c_location_id">Parking location <span class="req">*</span></label>
              <select id="c_location_id" name="c_location_id" required>
                <option value="">Select&hellip;</option>
                <?php foreach ($locations as $l): ?>
                  <option value="<?= e((string)$l['id']) ?>"<?= $old['c_location_id'] === (string)$l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?><?= !empty($l['city']) ? ' — ' . e($l['city']) : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="c_spaces">Number of spaces <span class="req">*</span></label>
              <input type="number" id="c_spaces" name="spaces" required min="1" max="500" value="<?= e($old['spaces']) ?>">
            </div>
            <div class="form-group">
              <label for="c_start_date">Start date <span class="req">*</span></label>
              <input type="date" id="c_start_date" name="c_start_date" required min="<?= e(today()) ?>" value="<?= e($old['c_start_date']) ?>">
            </div>
            <div class="form-group">
              <label for="c_billing_contact">Billing contact <span class="req">*</span></label>
              <input type="text" id="c_billing_contact" name="billing_contact" required maxlength="150" value="<?= e($old['billing_contact']) ?>">
            </div>
            <div class="form-group">
              <label for="c_billing_email">Billing email <span class="req">*</span></label>
              <input type="email" id="c_billing_email" name="billing_email" required maxlength="150" value="<?= e($old['billing_email']) ?>">
            </div>
            <div class="form-group full">
              <label for="c_comments">Comments <span class="hint">(optional)</span></label>
              <textarea id="c_comments" name="c_comments"><?= e($old['c_comments']) ?></textarea>
            </div>
          </div>
        </div>

        <div class="captcha-box">
          <span class="captcha-q">What is <?= e($_SESSION['math_q'] ?? '') ?>?</span>
          <label for="m_captcha" class="hint">Spam check <span class="req">*</span></label>
          <input type="text" id="m_captcha" name="captcha" required inputmode="numeric" autocomplete="off" maxlength="4">
        </div>

        <button class="btn btn-gold" type="submit" style="width:100%">Submit Monthly Request</button>
        <p class="hint" style="margin-top:1rem;text-align:center">Prefer to talk? Call <a href="tel:+18554426500">(855)-442-6500</a>.</p>
      </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
