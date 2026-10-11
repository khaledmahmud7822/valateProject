<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Request a Quote';
$page_desc = 'Request valet or parking management services for your hotel, restaurant, commercial building, or event. Metro Valet Parking responds with a tailored proposal.';
$page_key = 'contact';

/* ---- local anti-spam helpers ---- */
function mv_captcha_new() {
    $a = random_int(3, 9); $b = random_int(2, 9);
    $_SESSION['math_q'] = $a . ' + ' . $b;
    $_SESSION['math_a'] = $a + $b;
}
if (!isset($_SESSION['math_a'])) { mv_captcha_new(); }

$service_options = [
    'Hotel Valet', 'Commercial Building Valet', 'Restaurant Valet',
    'Parking Lot Management', 'Parking Garage Management', 'Event Valet', 'Corporate Parking',
];
$business_types = [
    'Hotel / Hospitality', 'Commercial Office', 'Restaurant / Dining', 'Retail',
    'Healthcare', 'Residential', 'Event Venue', 'Other',
];

$errors = [];
$old = [
    'business_name' => '', 'contact_person' => '', 'email' => '', 'phone' => '',
    'business_type' => '', 'property_address' => '', 'service_type' => '',
    'volume' => '', 'start_date' => '', 'requirements' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot: silently "succeed" for bots
    if (!empty($_POST['website_url'])) { redirect('thank-you.php'); }

    foreach (array_keys($old) as $k) {
        $old[$k] = trim((string)($_POST[$k] ?? ''));
    }

    verify_csrf(); // dies with 403 on mismatch

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rkey = 'form:request-quote:' . $ip;
    if (!rate_ok($rkey, 20, 3600)) { $errors[] = 'Too many submissions from your address. Please try again later.'; }

    $captcha = trim((string)($_POST['captcha'] ?? ''));
    if (!isset($_SESSION['math_a']) || $captcha === '' || (int)$captcha !== (int)$_SESSION['math_a']) {
        $errors[] = 'Please answer the math question correctly.';
    }
    mv_captcha_new(); // rotate regardless

    if ($old['business_name'] === '') { $errors[] = 'Business name is required.'; }
    if ($old['contact_person'] === '') { $errors[] = 'Contact person is required.'; }
    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'A valid email address is required.'; }
    if ($old['phone'] === '') { $errors[] = 'Phone number is required.'; }
    if (!in_array($old['business_type'], $business_types, true)) { $errors[] = 'Please select a business type.'; }
    if ($old['property_address'] === '') { $errors[] = 'Property address is required.'; }
    if (!in_array($old['service_type'], $service_options, true)) { $errors[] = 'Please select a service type.'; }
    if ($old['start_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['start_date'])) { $errors[] = 'Start date is not valid.'; }

    if (empty($errors)) {
        rate_hit($rkey);
        $ref = ref_no('MVP');
        $st = db()->prepare(
            "INSERT INTO valet_leads (ref_no, business_name, contact_person, email, phone, business_type, property_address, service_type, volume, start_date, requirements, stage, created_at, updated_at)
             VALUES (:ref_no, :business_name, :contact_person, :email, :phone, :business_type, :property_address, :service_type, :volume, :start_date, :requirements, 'new', :created_at, :created_at)"
        );
        $st->execute([
            ':ref_no' => $ref,
            ':business_name' => $old['business_name'],
            ':contact_person' => $old['contact_person'],
            ':email' => $old['email'],
            ':phone' => $old['phone'],
            ':business_type' => $old['business_type'],
            ':property_address' => $old['property_address'],
            ':service_type' => $old['service_type'],
            ':volume' => $old['volume'] !== '' ? $old['volume'] : null,
            ':start_date' => $old['start_date'] !== '' ? $old['start_date'] : null,
            ':requirements' => $old['requirements'] !== '' ? $old['requirements'] : null,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
        $leadId = (int)db()->lastInsertId();

        $vars = [
            'ref_no' => $ref,
            'business_name' => $old['business_name'],
            'contact_person' => $old['contact_person'],
            'email' => $old['email'],
            'phone' => $old['phone'],
            'business_type' => $old['business_type'],
            'property_address' => $old['property_address'],
            'service_type' => $old['service_type'],
            'volume' => $old['volume'],
            'start_date' => $old['start_date'],
            'requirements' => $old['requirements'],
        ];
        $adminEmail = setting('admin_email') ?: setting('business_email');
        if (!empty($adminEmail)) { send_template('lead_received', $adminEmail, $vars); }
        send_template('lead_received', $old['email'], $vars);

        audit('public.quote_requested', 'valet_leads', $leadId, ['ref_no' => $ref, 'service_type' => $old['service_type']]);

        redirect('thank-you.php?ref=' . urlencode($ref));
    }
}

include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; Request a Quote</p>
    <h1>Request Valet Services</h1>
    <p>Tell us about your property or event and we'll respond with a tailored proposal. No obligation.</p>
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

      <form method="post" action="request-quote.php" data-once novalidate>
        <?= csrf_field() ?>
        <input type="text" name="website_url" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true" value="">

        <div class="form-grid">
          <div class="form-group">
            <label for="q_business_name">Business name <span class="req">*</span></label>
            <input type="text" id="q_business_name" name="business_name" required maxlength="150" value="<?= e($old['business_name']) ?>">
          </div>
          <div class="form-group">
            <label for="q_contact_person">Contact person <span class="req">*</span></label>
            <input type="text" id="q_contact_person" name="contact_person" required maxlength="150" value="<?= e($old['contact_person']) ?>">
          </div>
          <div class="form-group">
            <label for="q_email">Email <span class="req">*</span></label>
            <input type="email" id="q_email" name="email" required maxlength="150" value="<?= e($old['email']) ?>">
          </div>
          <div class="form-group">
            <label for="q_phone">Phone <span class="req">*</span></label>
            <input type="tel" id="q_phone" name="phone" required maxlength="40" value="<?= e($old['phone']) ?>">
          </div>
          <div class="form-group">
            <label for="q_business_type">Business type <span class="req">*</span></label>
            <select id="q_business_type" name="business_type" required>
              <option value="">Select&hellip;</option>
              <?php foreach ($business_types as $bt): ?>
                <option value="<?= e($bt) ?>"<?= $old['business_type'] === $bt ? ' selected' : '' ?>><?= e($bt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="q_service_type">Service needed <span class="req">*</span></label>
            <select id="q_service_type" name="service_type" required>
              <option value="">Select&hellip;</option>
              <?php foreach ($service_options as $so): ?>
                <option value="<?= e($so) ?>"<?= $old['service_type'] === $so ? ' selected' : '' ?>><?= e($so) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full">
            <label for="q_property_address">Property address <span class="req">*</span></label>
            <input type="text" id="q_property_address" name="property_address" required maxlength="255" value="<?= e($old['property_address']) ?>">
          </div>
          <div class="form-group">
            <label for="q_volume">Expected volume <span class="hint">(optional)</span></label>
            <input type="text" id="q_volume" name="volume" maxlength="100" placeholder="e.g. 150 cars/night, 40 rooms" value="<?= e($old['volume']) ?>">
          </div>
          <div class="form-group">
            <label for="q_start_date">Desired start date <span class="hint">(optional)</span></label>
            <input type="date" id="q_start_date" name="start_date" value="<?= e($old['start_date']) ?>">
          </div>
          <div class="form-group full">
            <label for="q_requirements">Requirements / details <span class="hint">(optional)</span></label>
            <textarea id="q_requirements" name="requirements" placeholder="Tell us about your operation, hours, peak times, or anything else we should know."><?= e($old['requirements']) ?></textarea>
          </div>
        </div>

        <div class="captcha-box">
          <span class="captcha-q">What is <?= e($_SESSION['math_q'] ?? '') ?>?</span>
          <label for="q_captcha" class="hint">Spam check <span class="req">*</span></label>
          <input type="text" id="q_captcha" name="captcha" required inputmode="numeric" autocomplete="off" maxlength="4">
        </div>

        <button class="btn btn-gold" type="submit" style="width:100%">Submit Quote Request</button>
        <p class="hint" style="margin-top:1rem;text-align:center">Prefer to talk? Call <a href="tel:+18554426500">(855)-442-6500</a>.</p>
      </form>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
