<?php
// admin/settings.php — business, mail, Stripe, reminders, and security settings.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Settings';
$nav_section = 'settings';
$required_perm = 'settings.edit';
require __DIR__ . '/_guard.php';

$user = current_user();

$fields = [
    'business_name' => ['label' => 'Business name', 'type' => 'text', 'default' => 'Metro Valet Parking'],
    'business_phone' => ['label' => 'Business phone', 'type' => 'tel', 'default' => ''],
    'business_email' => ['label' => 'Business email', 'type' => 'email', 'default' => ''],
    'admin_email' => ['label' => 'Admin notification email', 'type' => 'email', 'default' => '', 'hint' => 'Receives form submissions and system alerts.'],
    'tagline' => ['label' => 'Tagline', 'type' => 'text', 'default' => ''],
    'mail_from' => ['label' => 'Mail "from" address', 'type' => 'email', 'default' => '', 'hint' => 'Used as the sender for customer emails.'],
    'smtp_host' => ['label' => 'SMTP host', 'type' => 'text', 'default' => '', 'hint' => 'e.g. smtp.hostinger.com — leave empty to use PHP mail().'],
    'smtp_port' => ['label' => 'SMTP port', 'type' => 'number', 'default' => '587', 'hint' => '587 for TLS, 465 for SSL.'],
    'smtp_secure' => ['label' => 'SMTP encryption', 'type' => 'select', 'options' => ['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'None'], 'default' => 'tls'],
    'smtp_user' => ['label' => 'SMTP username', 'type' => 'text', 'default' => '', 'hint' => 'Usually your full email address.'],
    'smtp_pass' => ['label' => 'SMTP password', 'type' => 'password', 'default' => '', 'secret' => true, 'hint' => 'Leave blank to keep the current value.'],
    'stripe_publishable_key' => ['label' => 'Stripe publishable key', 'type' => 'text', 'default' => '', 'hint' => 'Empty = Stripe checkout disabled; the pay page shows manual payment instructions.'],
    'stripe_secret_key' => ['label' => 'Stripe secret key', 'type' => 'password', 'default' => '', 'secret' => true, 'hint' => 'Leave blank to keep the current value.'],
    'stripe_webhook_secret' => ['label' => 'Stripe webhook signing secret', 'type' => 'password', 'default' => '', 'secret' => true, 'hint' => 'From the webhook endpoint in the Stripe Dashboard (whsec_…). Required for paid invoices to flip automatically.'],
    'backup_passphrase' => ['label' => 'Backup passphrase', 'type' => 'password', 'default' => '', 'secret' => true, 'hint' => 'Required. Encrypts every backup (AES-256). Save it somewhere safe — you need it to restore. Changing it does not re-encrypt older backups.'],
    'backup_email' => ['label' => 'Backup email', 'type' => 'email', 'default' => '', 'hint' => 'Receives the weekly backup (or download link) and failure alerts. Blank = first super admin.'],
    'backup_attach_max_mb' => ['label' => 'Email attachment limit (MB)', 'type' => 'number', 'default' => '12', 'hint' => 'Backups larger than this are sent as a 72-hour download link instead.'],
    'backup_keep' => ['label' => 'Weekly backups to keep', 'type' => 'number', 'default' => '12', 'hint' => 'Older backups are auto-deleted. Set to 1 to keep only the latest.'],
    'hiring_email' => ['label' => 'Hiring team email', 'type' => 'email', 'default' => '', 'hint' => 'New job applications are sent here. Falls back to the admin email if empty.'], 
    'backup_cloud_provider' => ['label' => 'Cloud provider', 'type' => 'select', 'options' => ['none' => 'None (local + email only)', 's3' => 'Amazon S3', 'r2' => 'Cloudflare R2', 'other' => 'Other S3-compatible'], 'default' => 'none'],
    'backup_cloud_endpoint' => ['label' => 'Endpoint URL', 'type' => 'text', 'default' => '', 'hint' => 'R2: https://&lt;accountid&gt;.r2.cloudflarestorage.com — S3: leave blank for auto.'],
    'backup_cloud_region' => ['label' => 'Region', 'type' => 'text', 'default' => 'auto', 'hint' => 'S3: e.g. us-east-1 — R2: auto.'],
    'backup_cloud_bucket' => ['label' => 'Bucket', 'type' => 'text', 'default' => ''],
    'backup_cloud_prefix' => ['label' => 'Key prefix', 'type' => 'text', 'default' => 'metrovalet'],
    'backup_cloud_key' => ['label' => 'Access key ID', 'type' => 'text', 'default' => ''],
    'backup_cloud_secret' => ['label' => 'Secret access key', 'type' => 'password', 'default' => '', 'secret' => true, 'hint' => 'Leave blank to keep the current value.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = (string) ($_POST['op'] ?? 'save');

    if ($op === 'regenerate_cron') {
        $new = secure_token(32);
        set_setting('cron_token', $new);
        audit('setting.cron_token_regenerated', 'setting', null);
        flash('Cron token regenerated. Update your scheduled task with the new URL below.', 'success');
        redirect(site_url('admin/settings.php'));
    }

    if ($op === 'test_stripe') {
        $secret = (string) setting('stripe_secret_key', '');
        if ($secret === '') {
            flash('No Stripe secret key saved — paste one first, then test.', 'error');
        } else {
            [$code, $data, $cerr] = mv_stripe_get($secret, '/balance');
            if ($cerr !== '') {
                flash('Could not reach Stripe from this server (' . $cerr . '). Card checkout will not work until the server can reach api.stripe.com.', 'error');
            } elseif ($code === 200) {
                $mode = str_starts_with($secret, 'sk_live_') ? 'LIVE' : (str_starts_with($secret, 'sk_test_') ? 'TEST' : 'unknown');
                audit('setting.stripe_tested', 'setting', null, ['mode' => $mode, 'result' => 'ok']);
                flash('Stripe connection works — ' . $mode . ' mode key accepted. Card checkout should work.', 'success');
            } elseif ($code === 401) {
                $msg = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : 'Invalid API key.';
                audit('setting.stripe_tested', 'setting', null, ['result' => 'invalid_key']);
                flash('Stripe rejected the key: ' . $msg . ' Check that you pasted the SECRET key (starts with sk_), not the publishable key, with no extra spaces.', 'error');
            } else {
                $msg = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : ('HTTP ' . $code);
                flash('Stripe test failed: ' . $msg, 'error');
            }
        }
        redirect(site_url('admin/settings.php'));
    }

    if ($op === 'test_email') {
        $to = trim((string) ($_POST['test_email_to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid email address for the test.', 'error');
        } else {
            $via = trim((string) setting('smtp_host', '')) !== '' ? 'SMTP' : 'PHP mail()';
            $ok = send_mail_raw($to, 'Test email from ' . setting('business_name', 'Metro Valet Parking'), '<p>This is a test email. If you received it, your email settings work.</p>');
            if ($ok) {
                audit('setting.email_tested', 'setting', null, ['via' => $via, 'to' => $to]);
                flash('Test email sent via ' . $via . ' to ' . $to . '. Check the inbox (and spam folder).', 'success');
            } else {
                audit('setting.email_tested', 'setting', null, ['via' => $via, 'to' => $to, 'result' => 'failed']);
                flash('Test email failed via ' . $via . '. Check Admin → Notifications for the error details.', 'error');
            }
        }
        redirect(site_url('admin/settings.php'));
    }

    if ($op === 'save' || $op === 'save_payments') {
        // Scoped save: each form only touches its own fields. The payments form has
        // no business/SMTP inputs, so running the full save from it would blank every
        // field it does not contain (and the business form would disable reminders,
        // whose checkbox lives on the payments form).
        $form1_keys = ['business_name','business_phone','business_email','admin_email','tagline','mail_from',
            'smtp_host','smtp_port','smtp_secure','smtp_user','smtp_pass',
            'backup_passphrase','backup_email','backup_attach_max_mb','backup_keep','hiring_email',
            'backup_cloud_provider','backup_cloud_endpoint','backup_cloud_region','backup_cloud_bucket',
            'backup_cloud_prefix','backup_cloud_key','backup_cloud_secret'];
        $form2_keys = ['stripe_publishable_key','stripe_secret_key','stripe_webhook_secret'];
        $keys = $op === 'save_payments' ? $form2_keys : $form1_keys;

        $changed = [];
        foreach ($keys as $key) {
            $meta = $fields[$key];
            $val = trim((string) ($_POST[$key] ?? ''));
            if (!empty($meta['secret']) && $val === '') {
                continue; // keep existing secret
            }
            if (in_array($meta['type'], ['email'], true) && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                flash($meta['label'] . ' is not a valid email.', 'error');
                redirect(site_url('admin/settings.php'));
            }
            $old = setting($key, $meta['default']);
            if ($val !== (string) $old) {
                set_setting($key, $val);
                $changed[] = $key;
            }
        }
        if ($op === 'save_payments') {
            // The toggles are rendered on the payments form — only process them there.
            foreach (['reminders_enabled' => 'Renewal reminders', 'mfa_enforced' => 'TOTP enforcement'] as $key => $lbl) {
                $val = isset($_POST[$key]) ? '1' : '0';
                $default = $key === 'reminders_enabled' ? '1' : '0';
                if ($val !== (string) setting($key, $default)) {
                    set_setting($key, $val);
                    $changed[] = $key;
                }
            }
        }
        if ($changed !== []) {
            audit('setting.updated', 'setting', null, ['keys' => $changed]);
            flash('Settings saved: ' . implode(', ', $changed), 'success');
        } else {
            flash('No changes to save.', 'info');
        }
        redirect(site_url('admin/settings.php'));
    }
}

$values = [];
foreach ($fields as $key => $meta) {
    $values[$key] = setting($key, $meta['default']);
}
$cron_token = setting('cron_token', '');
$cron_url = $cron_token !== '' ? site_url('cron/reminders.php') . '?token=' . $cron_token : '';
$reminders_on = setting('reminders_enabled', '1') === '1';
$mfa_enforced = setting('mfa_enforced', '0') === '1';

require __DIR__ . '/_layout.php';
?>

<div class="page-head"><h1>Settings</h1></div>

<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="save">
    <h3>Business</h3>
    <div class="form-grid">
        <?php foreach (['business_name', 'business_phone', 'business_email', 'admin_email', 'tagline', 'mail_from'] as $key): ?>
            <?php $meta = $fields[$key]; ?>
            <div class="field">
                <label for="<?= $key ?>"><?= e($meta['label']) ?></label>
                <input type="<?= e($meta['type']) ?>" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($values[$key]) ?>">
                <?php if (!empty($meta['hint'])): ?><div class="hint"><?= e($meta['hint']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <hr class="hr-gold">
    <h3>Email — SMTP</h3>
    <p class="muted small">When set, all emails send through your SMTP server instead of PHP mail(). For Hostinger: host <code>smtp.hostinger.com</code>, port <code>587</code>, TLS.</p>
    <div class="form-grid">
        <?php foreach (['smtp_host', 'smtp_port', 'smtp_user'] as $key): ?>
            <?php $meta = $fields[$key]; ?>
            <div class="field">
                <label for="<?= $key ?>"><?= e($meta['label']) ?></label>
                <input type="<?= e($meta['type']) ?>" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($values[$key]) ?>">
                <?php if (!empty($meta['hint'])): ?><div class="hint"><?= e($meta['hint']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="field">
            <label for="smtp_secure"><?= e($fields['smtp_secure']['label']) ?></label>
            <select id="smtp_secure" name="smtp_secure">
                <?php foreach ($fields['smtp_secure']['options'] as $ov => $ol): ?>
                    <option value="<?= e($ov) ?>"<?= ($values['smtp_secure'] ?? 'tls') === $ov ? ' selected' : '' ?>><?= e($ol) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="smtp_pass"><?= e($fields['smtp_pass']['label']) ?></label>
            <input type="password" id="smtp_pass" name="smtp_pass" value="" placeholder="<?= $values['smtp_pass'] !== '' ? '•••••••• (set — blank keeps it)' : 'Not set' ?>" autocomplete="new-password">
            <?php if (!empty($fields['smtp_pass']['hint'])): ?><div class="hint"><?= e($fields['smtp_pass']['hint']) ?></div><?php endif; ?>
        </div>
    </div>
    <hr class="hr-gold">
    <h3>Backups</h3>
    <p><a class="btn btn-outline" href="<?= e(site_url('admin/backup.php')) ?>">Open Backup &amp; Restore</a></p>
    <div class="form-grid">
        <div class="field">
            <label for="backup_passphrase"><?= e($fields['backup_passphrase']['label']) ?></label>
            <input type="password" id="backup_passphrase" name="backup_passphrase" value="" placeholder="<?= $values['backup_passphrase'] !== '' ? '•••••••• (set — blank keeps it)' : 'Not set — backups disabled until set' ?>" autocomplete="new-password">
            <?php if (!empty($fields['backup_passphrase']['hint'])): ?><div class="hint"><?= e($fields['backup_passphrase']['hint']) ?></div><?php endif; ?>
        </div>
        <?php foreach (['backup_email', 'backup_attach_max_mb', 'backup_keep', 'hiring_email'] as $key): ?>
            <?php $meta = $fields[$key]; ?>
            <div class="field">
                <label for="<?= $key ?>"><?= e($meta['label']) ?></label>
                <input type="<?= e($meta['type']) ?>" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($values[$key]) ?>">
                <?php if (!empty($meta['hint'])): ?><div class="hint"><?= $meta['hint'] ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <h4 style="margin-top:14px">Cloud storage (S3 / R2)</h4>
    <p class="muted small">Optional. Encrypted backups are also uploaded here each week. Buckets must be private. For R2, create an API token with Object Read &amp; Write.</p>
    <div class="form-grid">
        <div class="field">
            <label for="backup_cloud_provider"><?= e($fields['backup_cloud_provider']['label']) ?></label>
            <select id="backup_cloud_provider" name="backup_cloud_provider">
                <?php foreach ($fields['backup_cloud_provider']['options'] as $ov => $ol): ?>
                    <option value="<?= e($ov) ?>"<?= ($values['backup_cloud_provider'] ?? 'none') === $ov ? ' selected' : '' ?>><?= e($ol) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach (['backup_cloud_endpoint', 'backup_cloud_region', 'backup_cloud_bucket', 'backup_cloud_prefix', 'backup_cloud_key'] as $key): ?>
            <?php $meta = $fields[$key]; ?>
            <div class="field">
                <label for="<?= $key ?>"><?= e($meta['label']) ?></label>
                <input type="<?= e($meta['type']) ?>" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($values[$key]) ?>">
                <?php if (!empty($meta['hint'])): ?><div class="hint"><?= $meta['hint'] ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="field">
            <label for="backup_cloud_secret"><?= e($fields['backup_cloud_secret']['label']) ?></label>
            <input type="password" id="backup_cloud_secret" name="backup_cloud_secret" value="" placeholder="<?= $values['backup_cloud_secret'] !== '' ? '•••••••• (set — blank keeps it)' : 'Not set' ?>" autocomplete="new-password">
            <?php if (!empty($fields['backup_cloud_secret']['hint'])): ?><div class="hint"><?= e($fields['backup_cloud_secret']['hint']) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="btn-row" style="margin-top:10px">
        <button type="submit" class="btn btn-primary">Save business &amp; email settings</button>
    </div>
    </form>
    <form method="post" style="margin-top:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="test_email">
        <div class="field" style="max-width:320px">
            <label for="test_email_to">Send a test email to</label>
            <input type="email" id="test_email_to" name="test_email_to" value="<?= e(setting('admin_email', '')) ?>" placeholder="you@example.com">
        </div>
        <button type="submit" class="btn btn-outline">Send test email</button>
        <span class="hint">Uses <?= trim((string) setting('smtp_host', '')) !== '' ? 'SMTP (' . e(setting('smtp_host', '')) . ')' : 'PHP mail()' ?>.</span>
    </form>
    <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="save_payments">
    <hr class="hr-gold">
    <h3>Payments — Stripe</h3>
    <?php
    $stripe_ready = setting('stripe_publishable_key', '') !== '' && setting('stripe_secret_key', '') !== '';
    $wh_ready = setting('stripe_webhook_secret', '') !== '';
    ?>
    <p>
        <?php if ($stripe_ready && $wh_ready): ?>
            <span class="badge badge-green"><?= status_dot('green', 'Card payments fully enabled') ?></span>
        <?php elseif ($stripe_ready): ?>
            <span class="badge badge-amber"><?= status_dot('amber', 'Card checkout on — webhook secret missing, invoices will not auto-mark paid') ?></span>
        <?php else: ?>
            <span class="badge badge-gray"><?= status_dot('gray', 'Card payments off — customers see manual payment instructions') ?></span>
        <?php endif; ?>
    </p>
    <div class="form-grid">
        <div class="field">
            <label for="stripe_publishable_key">Stripe publishable key</label>
            <input type="text" id="stripe_publishable_key" name="stripe_publishable_key" value="<?= e($values['stripe_publishable_key']) ?>" autocomplete="off">
            <div class="hint">Empty = Stripe checkout disabled; the pay page shows manual payment instructions.</div>
        </div>
        <div class="field">
            <label for="stripe_secret_key">Stripe secret key</label>
            <input type="password" id="stripe_secret_key" name="stripe_secret_key" value="" placeholder="<?= $values['stripe_secret_key'] !== '' ? '•••••••• (set — blank keeps it)' : 'Not set' ?>" autocomplete="new-password">
            <div class="hint">Leave blank to keep the current value.</div>
        </div>
        <div class="field">
            <label for="stripe_webhook_secret">Stripe webhook signing secret</label>
            <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret" value="" placeholder="<?= $values['stripe_webhook_secret'] !== '' ? '•••••••• (set — blank keeps it)' : 'Not set' ?>" autocomplete="new-password">
            <div class="hint">From your webhook endpoint in the Stripe Dashboard (starts with <span class="mono">whsec_</span>). Paste it once — blank keeps the saved value.</div>
        </div>
    </div>
    <p class="muted small">Webhook endpoint to register in Stripe: <code class="mono"><?= e(site_url('pay/stripe_webhook.php')) ?></code> — listen for <code class="mono">checkout.session.completed</code>.</p>

    <hr class="hr-gold">
    <h3>Reminders</h3>
    <div class="field">
        <label class="check-line"><input type="checkbox" name="reminders_enabled" value="1"<?= $reminders_on ? ' checked' : '' ?>> Renewal reminders (7-day, 3-day, and expiry notices)</label>
        <div class="hint">Master switch honored by the daily cron and the manual "Run reminders now" sweep.</div>
    </div>

    <hr class="hr-gold">
    <h3>Security</h3>
    <div class="field">
        <label class="check-line"><input type="checkbox" name="mfa_enforced" value="1"<?= $mfa_enforced ? ' checked' : '' ?>> Require authenticator (TOTP) for all staff</label>
        <div class="hint">When on, every staff account must enroll an authenticator app before signing in.</div>
    </div>

    <div class="btn-row">
        <button type="submit" class="btn btn-primary">Save payment settings</button>
    </div>
</form>

<form method="post" action="<?= e(site_url('admin/settings.php')) ?>" class="card" style="margin-top:0">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="test_stripe">
    <button type="submit" class="btn btn-outline btn-sm">Test Stripe connection</button>
    <span class="muted small">Checks that the saved secret key is accepted by Stripe.</span>
</form>

<div class="card">
    <h3>Cron token</h3>
    <p class="muted">The daily reminder job calls this URL. Keep the token secret — anyone with it can trigger the sweep.</p>
    <?php if ($cron_url !== ''): ?>
        <p><code class="mono"><?= e($cron_url) ?></code></p>
    <?php else: ?>
        <p class="muted">No token set yet — generate one now.</p>
    <?php endif; ?>
    <form method="post" data-confirm="Regenerate the cron token? Update your scheduled task with the new URL afterwards.">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="regenerate_cron">
        <button type="submit" class="btn btn-outline">Regenerate token</button>
    </form>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
