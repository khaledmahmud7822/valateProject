<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Contact Us';
$page_desc = 'Contact Metro Valet Parking — send us a message or call (855)-442-6500. Detroit-based parking management company.';
$page_key = 'contact';

function mv_captcha_new() {
    $a = random_int(3, 9); $b = random_int(2, 9);
    $_SESSION['math_q'] = $a . ' + ' . $b;
    $_SESSION['math_a'] = $a + $b;
}
if (!isset($_SESSION['math_a'])) { mv_captcha_new(); }

$errors = [];
$old = ['name' => '', 'email' => '', 'phone' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['website_url'])) { redirect('thank-you.php'); }

    foreach (array_keys($old) as $k) {
        $old[$k] = trim((string)($_POST[$k] ?? ''));
    }

    verify_csrf(); // dies with 403 on mismatch

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rkey = 'form:contact:' . $ip;
    if (!rate_ok($rkey, 20, 3600)) { $errors[] = 'Too many submissions from your address. Please try again later.'; }

    $captcha = trim((string)($_POST['captcha'] ?? ''));
    if (!isset($_SESSION['math_a']) || $captcha === '' || (int)$captcha !== (int)$_SESSION['math_a']) {
        $errors[] = 'Please answer the math question correctly.';
    }
    mv_captcha_new();

    if ($old['name'] === '') { $errors[] = 'Your name is required.'; }
    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'A valid email address is required.'; }
    if ($old['message'] === '') { $errors[] = 'Please enter your message.'; }

    if (empty($errors)) {
        rate_hit($rkey);
        $ref = ref_no('MVP');
        $st = db()->prepare(
            "INSERT INTO valet_leads (ref_no, contact_person, email, phone, service_type, requirements, stage, created_at, updated_at)
             VALUES (:ref_no, :contact_person, :email, :phone, 'general_inquiry', :requirements, 'new', :created_at, :created_at)"
        );
        $st->execute([
            ':ref_no' => $ref,
            ':contact_person' => $old['name'],
            ':email' => $old['email'],
            ':phone' => $old['phone'] !== '' ? $old['phone'] : null,
            ':requirements' => $old['message'],
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
        $leadId = (int)db()->lastInsertId();

        $vars = [
            'ref_no' => $ref,
            'contact_person' => $old['name'],
            'name' => $old['name'],
            'email' => $old['email'],
            'phone' => $old['phone'],
            'message' => $old['message'],
            'service_type' => 'general_inquiry',
        ];
        $adminEmail = setting('admin_email') ?: setting('business_email');
        if (!empty($adminEmail)) { send_template('lead_received', $adminEmail, $vars); }

        audit('public.contact_submitted', 'valet_leads', $leadId, ['ref_no' => $ref]);

        redirect('thank-you.php?ref=' . urlencode($ref));
    }
}

include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; Contact</p>
    <h1>Contact Us</h1>
    <p>Questions about a location, monthly parking, or our services? Send a message — our Detroit team will respond.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="grid grid-2" style="align-items:start;max-width:1000px;margin:0 auto">
      <div>
        <h2>Get in Touch</h2>
        <hr class="gold-rule">
        <table class="info-table">
          <tr><th>Phone</th><td><a href="tel:+18554426500" style="font-weight:700">(855)-442-6500</a></td></tr>
          <tr><th>Location</th><td>Detroit, Michigan</td></tr>
          <tr><th>Contact</th><td>Jennifer Van Pelt</td></tr>
        </table>
        <p style="margin-top:1.2rem">For valet or parking management proposals, please use our <a href="request-quote.php" style="font-weight:700">quote request form</a>. For monthly parking, use the <a href="request-monthly.php" style="font-weight:700">monthly request form</a>.</p>
      </div>
      <div class="form-card" style="margin:0">
        <?php if (!empty($errors)): ?>
          <div class="form-errors" role="alert">
            <strong>Please fix the following:</strong>
            <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>
        <form method="post" action="contact.php" data-once novalidate>
          <?= csrf_field() ?>
          <input type="text" name="website_url" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true" value="">
          <div class="form-group">
            <label for="c_name">Your name <span class="req">*</span></label>
            <input type="text" id="c_name" name="name" required maxlength="150" value="<?= e($old['name']) ?>">
          </div>
          <div class="form-group">
            <label for="c_email">Email <span class="req">*</span></label>
            <input type="email" id="c_email" name="email" required maxlength="150" value="<?= e($old['email']) ?>">
          </div>
          <div class="form-group">
            <label for="c_phone">Phone <span class="hint">(optional)</span></label>
            <input type="tel" id="c_phone" name="phone" maxlength="40" value="<?= e($old['phone']) ?>">
          </div>
          <div class="form-group">
            <label for="c_message">Message <span class="req">*</span></label>
            <textarea id="c_message" name="message" required><?= e($old['message']) ?></textarea>
          </div>
          <div class="captcha-box">
            <span class="captcha-q">What is <?= e($_SESSION['math_q'] ?? '') ?>?</span>
            <label for="c_captcha" class="hint">Spam check <span class="req">*</span></label>
            <input type="text" id="c_captcha" name="captcha" required inputmode="numeric" autocomplete="off" maxlength="4">
          </div>
          <button class="btn btn-gold" type="submit" style="width:100%">Send Message</button>
        </form>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
