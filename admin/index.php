<?php
// admin/index.php — Parking Operations Command Center.
// Premium dashboard overview. All data is real, from the live database.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Command Center';
$nav_section = 'dashboard';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();
$today = date('Y-m-d');
$q = fn($d) => $db->quote($d);

// ---------- Location filter ----------
$filter_location = (int) ($_GET['location'] ?? 0);
$locations = $db->query('SELECT id, name FROM parking_locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$loc_name = 'All locations';
foreach ($locations as $l) {
    if ((int) $l['id'] === $filter_location) {
        $loc_name = $l['name'];
    }
}
$loc_sql = $filter_location > 0 ? ' AND s.location_id = ' . $filter_location : '';

// ---------- Greeting ----------
$hour = (int) date('G');
$greeting = $hour < 5 ? 'Good evening' : ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'));
$first_name = trim((string) strtok((string) ($user['name'] ?? 'Operator'), ' '));

// ---------- KPIs ----------
$active_where = "s.status = 'active' AND s.end_date >= {$q($today)} AND s.deleted_at IS NULL" . $loc_sql;
$parkers_ind = (int) $db->query("SELECT COUNT(*) FROM subscriptions s WHERE s.customer_id IS NOT NULL AND $active_where")->fetchColumn();
$parkers_corp = (int) $db->query("SELECT COUNT(*) FROM subscriptions s WHERE s.company_id IS NOT NULL AND $active_where")->fetchColumn();
$active_parkers = $parkers_ind + $parkers_corp;

// Revenue collected (this month), location-aware via invoice -> subscription.
$rev_join = $filter_location > 0
    ? 'JOIN invoices i ON i.id = p.invoice_id LEFT JOIN subscriptions s ON s.id = i.subscription_id'
    : '';
$rev_where = 'p.paid_at >= ' . $q(date('Y-m-01'));
if ($filter_location > 0) {
    $rev_where .= " AND (s.location_id = $filter_location OR i.subscription_id IS NULL)";
}
$revenue_month_cents = (int) $db->query("SELECT COALESCE(SUM(p.amount_cents), 0) FROM payments p $rev_join WHERE $rev_where")->fetchColumn();

// Outstanding invoices.
$out_exists = $filter_location > 0
    ? " AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.id = i.subscription_id AND s.location_id = $filter_location)"
    : '';
$out_cond = "i.status IN ('unpaid','pending','partially_paid','overdue')" . $out_exists;
$outstanding_cents = (int) $db->query("SELECT COALESCE(SUM(i.amount_cents - i.amount_paid_cents), 0) FROM invoices i WHERE $out_cond")->fetchColumn();
$outstanding_count = (int) $db->query("SELECT COUNT(*) FROM invoices i WHERE $out_cond")->fetchColumn();

// Renewal attention: expired + expiring within 7 days.
$d7 = date('Y-m-d', strtotime($today . ' +7 days'));
$d3 = date('Y-m-d', strtotime($today . ' +3 days'));
$exp7 = (int) $db->query(
    "SELECT COUNT(*) FROM subscriptions s WHERE s.status = 'active' AND s.deleted_at IS NULL AND s.end_date >= {$q($today)} AND s.end_date <= {$q($d7)} $loc_sql"
)->fetchColumn();
$exp3 = (int) $db->query(
    "SELECT COUNT(*) FROM subscriptions s WHERE s.status = 'active' AND s.deleted_at IS NULL AND s.end_date >= {$q($today)} AND s.end_date <= {$q($d3)} $loc_sql"
)->fetchColumn();
$expired = (int) $db->query(
    "SELECT COUNT(*) FROM subscriptions s WHERE s.status IN ('active','expired') AND s.deleted_at IS NULL AND s.end_date < {$q($today)} $loc_sql"
)->fetchColumn();
$attention = $exp7 + $expired;

// ---------- Revenue chart data (monthly / quarterly / yearly) ----------
function mv_rev_series(PDO $db, string $period, string $start, int $filter_location, callable $q): array
{
    $join = $filter_location > 0
        ? 'JOIN invoices i ON i.id = p.invoice_id LEFT JOIN subscriptions s ON s.id = i.subscription_id'
        : '';
    $where = 'p.paid_at >= ' . $q($start);
    if ($filter_location > 0) {
        $where .= " AND (s.location_id = $filter_location OR i.subscription_id IS NULL)";
    }
    if ($period === 'monthly') {
        $bucket = "substr(p.paid_at, 1, 7)";
    } elseif ($period === 'quarterly') {
        $bucket = "substr(p.paid_at, 1, 4) || '-Q' || ((CAST(substr(p.paid_at, 6, 2) AS INTEGER) + 2) / 3)";
    } else {
        $bucket = "substr(p.paid_at, 1, 4)";
    }
    $rows = $db->query(
        "SELECT $bucket AS b, COALESCE(SUM(p.amount_cents), 0) AS c FROM payments p $join WHERE $where GROUP BY b ORDER BY b"
    )->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[$r['b']] = (int) $r['c'];
    }
    return $map;
}

$rev_monthly_map = mv_rev_series($db, 'monthly', date('Y-m-01', strtotime('-11 months')), $filter_location, $q);
$rev_monthly = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $rev_monthly[] = ['label' => date('M', strtotime($ym . '-01')), 'full' => date('F Y', strtotime($ym . '-01')), 'value' => round(($rev_monthly_map[$ym] ?? 0) / 100, 2)];
}
$rev_quarterly_map = mv_rev_series($db, 'quarterly', date('Y-m-01', strtotime('-21 months')), $filter_location, $q);
$rev_quarterly = [];
$now_q = (int) ceil((int) date('n') / 3);
$now_y = (int) date('Y');
for ($i = 7; $i >= 0; $i--) {
    $qi = $now_q - $i;
    $yi = $now_y;
    while ($qi <= 0) {
        $qi += 4;
        $yi--;
    }
    $key = $yi . '-Q' . $qi;
    $rev_quarterly[] = ['label' => "Q$qi", 'full' => "Q$qi $yi", 'value' => round(($rev_quarterly_map[$key] ?? 0) / 100, 2)];
}
$rev_yearly_map = mv_rev_series($db, 'yearly', date('Y-01-01', strtotime('-4 years')), $filter_location, $q);
$rev_yearly = [];
for ($i = 4; $i >= 0; $i--) {
    $yy = (string) ((int) date('Y') - $i);
    $rev_yearly[] = ['label' => $yy, 'full' => $yy, 'value' => round(($rev_yearly_map[$yy] ?? 0) / 100, 2)];
}
$rev_total_12m = array_sum(array_column($rev_monthly, 'value'));

// Top locations by revenue (last 12 months) — for comparison.
$top_locs = [];
if (can('reports.view') || can('invoices.view')) {
    $top_locs = $db->query(
        'SELECT l.name, COALESCE(SUM(p.amount_cents), 0) AS c ' .
        'FROM payments p JOIN invoices i ON i.id = p.invoice_id ' .
        'JOIN subscriptions s ON s.id = i.subscription_id ' .
        'JOIN parking_locations l ON l.id = s.location_id ' .
        'WHERE p.paid_at >= ' . $q(date('Y-m-01', strtotime('-11 months'))) .
        ' GROUP BY l.id, l.name ORDER BY c DESC LIMIT 5'
    )->fetchAll(PDO::FETCH_ASSOC);
}

// ---------- Renewal intelligence ----------
function mv_cmd_renewals(PDO $db, string $cond): array
{
    $st = $db->prepare(
        "SELECT s.id, s.end_date, s.status, s.customer_id, s.company_id,
                c.name AS customer_name, co.name AS company_name,
                l.name AS location_name, v.plate, v.make AS v_make, v.model AS v_model,
                (SELECT i.status FROM invoices i WHERE i.subscription_id = s.id ORDER BY i.created_at DESC LIMIT 1) AS inv_status,
                (SELECT MAX(n.sent_at) FROM notification_logs n
                  WHERE n.status = 'sent'
                    AND (n.dedupe_key LIKE 'renewal_7:' || s.id || ':%'
                      OR n.dedupe_key LIKE 'renewal_3:' || s.id || ':%'
                      OR n.dedupe_key LIKE 'expired_notice:' || s.id || ':%'
                      OR n.dedupe_key LIKE 'manual_' || s.id || '\\_%' ESCAPE '\\')
                ) AS last_reminder
         FROM subscriptions s
         LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN companies co ON co.id = s.company_id
         LEFT JOIN parking_locations l ON l.id = s.location_id
         LEFT JOIN vehicles v ON v.id = s.vehicle_id
         WHERE $cond
         ORDER BY s.end_date ASC
         LIMIT 30"
    );
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
$cond_base = $filter_location > 0 ? " AND s.location_id = $filter_location" : '';
$rows_expired = mv_cmd_renewals($db, "s.status IN ('active','expired') AND s.end_date < {$q($today)}" . $cond_base);
$rows_7 = mv_cmd_renewals($db, "s.status = 'active' AND s.end_date >= {$q($today)} AND s.end_date <= {$q($d7)}" . $cond_base);
$rows_3 = array_values(array_filter($rows_7, fn($r) => $r['end_date'] <= $d3));

$can_renew = can('parkers.edit');
$can_remind = can('reminders.send');

// ---------- Location performance ----------
$loc_cards = [];
if (can('locations.view')) {
    $loc_list = $db->query('SELECT id, name, address, capacity FROM parking_locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($loc_list as $lc) {
        $lid = (int) $lc['id'];
        $parkers = (int) $db->query(
            "SELECT COUNT(*) FROM subscriptions s WHERE s.location_id = $lid AND s.status = 'active' AND s.end_date >= {$q($today)}"
        )->fetchColumn();
        $rev = (int) $db->query(
            'SELECT COALESCE(SUM(p.amount_cents), 0) FROM payments p ' .
            'JOIN invoices i ON i.id = p.invoice_id JOIN subscriptions s ON s.id = i.subscription_id ' .
            "WHERE s.location_id = $lid AND p.paid_at >= " . $q(date('Y-m-01', strtotime('-11 months')))
        )->fetchColumn();
        $out = (int) $db->query(
            "SELECT COALESCE(SUM(i.amount_cents - i.amount_paid_cents), 0) FROM invoices i " .
            "WHERE i.status IN ('unpaid','pending','partially_paid','overdue') " .
            "AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.id = i.subscription_id AND s.location_id = $lid)"
        )->fetchColumn();
        $loc_cards[] = [
            'id' => $lid,
            'name' => $lc['name'],
            'address' => $lc['address'],
            'capacity' => $lc['capacity'] !== null ? (int) $lc['capacity'] : null,
            'parkers' => $parkers,
            'revenue' => $rev,
            'outstanding' => $out,
        ];
    }
}

// ---------- Financial overview ----------
$fin = [];
if (can('invoices.view')) {
    foreach (['paid' => 'Paid', 'unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'overdue' => 'Overdue', 'pending' => 'Pending'] as $st => $lbl) {
        $c = (int) $db->query("SELECT COUNT(*) FROM invoices i WHERE i.status = " . $q($st) . $out_exists)->fetchColumn();
        $amt = (int) $db->query("SELECT COALESCE(SUM(i.amount_cents - i.amount_paid_cents), 0) FROM invoices i WHERE i.status = " . $q($st) . $out_exists)->fetchColumn();
        $fin[] = ['status' => $st, 'label' => $lbl, 'count' => $c, 'amount_cents' => $amt];
    }
}

// ---------- Activity feed ----------
$feed = [];
try {
    $log_rows = $db->query(
        'SELECT action, entity, entity_id, details, created_at FROM activity_logs ORDER BY id DESC LIMIT 14'
    )->fetchAll(PDO::FETCH_ASSOC);
    $feed_labels = [
        'subscription.created' => ['Monthly parker registered', 'blue'],
        'subscription.renewed' => ['Subscription renewed', 'green'],
        'payment.recorded' => ['Payment recorded', 'green'],
        'invoice.created' => ['Invoice created', 'gray'],
        'invoice.sent' => ['Invoice emailed', 'gray'],
        'customer.created' => ['New customer', 'blue'],
        'vehicle.updated' => ['Vehicle updated', 'gray'],
        'subscription.reminder_resent' => ['Reminder sent', 'amber'],
        'user.login' => null,
        'plate.lookup' => null,
        'portal_login' => null,
    ];
    foreach ($log_rows as $lr) {
        $known = array_key_exists($lr['action'], $feed_labels);
        $cfg = $known ? $feed_labels[$lr['action']] : [ucwords(str_replace(['.', '_'], ' ', $lr['action'])), 'gray'];
        if ($cfg === null) {
            continue;
        }
        $det = json_decode((string) ($lr['details'] ?? '{}'), true);
        $bits = [];
        if (is_array($det)) {
            foreach (array_slice($det, 0, 2) as $k => $v) {
                if (is_scalar($v)) {
                    $bits[] = "$k: $v";
                }
            }
        }
        $feed[] = [
            'label' => $cfg[0],
            'tone' => $cfg[1],
            'detail' => implode(' · ', $bits),
            'at' => $lr['created_at'],
        ];
        if (count($feed) >= 8) {
            break;
        }
    }
} catch (Throwable $ex) {
    $feed = [];
}

// ---------- Queues ----------
$pending_requests = can('requests.manage') ? (int) $db->query(
    "SELECT COUNT(*) FROM monthly_requests WHERE status NOT IN ('approved','rejected','cancelled','withdrawn')"
)->fetchColumn() : 0;
$new_leads = can('leads.manage') ? (int) $db->query("SELECT COUNT(*) FROM valet_leads WHERE stage = 'new'")->fetchColumn() : 0;

function mv_rel_time(string $dt): string
{
    $ts = strtotime($dt);
    if (!$ts) {
        return $dt;
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }
    if ($diff < 86400 * 7) {
        return floor($diff / 86400) . 'd ago';
    }
    return date('M j', $ts);
}

require __DIR__ . '/_layout.php';
?>
<div class="cmd">

    <!-- ============ EXECUTIVE HEADER ============ -->
    <section class="cmd-hero" aria-label="Overview">
        <div>
            <div class="cmd-hero-eyebrow">Parking Operations Command Center</div>
            <h1><?= e($greeting) ?>, <?= e($first_name) ?></h1>
            <div class="cmd-hero-date"><?= e(date('l, F j, Y')) ?> &middot; <?= e($loc_name) ?></div>
        </div>
        <form method="get" action="<?= e(site_url('admin/index.php')) ?>" class="cmd-hero-controls">
            <label class="sr-only" for="cmd_loc">Location filter</label>
            <select id="cmd_loc" name="location" class="cmd-select" onchange="this.form.submit()">
                <option value="0">All locations</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= (int) $l['id'] ?>"<?= $filter_location === (int) $l['id'] ? ' selected' : '' ?>>
                        <?= e($l['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($pending_requests > 0): ?>
                <a class="mini-btn gold" href="<?= e(site_url('admin/requests.php')) ?>">
                    <?= $pending_requests ?> request<?= $pending_requests === 1 ? '' : 's' ?> pending
                </a>
            <?php endif; ?>
        </form>
    </section>

    <!-- ============ SMART SEARCH ============ -->
    <section class="cmd-search" aria-label="Smart search">
        <svg class="cmd-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" id="cmdSearch" class="cmd-search-input"
               placeholder="Search plates, parker names, companies, invoice numbers…"
               autocomplete="off" aria-label="Search plates, parkers, companies, invoices">
        <div class="cmd-search-results" id="cmdSearchResults" hidden role="listbox" aria-label="Search results"></div>
    </section>

    <!-- ============ KPI ROW ============ -->
    <section class="cmd-kpis" aria-label="Key metrics">
        <div class="kpi-card" style="--kpi-accent:#2b6cb0;--kpi-soft:#e9f1fb">
            <div class="kpi-top">
                <span class="kpi-label">Active Monthly Parkers</span>
                <span class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
            </div>
            <div class="kpi-value"><?= number_format($active_parkers) ?></div>
            <div class="kpi-sub"><strong><?= $parkers_ind ?></strong> individual &middot; <strong><?= $parkers_corp ?></strong> corporate &middot; <a href="<?= e(site_url('admin/parkers.php')) ?>">View all</a></div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#1e8e5a;--kpi-soft:#e6f5ec">
            <div class="kpi-top">
                <span class="kpi-label">Revenue Collected · <?= e(date('M Y')) ?></span>
                <span class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
            </div>
            <div class="kpi-value"><?= e(money($revenue_month_cents)) ?></div>
            <div class="kpi-sub">Actual payments recorded &middot; <a href="<?= e(site_url('admin/reports.php')) ?>">Reports</a></div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#b7791f;--kpi-soft:#fdf3e0">
            <div class="kpi-top">
                <span class="kpi-label">Outstanding Payments</span>
                <span class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
            </div>
            <div class="kpi-value"><?= e(money($outstanding_cents)) ?></div>
            <div class="kpi-sub"><strong><?= $outstanding_count ?></strong> open invoice<?= $outstanding_count === 1 ? '' : 's' ?> &middot; <a href="<?= e(site_url('admin/invoices.php?status=unpaid')) ?>">Collect</a></div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#c93a3a;--kpi-soft:#fdeeee">
            <div class="kpi-top">
                <span class="kpi-label">Expired Parkers</span>
                <span class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg></span>
            </div>
            <div class="kpi-value"><?= number_format($expired) ?></div>
            <div class="kpi-sub"><strong><?= $exp7 ?></strong> expiring ≤ 7 days &middot; <a href="#renewals">Review renewals</a></div>
        </div>
    </section>

    <!-- ============ REVENUE + RENEWALS ============ -->
    <section class="cmd-grid-2">
        <div class="cmd-card">
            <div class="cmd-card-head">
                <h2 class="cmd-card-title"><span class="dot green"></span>Revenue Analytics</h2>
                <div class="seg" role="tablist" aria-label="Revenue period">
                    <button type="button" data-rev="monthly" class="active" role="tab" aria-selected="true">Monthly</button>
                    <button type="button" data-rev="quarterly" role="tab" aria-selected="false">Quarterly</button>
                    <button type="button" data-rev="yearly" role="tab" aria-selected="false">Yearly</button>
                </div>
            </div>
            <div class="cmd-card-body">
                <div class="rev-total">
                    <span class="rev-total-amount" id="revTotal"><?= e(money((int) round($rev_total_12m * 100))) ?></span>
                    <span class="rev-total-label" id="revTotalLabel">collected · last 12 months · <?= e($loc_name) ?></span>
                </div>
                <div class="rev-chart-wrap">
                    <svg class="rev-chart" id="revChart" viewBox="0 0 640 260" role="img" aria-label="Revenue chart"></svg>
                </div>
                <?php if ($top_locs !== []): ?>
                    <div class="rev-legend" style="margin-top:14px;flex-direction:column;gap:8px;align-items:stretch">
                        <div style="font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--mv-muted)">Top locations · last 12 months</div>
                        <?php foreach ($top_locs as $tl): ?>
                            <div style="display:flex;justify-content:space-between;font-size:13px">
                                <span style="color:var(--mv-ink)"><?= e(mb_strimwidth($tl['name'], 0, 30, '…')) ?></span>
                                <strong style="color:var(--mv-navy)"><?= e(money((int) $tl['c'])) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cmd-card" id="renewals">
            <div class="cmd-card-head">
                <h2 class="cmd-card-title"><span class="dot red"></span>Renewal Intelligence</h2>
                <a class="cmd-card-link" href="<?= e(site_url('admin/renewals.php')) ?>">Open renewals →</a>
            </div>
            <div class="cmd-card-body">
                <div class="pri-tabs" role="tablist" aria-label="Renewal priority" style="margin-bottom:12px">
                    <button type="button" class="pri-tab crit active" data-pri="expired" role="tab" aria-selected="true">
                        Expired <span class="cnt"><?= count($rows_expired) ?></span>
                    </button>
                    <button type="button" class="pri-tab warn" data-pri="d3" role="tab" aria-selected="false">
                        ≤ 3 days <span class="cnt"><?= count($rows_3) ?></span>
                    </button>
                    <button type="button" class="pri-tab" data-pri="d7" role="tab" aria-selected="false">
                        ≤ 7 days <span class="cnt"><?= count($rows_7) ?></span>
                    </button>
                </div>
                <div id="priPanels">
                    <?php
                    $pri_sets = ['expired' => $rows_expired, 'd3' => $rows_3, 'd7' => $rows_7];
                    foreach ($pri_sets as $pkey => $prows):
                    ?>
                    <div data-pri-panel="<?= $pkey ?>"<?= $pkey !== 'expired' ? ' hidden' : '' ?>>
                        <?php if ($prows === []): ?>
                            <div class="cmd-empty">
                                <div class="cmd-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg></div>
                                <strong>All clear</strong>
                                <p>No subscriptions in this bucket<?= $filter_location > 0 ? ' for ' . e($loc_name) : '' ?>.</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x:auto">
                            <table class="renew-table">
                                <thead><tr>
                                    <th>Plate</th><th>Parker</th><th>Expires</th><th>Countdown</th><th>Payment</th><th>Reminder</th><th>Actions</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach (array_slice($prows, 0, 8) as $r):
                                    $days = (int) floor((strtotime($r['end_date']) - strtotime($today)) / 86400);
                                    $who = $r['company_name'] ?: $r['customer_name'];
                                    $cd_class = $days < 0 ? 'crit' : ($days <= 3 ? 'crit' : ($days <= 7 ? 'warn' : 'ok'));
                                    $cd_text = $days < 0 ? abs($days) . 'd overdue' : ($days === 0 ? 'today' : $days . 'd left');
                                    $inv = $r['inv_status'];
                                    $inv_pill = $inv === 'paid' ? '<span class="pill green">Paid</span>'
                                        : ($inv === 'partially_paid' ? '<span class="pill amber">Partial</span>'
                                        : ($inv === 'overdue' ? '<span class="pill red">Overdue</span>'
                                        : ($inv ? '<span class="pill amber">Unpaid</span>' : '<span class="pill gray">No invoice</span>')));
                                    $rem = $r['last_reminder']
                                        ? '<span class="pill blue" title="' . e($r['last_reminder']) . '">Sent</span>'
                                        : '<span class="pill gray">Not sent</span>';
                                ?>
                                    <tr>
                                        <td data-label="Plate"><span class="plate-chip"><?= e($r['plate'] ?: '—') ?></span></td>
                                        <td data-label="Parker">
                                            <div style="font-weight:600"><?= e(mb_strimwidth($who ?: '—', 0, 24, '…')) ?></div>
                                            <div style="font-size:11.5px;color:var(--mv-muted)"><?= e(mb_strimwidth($r['location_name'] ?: '', 0, 26, '…')) ?></div>
                                        </td>
                                        <td data-label="Expires" style="white-space:nowrap"><?= e(date('M j, Y', strtotime($r['end_date']))) ?></td>
                                        <td data-label="Countdown"><span class="countdown <?= $cd_class ?>"><?= e($cd_text) ?></span></td>
                                        <td data-label="Payment"><?= $inv_pill ?></td>
                                        <td data-label="Reminder"><?= $rem ?></td>
                                        <td data-label="Actions">
                                            <div class="row-actions">
                                                <a class="mini-btn" href="<?= e(site_url('admin/parker_form.php?subscription_id=' . (int) $r['id'])) ?>">View</a>
                                                <?php if ($can_renew): ?>
                                                    <a class="mini-btn primary" href="<?= e(site_url('admin/invoices.php?subscription_id=' . (int) $r['id'])) ?>">Bill</a>
                                                <?php endif; ?>
                                                <?php if ($can_remind): ?>
                                                    <form method="post" action="<?= e(site_url('admin/renewals.php')) ?>" style="display:inline">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="resend" value="1">
                                                        <input type="hidden" name="subscription_id" value="<?= (int) $r['id'] ?>">
                                                        <button type="submit" class="mini-btn" title="Send reminder email">Remind</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                            <?php if (count($prows) > 8): ?>
                                <div style="padding-top:10px"><a class="cmd-card-link" href="<?= e(site_url('admin/renewals.php')) ?>">View all <?= count($prows) ?> →</a></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ LOCATIONS + FINANCIAL / QUICK ACTIONS ============ -->
    <section class="cmd-grid-2">
        <div class="cmd-card">
            <div class="cmd-card-head">
                <h2 class="cmd-card-title"><span class="dot blue"></span>Parking Locations</h2>
                <a class="cmd-card-link" href="<?= e(site_url('admin/locations.php')) ?>">Manage →</a>
            </div>
            <div class="cmd-card-body">
                <?php if ($loc_cards === []): ?>
                    <div class="cmd-empty">
                        <div class="cmd-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></div>
                        <strong>No locations yet</strong><p>Add your first parking location to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="loc-grid" style="grid-template-columns:1fr">
                        <?php foreach (array_slice($loc_cards, 0, 4) as $lc):
                            $occ = ($lc['capacity'] !== null && $lc['capacity'] > 0) ? min(100, (int) round($lc['parkers'] / $lc['capacity'] * 100)) : null;
                        ?>
                            <div class="loc-card">
                                <div class="loc-card-top">
                                    <div>
                                        <h3 class="loc-name"><?= e($lc['name']) ?></h3>
                                        <?php if ($lc['address']): ?><div class="loc-addr"><?= e($lc['address']) ?></div><?php endif; ?>
                                    </div>
                                    <a class="mini-btn" href="<?= e(site_url('admin/parkers.php?location=' . $lc['id'] . '&category=all')) ?>">View</a>
                                </div>
                                <div class="loc-stats">
                                    <div><div class="loc-stat-num"><?= $lc['parkers'] ?></div><div class="loc-stat-lbl">Parkers</div></div>
                                    <div><div class="loc-stat-num"><?= e(money($lc['revenue'])) ?></div><div class="loc-stat-lbl">Revenue 12m</div></div>
                                    <div><div class="loc-stat-num" style="<?= $lc['outstanding'] > 0 ? 'color:var(--mv-red)' : '' ?>"><?= e(money($lc['outstanding'])) ?></div><div class="loc-stat-lbl">Outstanding</div></div>
                                </div>
                                <?php if ($occ !== null): ?>
                                    <div class="occupancy">
                                        <div class="occupancy-bar"><div class="occupancy-fill" style="width:<?= $occ ?>%"></div></div>
                                        <div class="occupancy-lbl"><span>Occupancy</span><span><?= $occ ?>% · <?= $lc['parkers'] ?>/<?= $lc['capacity'] ?> spaces</span></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($loc_cards) > 4): ?>
                        <div style="padding-top:12px"><a class="cmd-card-link" href="<?= e(site_url('admin/locations.php')) ?>">View all <?= count($loc_cards) ?> locations →</a></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:16px">
            <div class="cmd-card">
                <div class="cmd-card-head">
                    <h2 class="cmd-card-title"><span class="dot"></span>Financial Overview</h2>
                    <a class="cmd-card-link" href="<?= e(site_url('admin/invoices.php')) ?>">Invoices →</a>
                </div>
                <div class="cmd-card-body">
                    <?php if ($fin === []): ?>
                        <div class="cmd-empty"><strong>No invoice access</strong><p>Your role does not include invoice permissions.</p></div>
                    <?php else: ?>
                        <div class="fin-strip" style="grid-template-columns:repeat(2,1fr)">
                            <?php
                            $fin_styles = [
                                'paid' => '--fin-bg:#e6f5ec;--fin-ink:#1e8e5a',
                                'unpaid' => '--fin-bg:#fdf3e0;--fin-ink:#b7791f',
                                'partially_paid' => '--fin-bg:#e9f1fb;--fin-ink:#2b6cb0',
                                'overdue' => '--fin-bg:#fdeeee;--fin-ink:#c93a3a',
                                'pending' => '--fin-bg:#eef2f7;--fin-ink:#3d4f68',
                            ];
                            foreach ($fin as $f):
                            ?>
                                <a class="fin-cell" style="<?= e($fin_styles[$f['status']] ?? '') ?>" href="<?= e(site_url('admin/invoices.php?status=' . $f['status'])) ?>">
                                    <div class="fin-cell-num"><?= $f['count'] ?></div>
                                    <div class="fin-cell-lbl"><?= e($f['label']) ?></div>
                                    <?php if ($f['amount_cents'] > 0 && $f['status'] !== 'paid'): ?>
                                        <div class="fin-cell-sub"><?= e(money($f['amount_cents'])) ?> open</div>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="cmd-card">
                <div class="cmd-card-head">
                    <h2 class="cmd-card-title"><span class="dot"></span>Quick Actions</h2>
                </div>
                <div class="cmd-card-body">
                    <div class="qa-grid" style="grid-template-columns:repeat(2,1fr)">
                        <?php if (can('parkers.edit')): ?>
                            <a class="qa-btn" href="<?= e(site_url('admin/parker_form.php')) ?>"><span class="qa-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg></span>Register parker</a>
                        <?php endif; ?>
                        <?php if (can('invoices.edit')): ?>
                            <a class="qa-btn" href="<?= e(site_url('admin/invoices.php?action=new')) ?>"><span class="qa-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg></span>Create invoice</a>
                        <?php endif; ?>
                        <?php if (can('payments.record')): ?>
                            <a class="qa-btn" href="<?= e(site_url('admin/payments.php')) ?>"><span class="qa-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>Record payment</a>
                        <?php endif; ?>
                        <?php if (can('locations.edit')): ?>
                            <a class="qa-btn" href="<?= e(site_url('admin/location_form.php')) ?>"><span class="qa-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span>Add location</a>
                        <?php endif; ?>
                        <?php if (can('reminders.send')): ?>
                            <a class="qa-btn" href="<?= e(site_url('admin/reminders.php')) ?>"><span class="qa-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg></span>Send reminder</a>
                        <?php endif; ?>
                        <?php if (can('plates.lookup')): ?>
                            <a class="qa-btn" href="<?= e(site_url('admin/plate_lookup.php')) ?>"><span class="qa-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></span>Plate lookup</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ ACTIVITY ============ -->
    <section class="cmd-card">
        <div class="cmd-card-head">
            <h2 class="cmd-card-title"><span class="dot blue"></span>Recent Activity</h2>
            <?php if (can('audit.view')): ?><a class="cmd-card-link" href="<?= e(site_url('admin/audit.php')) ?>">Audit log →</a><?php endif; ?>
        </div>
        <div class="cmd-card-body">
            <?php if ($feed === []): ?>
                <div class="cmd-empty">
                    <div class="cmd-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/></svg></div>
                    <strong>No activity yet</strong><p>Registrations, payments, and renewals will appear here.</p>
                </div>
            <?php else: ?>
                <div class="feed">
                    <?php
                    $tone_bg = ['green' => 'var(--mv-green-bg);color:var(--mv-green)', 'blue' => 'var(--mv-blue-bg);color:var(--mv-blue)', 'amber' => 'var(--mv-amber-bg);color:var(--mv-amber)', 'red' => 'var(--mv-red-bg);color:var(--mv-red)', 'gray' => 'var(--mv-line-soft);color:var(--mv-muted)'];
                    foreach ($feed as $fi):
                    ?>
                        <div class="feed-item">
                            <div class="feed-icon" style="background:<?= $tone_bg[$fi['tone']] ?? $tone_bg['gray'] ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8Z"/></svg>
                            </div>
                            <div class="feed-body">
                                <div class="feed-text"><strong><?= e($fi['label']) ?></strong><?php if ($fi['detail'] !== ''): ?> <span style="color:var(--mv-muted)">· <?= e(mb_strimwidth($fi['detail'], 0, 90, '…')) ?></span><?php endif; ?></div>
                                <div class="feed-time"><?= e(mv_rel_time($fi['at'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
(function () {
    'use strict';

    /* ---------- Revenue chart ---------- */
    var revData = {
        monthly: <?= json_encode($rev_monthly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        quarterly: <?= json_encode($rev_quarterly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        yearly: <?= json_encode($rev_yearly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
    var revLabels = {
        monthly: 'collected · last 12 months',
        quarterly: 'collected · last 8 quarters',
        yearly: 'collected · last 5 years'
    };
    var chart = document.getElementById('revChart');
    var totalEl = document.getElementById('revTotal');
    var totalLabel = document.getElementById('revTotalLabel');
    var locName = <?= json_encode($loc_name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function moneyFmt(v) {
        return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function drawRev(key) {
        if (!chart) return;
        var data = revData[key] || [];
        var W = 640, H = 260, padL = 56, padR = 12, padT = 16, padB = 40;
        var max = Math.max.apply(null, data.map(function (d) { return d.value; }).concat([1]));
        var n = data.length;
        var slot = (W - padL - padR) / Math.max(n, 1);
        var bw = Math.max(14, Math.min(56, slot * 0.58));
        var base = H - padB, plotH = base - padT;

        var s = '';
        for (var g = 0; g <= 4; g++) {
            var gy = padT + plotH * g / 4;
            var gv = max * (1 - g / 4);
            s += '<line x1="' + padL + '" y1="' + gy + '" x2="' + (W - padR) + '" y2="' + gy + '" stroke="#eef2f7" stroke-width="1"/>';
            s += '<text x="' + (padL - 8) + '" y="' + (gy + 4) + '" text-anchor="end" font-size="10.5" fill="#6b7d97">$' + (gv >= 1000 ? (gv / 1000).toFixed(1) + 'k' : Math.round(gv)) + '</text>';
        }
        data.forEach(function (d, i) {
            var cx = padL + slot * i + slot / 2;
            var bh = plotH * (d.value / max);
            var y = base - bh;
            var grad = d.value === max ? '#D7AD49' : '#0D2038';
            s += '<g><title>' + d.full + ': ' + moneyFmt(d.value) + '</title>';
            s += '<rect x="' + (cx - bw / 2) + '" y="' + y + '" width="' + bw + '" height="' + Math.max(bh, 2) + '" rx="5" fill="' + grad + '" opacity="' + (d.value === max ? '1' : '0.88') + '"/>';
            s += '<text x="' + cx + '" y="' + (base + 20) + '" text-anchor="middle" font-size="11" fill="#6b7d97" font-weight="600">' + d.label + '</text>';
            s += '</g>';
        });
        chart.innerHTML = s;

        var total = data.reduce(function (a, d) { return a + d.value; }, 0);
        if (totalEl) totalEl.textContent = moneyFmt(total);
        if (totalLabel) totalLabel.textContent = revLabels[key] + ' · ' + locName;
    }

    document.querySelectorAll('[data-rev]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-rev]').forEach(function (b) {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');
            drawRev(btn.getAttribute('data-rev'));
        });
    });
    drawRev('monthly');

    /* ---------- Renewal priority tabs ---------- */
    document.querySelectorAll('[data-pri]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var key = tab.getAttribute('data-pri');
            document.querySelectorAll('[data-pri]').forEach(function (t) {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            document.querySelectorAll('[data-pri-panel]').forEach(function (p) {
                p.hidden = p.getAttribute('data-pri-panel') !== key;
            });
        });
    });

    /* ---------- Smart search ---------- */
    var input = document.getElementById('cmdSearch');
    var results = document.getElementById('cmdSearchResults');
    var timer = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function renderResults(items) {
        if (!items || items.length === 0) {
            results.innerHTML = '<div class="search-empty">No matches found.</div>';
        } else {
            results.innerHTML = items.map(function (it) {
                return '<a class="search-result" role="option" href="' + esc(it.url) + '">' +
                    '<span class="search-result-type">' + esc(it.type) + '</span>' +
                    '<span><strong>' + esc(it.title) + '</strong>' +
                    (it.sub ? '<br><span style="color:var(--mv-muted);font-size:12px">' + esc(it.sub) + '</span>' : '') +
                    '</span></a>';
            }).join('');
        }
        results.hidden = false;
    }

    if (input) {
        input.addEventListener('input', function () {
            var qv = input.value.trim();
            clearTimeout(timer);
            if (qv.length < 2) {
                results.hidden = true;
                return;
            }
            timer = setTimeout(function () {
                results.innerHTML = '<div class="search-empty"><div class="skeleton" style="height:14px;margin:4px 0"></div><div class="skeleton" style="height:14px;margin:4px 0"></div></div>';
                results.hidden = false;
                fetch(<?= json_encode(site_url('admin/api_search.php'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + '?q=' + encodeURIComponent(qv), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { renderResults(data.results || []); })
                    .catch(function () { results.innerHTML = '<div class="search-empty">Search failed — try again.</div>'; });
            }, 280);
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.cmd-search')) results.hidden = true;
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') results.hidden = true;
        });
    }
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
