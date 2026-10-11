<?php
require __DIR__ . '/../lib/bootstrap.php';

$auth     = require_customer();
$scope    = $_SESSION['customer_scope'] ?? 'customer';
$scope_id = (int)($_SESSION['customer_scope_id'] ?? 0);
$login_id = (int)($_SESSION['customer_id'] ?? 0);
$customer = $auth;
$pdo      = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); /* exits 403 on mismatch */
    $subject = trim((string)($_POST['subject'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $errs = array();
    if (strlen($subject) < 4) { $errs[] = 'Please give your message a short subject.'; }
    if (strlen($message) < 10) { $errs[] = 'Please describe your issue in a little more detail.'; }
    if (strlen($message) > 5000) { $errs[] = 'Your message is too long (5,000 character limit).'; }

    if (!empty($errs)) {
        flash(implode(' ', $errs), 'error');
        redirect(site_url('portal/support.php'));
    }

    $admin_email = (string)(setting('admin_email') ?: setting('business_email'));
    $who         = (string)($customer['name'] ?? 'Customer');
    $full_subj   = 'Portal support — ' . $who . ': ' . $subject;
    $body  = '<p><strong>From:</strong> ' . e($who) . ' &lt;' . e((string)($customer['email'] ?? '')) . '&gt;</p>';
    $body .= '<p><strong>Subject:</strong> ' . e($subject) . '</p>';
    $body .= '<p>' . nl2br(e($message)) . '</p>';

    $sent = false;
    if ($admin_email !== '') {
        $sent = (bool)send_mail_raw($admin_email, $full_subj, $body);
    }
    try {
        $ins = $pdo->prepare(
            "INSERT INTO notification_logs (template_key, recipient, subject, body_hash, status, sent_at, dedupe_key)
             VALUES ('support_message', :r, :s, :h, :st, :t, :d)"
        );
        $ins->execute(array(
            ':r'  => $admin_email,
            ':s'  => $full_subj,
            ':h'  => sha1($body),
            ':st' => $sent ? 'sent' : 'failed',
            ':t'  => date('Y-m-d H:i:s'),
            ':d'  => 'support_message:' . $login_id . ':' . date('YmdHis'),
        ));
    } catch (Throwable $e) { /* logging must not block the confirmation */ }
    audit('support_message', 'customers', $login_id, array('subject' => $subject));
    flash(
        $sent
            ? 'Message sent. Our team will get back to you shortly.'
            : 'Message recorded. Our team will get back to you shortly.',
        $sent ? 'success' : 'info'
    );
    redirect(site_url('portal/support.php'));
}

$biz_phone = (string)setting('business_phone', '(855)-442-6500');
$biz_email = (string)setting('business_email', '');

$page_title = 'Support';
$active     = 'support';
include __DIR__ . '/_layout.php';
?>

<h1>Support</h1>
<p class="p-lead">Questions about your subscription, an invoice, or your vehicle? We&rsquo;re here to help.</p>

<div class="p-card">
  <h2>Contact us directly</h2>
  <dl class="p-kv">
    <dt>Phone</dt><dd><a href="tel:+18554426500"><?= e($biz_phone) ?></a></dd>
    <?php if ($biz_email !== ''): ?>
      <dt>Email</dt><dd><a href="mailto:<?= e($biz_email) ?>"><?= e($biz_email) ?></a></dd>
    <?php endif; ?>
  </dl>
</div>

<div class="p-card">
  <h2>Send us a message</h2>
  <form method="post" action="<?= e(site_url('portal/support.php')) ?>">
    <?php $cf = csrf_field(); if (is_string($cf)) { echo $cf; } ?>
    <div class="p-field">
      <label for="subject">Subject</label>
      <input type="text" id="subject" name="subject" required maxlength="190" placeholder="e.g. Question about my October invoice">
    </div>
    <div class="p-field">
      <label for="message">Message</label>
      <textarea id="message" name="message" rows="6" required maxlength="5000"
                placeholder="Tell us what you need help with&hellip;"></textarea>
    </div>
    <button class="btn btn-navy" type="submit">Send message</button>
  </form>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
