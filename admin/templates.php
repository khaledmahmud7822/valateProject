<?php
// admin/templates.php — edit notification templates, toggle, test-send.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Email Templates';
$nav_section = 'templates';
$required_perm = 'templates.edit';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();

$templates = $db->query('SELECT * FROM notification_templates ORDER BY `key`')->fetchAll(PDO::FETCH_ASSOC);
$keys = array_column($templates, 'key');
$active_key = (string) ($_GET['key'] ?? $_POST['key'] ?? ($keys[0] ?? ''));
$tpl = null;
foreach ($templates as $t) {
    if ($t['key'] === $active_key) {
        $tpl = $t;
        break;
    }
}

$common_vars = ['business_name', 'name', 'company_name', 'ref_no', 'location_name', 'end_date', 'days_left', 'invoice_number', 'amount', 'due_date', 'pay_url', 'temp_password', 'login_url', 'paid_at', 'method', 'reason'];

// ---------- Save ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    verify_csrf();
    if (!$tpl) {
        flash('Template not found.', 'error');
    } else {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;
        if ($subject === '' || $body === '') {
            flash('Subject and body are required.', 'error');
        } else {
            $db->prepare('UPDATE notification_templates SET subject = ?, body = ?, active = ?, updated_at = CURRENT_TIMESTAMP WHERE `key` = ?')
                ->execute([$subject, $body, $active, $tpl['key']]);
            audit('template.updated', 'notification_template', null, ['key' => $tpl['key'], 'active' => $active]);
            flash('Template "' . $tpl['key'] . '" saved.', 'success');
        }
    }
    redirect(site_url('admin/templates.php?key=' . urlencode($active_key)));
}

// ---------- Test send ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_send'])) {
    verify_csrf();
    if (!$tpl) {
        flash('Template not found.', 'error');
    } else {
        // Fill every {{var}} token in subject+body with a sample value.
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $tpl['subject'] . ' ' . $tpl['body'], $m);
        $vars = [];
        foreach (array_unique($m[1]) as $v) {
            $vars[$v] = match ($v) {
                'business_name' => setting('business_name', 'Metro Valet Parking'),
                'amount' => money(10000),
                'pay_url' => site_url('portal/pay.php?token=TEST'),
                'login_url' => site_url('portal/login.php'),
                'end_date' => fmt_date(date('Y-m-d', strtotime('+7 days'))),
                default => 'TEST-' . $v,
            };
        }
        try {
            send_template($tpl['key'], $user['email'], $vars, 'test_' . $tpl['key'] . '_' . time());
            audit('template.test_sent', 'notification_template', null, ['key' => $tpl['key'], 'to' => $user['email']]);
            flash('Test email sent to ' . $user['email'] . '.', 'success');
        } catch (Exception $ex) {
            flash('Test send failed: ' . $ex->getMessage(), 'error');
        }
    }
    redirect(site_url('admin/templates.php?key=' . urlencode($active_key)));
}

require __DIR__ . '/_layout.php';
?>

<div class="page-head"><h1>Email Templates</h1></div>

<div class="grid-2" style="align-items:start">
    <div class="card">
        <h3>Templates</h3>
        <?php if ($templates === []): ?>
            <div class="empty"><strong>No templates</strong>Run the installer to seed notification templates.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Key</th><th>Subject</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($templates as $t): ?>
                            <tr<?= $tpl && $t['key'] === $tpl['key'] ? ' style="background:#F8FAFD"' : '' ?>>
                                <td><a href="<?= e(site_url('admin/templates.php?key=' . urlencode($t['key']))) ?>" class="mono"><?= e($t['key']) ?></a></td>
                                <td class="small"><?= e(mb_strimwidth($t['subject'], 0, 50, '…')) ?></td>
                                <td>
                                    <?php if ((int) $t['active'] === 1): ?>
                                        <span class="badge badge-green"><?= status_dot('green', 'Active') ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-gray"><?= status_dot('gray', 'Disabled') ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div class="legend" style="margin-top:14px">
            <strong>Available variables</strong> — use <code>{{var}}</code> in subject or body:
            <br><?= implode(' ', array_map(fn($v) => '<code>{{' . $v . '}}</code>', $common_vars)) ?>
        </div>
    </div>

    <div class="card">
        <?php if (!$tpl): ?>
            <div class="empty"><strong>Select a template</strong></div>
        <?php else: ?>
            <h3 class="mono"><?= e($tpl['key']) ?></h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="key" value="<?= e($tpl['key']) ?>">
                <input type="hidden" name="save" value="1">
                <div class="field">
                    <label for="subject">Subject</label>
                    <input type="text" id="subject" name="subject" value="<?= e($tpl['subject']) ?>" required maxlength="255">
                </div>
                <div class="field">
                    <label for="body">Body (HTML allowed)</label>
                    <textarea id="body" name="body" required style="min-height:260px"><?= e($tpl['body']) ?></textarea>
                </div>
                <div class="field">
                    <label class="check-line"><input type="checkbox" name="active" value="1"<?= (int) $tpl['active'] === 1 ? ' checked' : '' ?>> Active (sends go out; disabled templates are skipped)</label>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary">Save template</button>
                </div>
            </form>
            <hr class="hr-gold">
            <h3>Test send</h3>
            <p class="muted small">Sends this template to your own address (<?= e($user['email']) ?>) with sample values, so you can proof the layout.</p>
            <form method="post" data-confirm="Send a test email to <?= e($user['email']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="key" value="<?= e($tpl['key']) ?>">
                <input type="hidden" name="test_send" value="1">
                <button type="submit" class="btn btn-outline">Send test email</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
