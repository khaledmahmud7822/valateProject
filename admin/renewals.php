<?php
// admin/renewals.php — expiring subscriptions: resend reminders, renew, upcoming schedule.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Renewals';
$nav_section = 'renewals';
$required_perm = 'renewals.view';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$today = today();
$can_send = can('reminders.send');
$can_edit_parkers = can('parkers.edit');

// ---- Resend reminder ----
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
             WHERE s.deleted_at IS NULL AND s.id = ?'
        );
        $st->execute([$sid]);
        $sub = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sub) {
            flash('Subscription not found.', 'error');
        } elseif (empty($sub['customer_email'])) {
            flash('No email on file for this parker — reminder not sent.', 'error');
        } else {
            $days = days_remaining($sub['end_date']);
            $tpl = $days < 0 ? 'expired_notice' : ($days <= 3 ? 'renewal_3' : 'renewal_7');
            $dedupe = 'manual_' . $sid . '_' . time();
            try {
                send_template($tpl, $sub['customer_email'], [
                    'name' => $sub['customer_name'],
                    'location_name' => $sub['location_name'],
                    'end_date' => fmt_date($sub['end_date']),
                    'days_left' => $days,
                    'business_name' => setting('business_name', 'Metro Valet Parking'),
                ], $dedupe);
                audit('subscription.reminder_resent', 'subscription', $sid, ['template' => $tpl, 'dedupe' => $dedupe, 'to' => $sub['customer_email']]);
                flash('Reminder re-sent to ' . $sub['customer_email'] . ' (' . $tpl . ').', 'success');
            } catch (Exception $ex) {
                flash('Reminder failed: ' . $ex->getMessage(), 'error');
            }
        }
    }
    redirect(site_url('admin/renewals.php'));
}

function mv_renewal_rows(PDO $db, string $today, string $cond): array
{
    $st = $db->prepare(
        "SELECT s.*, c.name AS customer_name, c.email AS customer_email, co.name AS company_name,
                l.name AS location_name, v.plate
         FROM subscriptions s
         LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN companies co ON co.id = s.company_id
         LEFT JOIN parking_locations l ON l.id = s.location_id
         LEFT JOIN vehicles v ON v.id = s.vehicle_id
         WHERE s.deleted_at IS NULL AND $cond
         ORDER BY s.end_date ASC"
    );
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

$q = fn($d) => $db->quote($d);
$d7 = date('Y-m-d', strtotime($today . ' +7 days'));
$d3 = date('Y-m-d', strtotime($today . ' +3 days'));

$rows_7 = mv_renewal_rows($db, $today, "s.status = 'active' AND s.end_date >= {$q($today)} AND s.end_date <= {$q($d7)}");
$rows_3 = array_values(array_filter($rows_7, fn($r) => $r['end_date'] <= $d3));
$rows_expired = mv_renewal_rows($db, $today, "s.status IN ('active','expired') AND s.end_date < {$q($today)}");

// Upcoming schedule: expirations in the next 30 days grouped by date.
$sched = $db->prepare(
    "SELECT s.end_date, COUNT(*) AS c FROM subscriptions s
     WHERE s.deleted_at IS NULL AND s.status = 'active' AND s.end_date >= {$q($today)} AND s.end_date <= {$q(date('Y-m-d', strtotime($today . ' +30 days')))}
     GROUP BY s.end_date ORDER BY s.end_date"
);
$sched->execute();
$schedule = $sched->fetchAll(PDO::FETCH_ASSOC);
$sched_data = [];
foreach ($schedule as $s) {
    $sched_data[date('M j', strtotime($s['end_date']))] = (int) $s['c'];
}

function mv_renewal_table(array $rows, bool $can_send, bool $can_edit_parkers, string $today): void
{
    if ($rows === []) {
        echo '<div class="empty"><strong>Nothing here</strong>No subscriptions in this group.</div>';
        return;
    }
    ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Parker</th><th>Plate</th><th>Location</th><th>Ends</th><th>Days left</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $b = days_badge($r['end_date']); ?>
                    <tr>
                        <td><strong><?= e($r['customer_name'] ?: $r['company_name'] ?: '—') ?></strong><br>
                            <span class="muted small"><?= e($r['customer_email'] ?: 'no email') ?></span></td>
                        <td class="mono"><?= e($r['plate'] ?: '—') ?></td>
                        <td><?= e($r['location_name'] ?: '—') ?></td>
                        <td class="nowrap"><?= e(fmt_date($r['end_date'])) ?></td>
                        <td><span class="badge badge-<?= e($b['color']) ?>"><?= status_dot($b['color'], $b['label']) ?></span></td>
                        <td>
                            <div class="row-actions">
                                <?php if ($can_send && $r['customer_email']): ?>
                                    <form method="post" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="resend" value="1">
                                        <input type="hidden" name="subscription_id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-xs">Resend reminder</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($can_edit_parkers): ?>
                                    <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/parkers.php?action=renew&id=' . (int) $r['id'])) ?>">Renew</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1>Renewals</h1>
    <div class="page-actions">
        <a class="btn btn-outline btn-sm" href="<?= e(site_url('admin/reminders.php')) ?>">Reminder center</a>
    </div>
</div>

<div class="section-title"><h2>Expiring within 3 days <span class="badge badge-red"><?= count($rows_3) ?></span></h2></div>
<?php mv_renewal_table($rows_3, $can_send, $can_edit_parkers, $today); ?>

<div class="section-title"><h2>Expiring within 7 days <span class="badge badge-amber"><?= count($rows_7) ?></span></h2></div>
<?php mv_renewal_table($rows_7, $can_send, $can_edit_parkers, $today); ?>

<div class="section-title"><h2>Expired <span class="badge badge-gray"><?= count($rows_expired) ?></span></h2></div>
<?php mv_renewal_table($rows_expired, $can_send, $can_edit_parkers, $today); ?>

<div class="section-title"><h2>Upcoming schedule — next 30 days</h2></div>
<?php if ($schedule === []): ?>
    <div class="empty"><strong>No upcoming expirations</strong>Nothing expires in the next 30 days.</div>
<?php else: ?>
    <div class="card">
        <?= chart_bars($sched_data, 'Expirations by date') ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
