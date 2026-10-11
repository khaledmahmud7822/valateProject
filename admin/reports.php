<?php
// admin/reports.php — revenue, occupancy, expirations, outstanding, lead pipeline.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Revenue Reports';
$nav_section = 'reports';
$required_perm = 'reports.view';
require __DIR__ . '/_guard.php';

$db = db();
$today = today();

$f_from = trim((string) ($_GET['from'] ?? date('Y-m-01', strtotime('-5 months'))));
$f_to = trim((string) ($_GET['to'] ?? $today));
$f_location = (int) ($_GET['location'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) {
    $f_from = date('Y-m-01', strtotime('-5 months'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to)) {
    $f_to = $today;
}
$loc_cond = $f_location > 0 ? ' AND COALESCE(s.location_id, co.location_id, cu.location_id) = ' . $f_location : '';

$q = fn($d) => $db->quote($d);

// ---- Revenue by month (payments in range) ----
$rev = $db->query(
    "SELECT substr(p.paid_at, 1, 7) AS ym, COALESCE(SUM(p.amount_cents), 0) AS c, COUNT(*) AS n
     FROM payments p
     LEFT JOIN invoices i ON i.id = p.invoice_id
     LEFT JOIN subscriptions s ON s.id = i.subscription_id
     LEFT JOIN companies co ON co.id = i.company_id
     LEFT JOIN customers cu ON cu.id = i.customer_id
     WHERE p.paid_at >= {$q($f_from)} AND p.paid_at <= {$q($f_to)} $loc_cond
     GROUP BY ym ORDER BY ym"
)->fetchAll(PDO::FETCH_ASSOC);
$rev_total = array_sum(array_map(fn($r) => (int) $r['c'], $rev));

// ---- Occupancy by location: active subs vs capacity ----
$occ = $db->query(
    "SELECT l.id, l.name, l.capacity,
            (SELECT COUNT(*) FROM subscriptions s2 WHERE s2.location_id = l.id AND s2.status = 'active' AND s2.end_date >= {$q($today)}) AS active_subs
     FROM parking_locations l
     " . ($f_location > 0 ? 'WHERE l.id = ' . $f_location : '') . "
     ORDER BY l.name"
)->fetchAll(PDO::FETCH_ASSOC);

// ---- Expiring soon (next 30 days) ----
$exp = $db->query(
    "SELECT s.*, c.name AS customer_name, co.name AS company_name, l.name AS location_name, v.plate
     FROM subscriptions s
     LEFT JOIN customers c ON c.id = s.customer_id
     LEFT JOIN companies co ON co.id = s.company_id
     LEFT JOIN parking_locations l ON l.id = s.location_id
     LEFT JOIN vehicles v ON v.id = s.vehicle_id
     WHERE s.status = 'active' AND s.end_date >= {$q($today)} AND s.end_date <= {$q(date('Y-m-d', strtotime($today . ' +30 days')))}
     " . ($f_location > 0 ? ' AND s.location_id = ' . $f_location : '') . "
     ORDER BY s.end_date"
)->fetchAll(PDO::FETCH_ASSOC);

// ---- Outstanding balances per bill-to ----
$out = $db->query(
    "SELECT COALESCE(c.name, co.name, '(unknown)') AS bill_to,
            CASE WHEN c.id IS NOT NULL THEN 'Individual' ELSE 'Corporate' END AS kind,
            COUNT(*) AS n, COALESCE(SUM(i.amount_cents - i.amount_paid_cents), 0) AS bal
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     LEFT JOIN companies co ON co.id = i.company_id
     LEFT JOIN subscriptions s ON s.id = i.subscription_id
     WHERE i.status IN ('unpaid','pending','partially_paid','overdue')
     " . ($f_location > 0 ? ' AND COALESCE(s.location_id, co.location_id, c.location_id) = ' . $f_location : '') . "
     GROUP BY bill_to, kind ORDER BY bal DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$out_total = array_sum(array_map(fn($r) => (int) $r['bal'], $out));

// ---- Leads by stage ----
$leads = $db->query(
    'SELECT stage, COUNT(*) AS c FROM valet_leads GROUP BY stage ORDER BY c DESC'
)->fetchAll(PDO::FETCH_ASSOC);
$lead_stages = ['new' => 'New', 'contacted' => 'Contacted', 'quoted' => 'Quoted', 'negotiating' => 'Negotiating', 'won' => 'Won', 'lost' => 'Lost'];

// ---- CSV export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $section = (string) ($_GET['section'] ?? 'revenue');
    $fname = 'report-' . $section . '-' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $o = fopen('php://output', 'w');
    if ($section === 'revenue') {
        fputcsv($o, ['Month', 'Payments', 'Revenue $']);
        foreach ($rev as $r) {
            fputcsv($o, [$r['ym'], $r['n'], number_format(((int) $r['c']) / 100, 2)]);
        }
    } elseif ($section === 'occupancy') {
        fputcsv($o, ['Location', 'Active subscriptions', 'Capacity', 'Occupancy %']);
        foreach ($occ as $r) {
            $pct = $r['capacity'] > 0 ? round(((int) $r['active_subs'] / (int) $r['capacity']) * 100, 1) : '';
            fputcsv($o, [$r['name'], $r['active_subs'], $r['capacity'], $pct]);
        }
    } elseif ($section === 'expiring') {
        fputcsv($o, ['Parker', 'Plate', 'Location', 'End date', 'Days left']);
        foreach ($exp as $r) {
            fputcsv($o, [$r['customer_name'] ?: $r['company_name'], $r['plate'], $r['location_name'], $r['end_date'], days_remaining($r['end_date'])]);
        }
    } elseif ($section === 'outstanding') {
        fputcsv($o, ['Bill to', 'Type', 'Open invoices', 'Balance $']);
        foreach ($out as $r) {
            fputcsv($o, [$r['bill_to'], $r['kind'], $r['n'], number_format(((int) $r['bal']) / 100, 2)]);
        }
    } else {
        fputcsv($o, ['Stage', 'Leads']);
        foreach ($leads as $r) {
            fputcsv($o, [$lead_stages[$r['stage']] ?? $r['stage'], $r['c']]);
        }
    }
    fclose($o);
    exit;
}

$locations = $db->query('SELECT id, name FROM parking_locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$csv_base = '?' . http_build_query(array_merge($_GET, ['export' => 'csv']));

require __DIR__ . '/_layout.php';
?>

<div class="page-head no-print">
    <h1>Revenue Reports</h1>
    <div class="page-actions">
        <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">Print / PDF</button>
    </div>
</div>

<form method="get" action="<?= e(site_url('admin/reports.php')) ?>" class="filter-bar no-print">
    <div class="field narrow"><label for="ffrom">From</label><input type="date" id="ffrom" name="from" value="<?= e($f_from) ?>"></div>
    <div class="field narrow"><label for="fto">To</label><input type="date" id="fto" name="to" value="<?= e($f_to) ?>"></div>
    <div class="field narrow"><label for="floc">Location</label>
        <select id="floc" name="location">
            <option value="0">All</option>
            <?php foreach ($locations as $l): ?>
                <option value="<?= (int) $l['id'] ?>"<?= $f_location === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Apply</button>
</form>

<div class="section-title"><h2>Revenue by month</h2>
    <a class="btn btn-ghost btn-sm no-print" href="<?= e($csv_base . '&section=revenue') ?>">Export CSV</a>
</div>
<?php if ($rev === []): ?>
    <div class="empty"><strong>No revenue in range</strong>Recorded payments will appear here.</div>
<?php else: ?>
    <div class="card">
        <?php
        $rev_chart = [];
        foreach ($rev as $r) {
            $rev_chart[date('M Y', strtotime($r['ym'] . '-01'))] = round(((int) $r['c']) / 100, 2);
        }
        echo chart_bars($rev_chart, 'Revenue collected ($)');
        ?>
        <div class="table-wrap" style="margin-top:14px">
            <table class="data">
                <thead><tr><th>Month</th><th>Payments</th><th>Revenue</th></tr></thead>
                <tbody>
                    <?php foreach ($rev as $r): ?>
                        <tr><td><?= e(date('F Y', strtotime($r['ym'] . '-01'))) ?></td><td><?= (int) $r['n'] ?></td><td><?= e(money((int) $r['c'])) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td>Total</td><td></td><td><?= e(money($rev_total)) ?></td></tr></tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="section-title"><h2>Occupancy by location</h2>
    <a class="btn btn-ghost btn-sm no-print" href="<?= e($csv_base . '&section=occupancy') ?>">Export CSV</a>
</div>
<?php if ($occ === []): ?>
    <div class="empty"><strong>No locations</strong></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Location</th><th>Active subs</th><th>Capacity</th><th>Occupancy</th></tr></thead>
            <tbody>
                <?php foreach ($occ as $r): ?>
                    <?php $pct = $r['capacity'] > 0 ? min(100, round(((int) $r['active_subs'] / (int) $r['capacity']) * 100, 1)) : null; ?>
                    <tr>
                        <td><?= e($r['name']) ?></td>
                        <td><?= (int) $r['active_subs'] ?></td>
                        <td><?= $r['capacity'] !== null ? (int) $r['capacity'] : '<span class="muted">—</span>' ?></td>
                        <td>
                            <?php if ($pct === null): ?>
                                <span class="muted">No capacity set</span>
                            <?php else: ?>
                                <div style="background:var(--gray-bg);border-radius:6px;height:14px;min-width:120px;position:relative">
                                    <div style="background:var(--navy-2);height:14px;border-radius:6px;width:<?= $pct ?>%"></div>
                                </div>
                                <span class="small"><?= $pct ?>%</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="section-title"><h2>Expiring soon (30 days)</h2>
    <a class="btn btn-ghost btn-sm no-print" href="<?= e($csv_base . '&section=expiring') ?>">Export CSV</a>
</div>
<?php if ($exp === []): ?>
    <div class="empty"><strong>Nothing expiring</strong>No subscriptions end in the next 30 days.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Parker</th><th>Plate</th><th>Location</th><th>Ends</th><th>Days left</th></tr></thead>
            <tbody>
                <?php foreach ($exp as $r): ?>
                    <?php $b = days_badge($r['end_date']); ?>
                    <tr>
                        <td><?= e($r['customer_name'] ?: $r['company_name']) ?></td>
                        <td class="mono"><?= e($r['plate'] ?: '—') ?></td>
                        <td><?= e($r['location_name'] ?: '—') ?></td>
                        <td><?= e(fmt_date($r['end_date'])) ?></td>
                        <td><span class="badge badge-<?= e($b['color']) ?>"><?= status_dot($b['color'], $b['label']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="section-title"><h2>Outstanding balances</h2>
    <a class="btn btn-ghost btn-sm no-print" href="<?= e($csv_base . '&section=outstanding') ?>">Export CSV</a>
</div>
<?php if ($out === []): ?>
    <div class="empty"><strong>Nothing outstanding</strong>All invoices are paid.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Bill to</th><th>Type</th><th>Open invoices</th><th>Balance</th></tr></thead>
            <tbody>
                <?php foreach ($out as $r): ?>
                    <tr>
                        <td><strong><?= e($r['bill_to']) ?></strong></td>
                        <td><?= e($r['kind']) ?></td>
                        <td><?= (int) $r['n'] ?></td>
                        <td><strong><?= e(money((int) $r['bal'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="3">Total outstanding</td><td><?= e(money($out_total)) ?></td></tr></tfoot>
        </table>
    </div>
<?php endif; ?>

<div class="section-title"><h2>Valet leads by stage</h2>
    <a class="btn btn-ghost btn-sm no-print" href="<?= e($csv_base . '&section=leads') ?>">Export CSV</a>
</div>
<?php if ($leads === []): ?>
    <div class="empty"><strong>No leads yet</strong></div>
<?php else: ?>
    <div class="card">
        <?php
        $lead_chart = [];
        foreach ($leads as $r) {
            $lead_chart[$lead_stages[$r['stage']] ?? $r['stage']] = (int) $r['c'];
        }
        echo chart_bars($lead_chart, 'Leads by stage');
        ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
