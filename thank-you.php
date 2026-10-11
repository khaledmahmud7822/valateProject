<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Thank You';
$page_desc = 'Thank you for contacting Metro Valet Parking. Your reference number and next steps.';
$page_key = '';

$ref = trim($_GET['ref'] ?? '');
$showRef = preg_match('/^[A-Za-z0-9\-]{3,40}$/', $ref) ? $ref : '';

include __DIR__.'/includes/site_header.php';
?>

<section class="section">
  <div class="container">
    <div class="thankyou-wrap reveal">
      <span class="eyebrow">Received</span>
      <h1>Thank You</h1>
      <hr class="gold-rule" style="margin-left:auto;margin-right:auto">
      <p class="lead" style="margin:0 auto">We've received your submission. Our Detroit team will review it and get back to you — usually within one business day.</p>
      <?php if ($showRef !== ''): ?>
        <p style="margin-bottom:0">Your reference number:</p>
        <div class="ref-box"><?= e($showRef) ?></div>
        <p class="hint">Please keep this reference number for your records. If you contact us about this request, mentioning it helps us find it quickly.</p>
      <?php endif; ?>
      <h2 style="margin-top:2.5rem">What happens next</h2>
      <div class="steps" style="grid-template-columns:1fr;text-align:left;max-width:520px;margin:1.5rem auto 0">
        <div class="step">
          <h3>1. Review</h3>
          <p>A member of our team reviews your request and checks availability or requirements.</p>
        </div>
        <div class="step">
          <h3>2. Response</h3>
          <p>We contact you by email or phone with next steps, availability, or a proposal.</p>
        </div>
        <div class="step">
          <h3>3. Confirmation</h3>
          <p>For monthly parking: approval and payment activate your subscription. For services: we finalize the agreement and schedule.</p>
        </div>
      </div>
      <div class="hero-ctas" style="justify-content:center;margin-top:2.5rem">
        <a class="btn btn-navy" href="index.php">Back to Home</a>
        <a class="btn btn-ghost" href="tel:+18554426500">Call (855)-442-6500</a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
