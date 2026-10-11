<?php
// admin/plate_lookup.php — normalize a plate and show registration/permit standing.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Plate Lookup';
$nav_section = 'plate_lookup';
$required_perm = 'plates.lookup';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$today = today();
$can_see_amounts = can('invoices.view');

$plate_in = trim((string) ($_GET['plate'] ?? ''));
$state_in = trim((string) ($_GET['state'] ?? ''));
$result = null;
$searched = false;

if ($plate_in !== '') {
    $searched = true;
    $norm = normalize_plate($plate_in);

    $sql = 'SELECT v.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                   co.name AS company_name
            FROM vehicles v
            LEFT JOIN customers c ON c.id = v.customer_id
            LEFT JOIN companies co ON co.id = v.company_id
            WHERE v.plate_norm = ?';
    $params = [$norm];
    if ($state_in !== '') {
        $sql .= ' AND UPPER(v.plate_state) = ?';
        $params[] = strtoupper($state_in);
    }
    $sql .= ' ORDER BY v.id LIMIT 5';
    $st = $db->prepare($sql);
    $st->execute($params);
    $vehicles = $st->fetchAll(PDO::FETCH_ASSOC);

    audit('plate.lookup', 'plate_lookup', null, [
        'plate' => $plate_in,
        'normalized' => $norm,
        'state' => $state_in,
        'matches' => count($vehicles),
    ]);

    $result = ['vehicles' => [], 'norm' => $norm];
    foreach ($vehicles as $v) {
        // Current subscription: active with latest end date, else most recent.
        $ss = $db->prepare(
            'SELECT s.*, l.name AS location_name
             FROM subscriptions s LEFT JOIN parking_locations l ON l.id = s.location_id
             WHERE s.vehicle_id = ? ORDER BY s.end_date DESC, s.id DESC LIMIT 1'
        );
        $ss->execute([$v['id']]);
        $sub = $ss->fetch(PDO::FETCH_ASSOC);

        // Active permit covering today.
        $ps = $db->prepare(
            "SELECT * FROM permits WHERE vehicle_id = ? AND status = 'active' AND end_date >= ? ORDER BY end_date DESC LIMIT 1"
        );
        $ps->execute([$v['id'], $today]);
        $permit = $ps->fetch(PDO::FETCH_ASSOC);

        // Payment standing from the latest invoice on the subscription.
        $inv = null;
        if ($sub) {
            $is = $db->prepare('SELECT * FROM invoices WHERE subscription_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
            $is->execute([$sub['id']]);
            $inv = $is->fetch(PDO::FETCH_ASSOC);
        }

        $result['vehicles'][] = [
            'vehicle' => $v,
            'subscription' => $sub,
            'permit' => $permit,
            'invoice' => $inv,
        ];
    }
}

require __DIR__ . '/_layout.php';
?>

<div class="page-head"><h1>Plate Lookup</h1></div>

<div class="card">
    <form method="get" action="<?= e(site_url('admin/plate_lookup.php')) ?>">
        <div class="form-grid">
            <div class="field">
                <label for="plate">License plate *</label>
                <input type="text" id="plate" name="plate" value="<?= e($plate_in) ?>" required
                       maxlength="20" style="text-transform:uppercase" placeholder="ABC1234" autofocus>
                <div class="hint">Spaces and dashes are ignored automatically.</div>
            </div>
            <div class="field">
                <label for="state">Plate state (optional)</label>
                <input type="text" id="state" name="state" value="<?= e($state_in) ?>" maxlength="10" placeholder="MI">
            </div>
        </div>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Look up</button>
            <?php if ($searched): ?>
                <a class="btn btn-ghost" href="<?= e(site_url('admin/plate_lookup.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($searched): ?>
    <?php if ($result['vehicles'] === []): ?>
        <div class="empty">
            <strong>Plate not registered</strong>
            No vehicle matches <span class="mono"><?= e($result['norm']) ?></span><?= $state_in !== '' ? ' (' . e(strtoupper($state_in)) . ')' : '' ?>.
        </div>
    <?php else: ?>
        <?php foreach ($result['vehicles'] as $r): ?>
            <?php
            $v = $r['vehicle'];
            $sub = $r['subscription'];
            $permit = $r['permit'];
            $inv = $r['invoice'];
            $owner = $v['customer_name'] ?: $v['company_name'] ?: '—';
            $permit_ok = $permit && $sub && $sub['status'] === 'active' && $sub['end_date'] >= $today;
            ?>
            <div class="card">
                <div class="section-title" style="margin-top:0">
                    <h2><span class="mono"><?= e($v['plate']) ?></span>
                        <span class="muted small"><?= e($v['plate_state'] ?: '') ?></span></h2>
                    <?php if ($permit_ok): ?>
                        <span class="badge badge-green"><?= status_dot('green', 'Permit active') ?></span>
                    <?php else: ?>
                        <span class="badge badge-red"><?= status_dot('red', 'No active permit') ?></span>
                    <?php endif; ?>
                </div>
                <dl class="dl">
                    <dt>Registered</dt>
                    <dd><span class="badge badge-green"><?= status_dot('green', 'Yes') ?></span></dd>
                    <dt>Vehicle</dt>
                    <dd><?= e(trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? '')) ?: '—') ?><?= $v['color'] ? ' — ' . e($v['color']) : '' ?></dd>
                    <dt>Owner</dt>
                    <dd><?= e($owner) ?>
                        <?= $v['company_name'] ? '<span class="badge badge-navy">Corporate</span>' : '<span class="badge badge-navy">Individual</span>' ?>
                    </dd>
                    <?php if ($v['customer_email']): ?><dt>Contact</dt><dd><?= e($v['customer_email']) ?><?= $v['customer_phone'] ? ' · ' . e($v['customer_phone']) : '' ?></dd><?php endif; ?>
                    <dt>Location</dt>
                    <dd><?= $sub ? e($sub['location_name'] ?: '—') : '<span class="muted">No subscription on record</span>' ?></dd>
                    <?php if ($sub): ?>
                        <?php $b = days_badge($sub['end_date']); ?>
                        <dt>Subscription</dt>
                        <dd><?= e(ucfirst($sub['status'])) ?> · ends <?= e(fmt_date($sub['end_date'])) ?>
                            <span class="badge badge-<?= e($b['color']) ?>"><?= status_dot($b['color'], $b['label']) ?></span></dd>
                    <?php endif; ?>
                    <dt>Payment standing</dt>
                    <dd>
                        <?php if (!$inv): ?>
                            <span class="muted">No invoice on record</span>
                        <?php elseif ($can_see_amounts): ?>
                            <?= e(ucfirst(str_replace('_', ' ', $inv['status']))) ?>
                            — <?= e(money((int) $inv['amount_paid_cents'])) ?> of <?= e(money((int) $inv['amount_cents'])) ?>
                            paid (<?= e($inv['number']) ?>)
                        <?php else: ?>
                            <?= $inv['status'] === 'paid' ? 'Paid' : 'Unpaid' ?>
                        <?php endif; ?>
                    </dd>
                </dl>
                <?php if ($sub && can('parkers.view')): ?>
                    <div class="btn-row">
                        <a class="btn btn-outline btn-sm" href="<?= e(site_url('admin/parker_form.php?subscription_id=' . (int) $sub['id'])) ?>">Open parker record</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
