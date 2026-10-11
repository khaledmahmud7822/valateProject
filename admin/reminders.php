<?php
// admin/reminders.php — renewal reminder center: upcoming schedule, run-now, resends.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Reminders';
$nav_section = 'reminders';
$required_perm = 'reminders.view';
require __DIR__ . '/_guard.php';

if (!function_exists('find_due_reminders')) {
    require_once __DIR__ . '/../lib/reminders.php';
}

$db = db();
$user = current_user();
$today = today();
$can_send = can('reminders.send');

// ---------- Run reminders now ----------
$run_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_now'])) {
    verify_csrf();
    if (!$can_send) {
        flash('You do not have permission to send reminders.', 'error');
    } else {
        try {
            $run_result = process_reminders();
            audit('reminder.manual_run', 'reminder', null, ['result' => is_array($run_result) ? $run_result : (string) $run_result]);
            flash('Reminder run completed.', 'success');
        } catch (Exception $ex) {
            flash('Reminder run failed: ' . $ex->getMessage(), 'error');
        }
    }
    // Fall through to render (keeps $run_result visible) — no redirect.
}

// ---------- Per-subscription resend ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    verify_csrf();
    if (!$can_send) {
        flash('You do not have permission to send reminders.', 'error');
    } else {
        $sid = (int) ($_POST['subscription_id'] ?? 0);
        $st = $db->prepare(
            'SELECT s.*, c.name AS customer_name, c.email AS customer_email, l.name AS location_name
             FROM subscriptions s
             LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN parking_locations l ON l.id = s.location_id
             WHERE s.id = ?'
        );
        $st->execute([$sid]);
        $sub = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sub || empty($sub['customer_email'])) {
            flash('Subscription not found or no email on file.', 'error');
        } else {
            $days = days_remaining($sub['end_date']);
            $tpl = $days < 0 ? 'expired_notice' : ($days <= 3 ? 'renewal_3' : 'renewal_7');
            try {
                send_template($tpl, $sub['customer_email'], [
                    'name' => $sub['customer_name'],
                    'location_name' => $sub['location_name'],
                    'end_date' => fmt_date($sub['end_date']),
                    'days_left' => $days,
                    'business_name' => setting('business_name', 'Metro Valet Parking'),
                ], 'manual_' . $sid . '_' . time());
                audit('subscription.reminder_resent', 'subscription', $sid, ['template' => $tpl]);
                flash('Reminder re-sent (' . $tpl . ').', 'success');
            } catch (Exception $ex) {
                flash('Resend failed: ' . $ex->getMessage(), 'error');
            }
        }
    }
}

// ---------- Upcoming schedule ----------
$due = [];
try {
    $due = find_due_reminders();
} catch (Exception $ex) {
    flash('Could not load the reminder schedule: ' . $ex->getMessage(), 'error');
    $due = [];
}

// find_due_reminders() returns ['seven' => [...], 'three' => [...], 'expired' => [...]].
// Each row carries customer_name, customer_email, location_name, plate, end_date, id.
$groups = [
    'seven' => isset($due['seven']) && is_array($due['seven']) ? array_values($due['seven']) : [],
    'three' => isset($due['three']) && is_array($due['three']) ? array_values($due['three']) : [],
    'expired' => isset($due['expired']) && is_array($due['expired']) ? array_values($due['expired']) : [],
];

function mv_rem_row(array $r, bool $can_send): void
{
    $name = $r['customer_name'] ?? $r['name'] ?? $r['company_name'] ?? '—';
    $email = $r['customer_email'] ?? $r['email'] ?? '';
    $loc = $r['location_name'] ?? '';
    $end = $r['end_date'] ?? '';
    $sid = (int) ($r['subscription_id'] ?? $r['id'] ?? 0);
    $b = $end !== '' ? days_badge($end) : ['color' => 'gray', 'label' => '—'];
    ?>
    <tr>
        <td><strong><?= e($name) ?></strong><br><span class="muted small"><?= e($email) ?></span></td>
        <td><?= e($loc) ?></td>
        <td class="nowrap"><?= $end !== '' ? e(fmt_date($end)) : '—' ?></td>
        <td><span class="badge badge-<?= e($b['color']) ?>"><?= status_dot($b['color'], $b['label']) ?></span></td>
        <td>
            <?php if ($can_send && $sid > 0 && $email !== ''): ?>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="resend" value="1">
                    <input type="hidden" name="subscription_id" value="<?= $sid ?>">
                    <button type="submit" class="btn btn-outline btn-xs">Resend now</button>
                </form>
            <?php else: ?>
                <span class="muted small">—</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

function mv_rem_table(string $title, array $rows, bool $can_send): void
{
    echo '<div class="section-title"><h2>' . e($title) . ' <span class="badge badge-navy">' . count($rows) . '</span></h2></div>';
    if ($rows === []) {
        echo '<div class="empty"><strong>Nothing due</strong>No subscriptions in this group.</div>';
        return;
    }
    echo '<div class="table-wrap"><table class="data"><thead><tr><th>Parker</th><th>Location</th><th>Ends</th><th>Days left</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        mv_rem_row(is_array($r) ? $r : [], $can_send);
    }
    echo '</tbody></table></div>';
}

$reminders_on = setting('reminders_enabled', '1') === '1';

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1>Reminder Center</h1>
    <div class="page-actions">
        <?php if ($can_send): ?>
            <form method="post" class="inline-form" data-confirm="Run the reminder sweep now? Emails go to parkers whose subscriptions are due.">
                <?= csrf_field() ?>
                <input type="hidden" name="run_now" value="1">
                <button type="submit" class="btn btn-primary btn-sm">Run reminders now</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($run_result !== null): ?>
    <div class="card">
        <h3>Run result</h3>
        <?php if (is_array($run_result)): ?>
            <dl class="dl">
                <?php foreach ($run_result as $k => $v): ?>
                    <dt><?= e(ucfirst(str_replace('_', ' ', (string) $k))) ?></dt>
                    <dd><?= e(is_array($v) ? json_encode($v) : (string) $v) ?></dd>
                <?php endforeach; ?>
            </dl>
        <?php else: ?>
            <p><?= e((string) $run_result) ?></p>
        <?php endif; ?>
        <p class="muted small">Every send is logged under <a href="<?= e(site_url('admin/notifications.php')) ?>">Notification History</a> with its dedupe key, so re-runs never double-send.</p>
    </div>
<?php endif; ?>

<div class="card">
    <h3>Schedule</h3>
    <p class="muted">
        Renewal reminders <?= $reminders_on ? '<span class="badge badge-green">' . status_dot('green', 'On') . '</span>' : '<span class="badge badge-gray">' . status_dot('gray', 'Off') . '</span>' ?>
        — 7-day, 3-day, and expiry notices are covered by this master switch (toggle in <a href="<?= e(site_url('admin/settings.php')) ?>">Settings</a>).
        The daily cron (<code class="mono">cron/reminders.php?token=…</code>) runs the same sweep automatically.
    </p>
</div>

<?php
mv_rem_table('Due in 7 days', $groups['seven'], $can_send);
mv_rem_table('Due in 3 days', $groups['three'], $can_send);
mv_rem_table('Expired', $groups['expired'], $can_send);
?>

<?php require __DIR__ . '/_footer.php'; ?>
