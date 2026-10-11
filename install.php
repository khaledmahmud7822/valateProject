<?php
// install.php — one-time setup wizard: creates the schema, seeds roles,
// templates and settings, and creates the super-admin account.
// DELETE THIS FILE after installation (a self-delete button is provided).
require __DIR__ . '/lib/bootstrap.php';

/** Full schema. Columns added later by lib/bootstrap.php migrations are included here too. */
function mv_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY, value TEXT
        )",
        "CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL,
            label TEXT NOT NULL, permissions TEXT NOT NULL DEFAULT '[]'
        )",
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL,
            role_id INTEGER NOT NULL REFERENCES roles(id),
            location_id INTEGER, mfa_secret TEXT,
            active INTEGER NOT NULL DEFAULT 1, last_login_at TEXT,
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id),
            token_hash TEXT UNIQUE NOT NULL, expires_at TEXT NOT NULL,
            used INTEGER NOT NULL DEFAULT 0, created_at TEXT
        )",
        "CREATE TABLE IF NOT EXISTS parking_locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
            address TEXT, city TEXT, state TEXT, zip TEXT, type TEXT, services TEXT,
            monthly_available INTEGER NOT NULL DEFAULT 1,
            accepts_requests INTEGER NOT NULL DEFAULT 1,
            is_published INTEGER NOT NULL DEFAULT 1,
            needs_review INTEGER NOT NULL DEFAULT 0,
            capacity INTEGER, monthly_rate_cents INTEGER, hours TEXT, description TEXT,
            lat REAL, lng REAL, created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS location_photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            location_id INTEGER NOT NULL REFERENCES parking_locations(id) ON DELETE CASCADE,
            path TEXT NOT NULL, caption TEXT, sort INTEGER NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT 'individual',
            name TEXT NOT NULL, contact_name TEXT, email TEXT, phone TEXT, password_hash TEXT,
            location_id INTEGER REFERENCES parking_locations(id),
            address TEXT, city TEXT, state TEXT, zip TEXT,
            status TEXT NOT NULL DEFAULT 'active', notes TEXT, created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS companies (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, contact_name TEXT,
            billing_contact TEXT, billing_email TEXT, billing_phone TEXT, billing_address TEXT,
            phone TEXT, email TEXT, location_id INTEGER REFERENCES parking_locations(id),
            spaces INTEGER, rate_cents INTEGER, contract_start TEXT, contract_end TEXT,
            status TEXT NOT NULL DEFAULT 'active', notes TEXT, deleted_at TEXT,
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS company_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id),
            name TEXT NOT NULL, email TEXT, phone TEXT, role TEXT, password_hash TEXT,
            created_at TEXT
        )",
        "CREATE TABLE IF NOT EXISTS vehicles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER REFERENCES customers(id),
            company_id INTEGER REFERENCES companies(id),
            make TEXT, model TEXT, plate TEXT, plate_state TEXT, plate_norm TEXT, color TEXT,
            tag_number TEXT, driver_name TEXT, driver_email TEXT, driver_phone TEXT,
            created_at TEXT NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_vehicles_plate ON vehicles(plate_norm)",
        "CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER REFERENCES customers(id),
            company_id INTEGER REFERENCES companies(id),
            location_id INTEGER REFERENCES parking_locations(id),
            vehicle_id INTEGER REFERENCES vehicles(id),
            start_date TEXT, end_date TEXT, monthly_rate_cents INTEGER,
            status TEXT NOT NULL DEFAULT 'active', auto_renew INTEGER NOT NULL DEFAULT 0,
            hours_allowed TEXT, spaces INTEGER, category TEXT NOT NULL DEFAULT 'monthly',
            notes TEXT, deleted_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_subs_end ON subscriptions(end_date)",
        "CREATE TABLE IF NOT EXISTS permits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            vehicle_id INTEGER REFERENCES vehicles(id),
            customer_id INTEGER REFERENCES customers(id),
            company_id INTEGER REFERENCES companies(id),
            location_id INTEGER REFERENCES parking_locations(id),
            start_date TEXT, end_date TEXT, status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT, number TEXT UNIQUE NOT NULL,
            subscription_id INTEGER REFERENCES subscriptions(id),
            company_id INTEGER REFERENCES companies(id),
            customer_id INTEGER REFERENCES customers(id),
            bill_name TEXT, bill_email TEXT, issue_date TEXT, due_date TEXT,
            amount_cents INTEGER NOT NULL DEFAULT 0, amount_paid_cents INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'unpaid', payment_token TEXT UNIQUE,
            created_by INTEGER, notes TEXT, stripe_session_id TEXT,
            extend_months INTEGER NOT NULL DEFAULT 0, extended_at TEXT, deleted_at TEXT,
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS invoice_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
            description TEXT NOT NULL, qty INTEGER NOT NULL DEFAULT 1,
            unit_cents INTEGER NOT NULL DEFAULT 0, amount_cents INTEGER NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL REFERENCES invoices(id),
            amount_cents INTEGER NOT NULL, method TEXT, reference TEXT, paid_at TEXT,
            recorded_by INTEGER, recorded_by_customer INTEGER, notes TEXT,
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS monthly_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ref_no TEXT UNIQUE NOT NULL,
            type TEXT NOT NULL DEFAULT 'individual', email TEXT, phone TEXT,
            location_id INTEGER REFERENCES parking_locations(id), start_date TEXT, comments TEXT,
            full_name TEXT, vehicle_make TEXT, vehicle_model TEXT, plate TEXT, plate_state TEXT,
            contact_method TEXT, company_name TEXT, contact_person TEXT, spaces INTEGER,
            billing_contact TEXT, billing_email TEXT,
            status TEXT NOT NULL DEFAULT 'new', admin_notes TEXT,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS valet_leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ref_no TEXT UNIQUE NOT NULL,
            business_name TEXT, contact_person TEXT, email TEXT, phone TEXT, business_type TEXT,
            property_address TEXT, service_type TEXT, volume TEXT, start_date TEXT,
            requirements TEXT, stage TEXT NOT NULL DEFAULT 'new', notes TEXT,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS notification_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT UNIQUE NOT NULL,
            subject TEXT NOT NULL, body TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT
        )",
        "CREATE TABLE IF NOT EXISTS notification_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, template_key TEXT, recipient TEXT,
            subject TEXT, body_hash TEXT, status TEXT, dedupe_key TEXT, error TEXT, sent_at TEXT
        )",
        "CREATE INDEX IF NOT EXISTS idx_notif_dedupe ON notification_logs(dedupe_key)",
        "CREATE TABLE IF NOT EXISTS activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, actor_type TEXT,
            action TEXT NOT NULL, entity TEXT, entity_id INTEGER, details TEXT, ip TEXT,
            created_at TEXT NOT NULL
        )",
    ];
}

/** Default email templates (subject, HTML body). {{vars}} are filled by send_template(). */
function mv_templates(): array {
    return [
        'renewal_7' => ['Your {{business_name}} parking renews in 7 days',
            '<p>Hi {{customer_name}},</p><p>Your monthly parking at <strong>{{location_name}}</strong> (plate {{plate}}) ends on <strong>{{end_date}}</strong> — {{days}} days from now.</p><p>To keep your space, please renew before then.</p><p>{{business_name}}</p>'],
        'renewal_3' => ['Reminder: your {{business_name}} parking ends in 3 days',
            '<p>Hi {{customer_name}},</p><p>Your monthly parking at <strong>{{location_name}}</strong> (plate {{plate}}) ends on <strong>{{end_date}}</strong>.</p><p>Please renew soon to keep your space.</p><p>{{business_name}}</p>'],
        'expired_notice' => ['Your {{business_name}} parking has expired',
            '<p>Hi {{customer_name}},</p><p>Your monthly parking at <strong>{{location_name}}</strong> (plate {{plate}}) expired on <strong>{{end_date}}</strong>.</p><p>Contact us to renew.</p><p>{{business_name}}</p>'],
        'invoice_issued' => ['Invoice {{number}} from {{business_name}}',
            '<p>Hi {{customer_name}},</p><p>Invoice <strong>{{number}}</strong> for <strong>{{amount}}</strong> is due on {{due_date}}.</p><p><a href="{{pay_url}}">View and pay your invoice</a></p><p>{{business_name}}</p>'],
        'payment_received' => ['Payment received — invoice {{number}}',
            '<p>Hi {{customer_name}},</p><p>We received your payment of <strong>{{amount}}</strong> for invoice {{number}}. Thank you!</p><p>{{business_name}}</p>'],
        'payment_failed' => ['Payment problem — invoice {{number}}',
            '<p>Hi {{customer_name}},</p><p>Your payment for invoice {{number}} could not be completed. {{reason}}</p><p><a href="{{pay_url}}">Try again</a></p><p>{{business_name}}</p>'],
        'request_received' => ['We received your monthly parking request ({{ref_no}})',
            '<p>Hi {{name}},</p><p>Thanks for your request. Your reference number is <strong>{{ref_no}}</strong>. Our team will contact you shortly.</p><p>{{business_name}}</p>'],
        'request_approved' => ['Your monthly parking request is approved',
            '<p>Hi {{name}},</p><p>Your request {{ref_no}} has been approved.</p><p>Portal login: <a href="{{login_url}}">{{login_url}}</a><br>Temporary password: <strong>{{temp_password}}</strong> (please change it after signing in)</p><p>{{business_name}}</p>'],
        'request_rejected' => ['Update on your monthly parking request',
            '<p>Hi {{name}},</p><p>Unfortunately we cannot accommodate request {{ref_no}} at this time. {{reason}}</p><p>{{business_name}}</p>'],
        'lead_received' => ['We received your valet inquiry ({{ref_no}})',
            '<p>Hi {{name}},</p><p>Thanks for contacting {{business_name}} about {{service_type}}. Your reference number is <strong>{{ref_no}}</strong>. We will be in touch soon.</p>'],
        'vehicle_change_received' => ['Vehicle change request received',
            '<p>Hi {{customer_name}},</p><p>We received your request to update your vehicle to {{new_plate}} ({{new_plate_state}}). Our team will confirm shortly.</p><p>{{business_name}}</p>'],
    ];
}

function mv_installed(): bool {
    try {
        return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Clearly-labeled demo data for previews. All names start with "Demo". */
function mv_seed_demo(PDO $db, int $adminId): void {
    $now = date('Y-m-d H:i:s');
    $locs = [
        ['Demo Garage — Downtown', 'Garage'], ['Demo Surface Lot — Midtown', 'Surface Lot'],
        ['Demo Hotel Valet — Riverfront', 'Hotel'],
    ];
    $locIds = [];
    foreach ($locs as [$name, $type]) {
        $db->prepare('INSERT INTO parking_locations (name, address, city, state, zip, type, services, monthly_available, accepts_requests, is_published, needs_review, capacity, monthly_rate_cents, hours, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 0, NULL, NULL, ?, ?, ?)')
            ->execute([$name, '(demo address)', 'Detroit', 'MI', '', $type, 'Monthly parking, Valet', '24/7', 'Demo location — replace with a real one before going live.', $now]);
        $locIds[] = (int) $db->lastInsertId();
    }
    $people = [['Alice', 6], ['Bob', 2], ['Carol', -5], ['Dan', 7], ['Erin', 3], ['Frank', 30]];
    foreach ($people as $i => [$first, $days]) {
        $email = 'demo.' . strtolower($first) . '@example.com';
        $loc = $locIds[$i % count($locIds)];
        $db->prepare("INSERT INTO customers (type, name, contact_name, email, phone, password_hash, location_id, notes, created_at) VALUES ('individual', ?, ?, ?, ?, ?, ?, 'Demo customer', ?)")
            ->execute(['Demo ' . $first, 'Demo ' . $first, $email, '555-0100', password_hash('Demo-Pass-123', PASSWORD_DEFAULT), $loc, $now]);
        $cid = (int) $db->lastInsertId();
        $plate = 'DEMO' . ($i + 1);
        $db->prepare("INSERT INTO vehicles (customer_id, make, model, plate, plate_state, plate_norm, color, created_at) VALUES (?, 'Demo', 'Sedan', ?, 'MI', ?, 'Gray', ?)")
            ->execute([$cid, $plate, normalize_plate($plate), $now]);
        $vid = (int) $db->lastInsertId();
        $end = date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
        $start = date('Y-m-d', strtotime($end . ' -1 month'));
        $status = $days < 0 ? 'expired' : 'active';
        $db->prepare("INSERT INTO subscriptions (customer_id, location_id, vehicle_id, start_date, end_date, monthly_rate_cents, status, auto_renew, category, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 15000, ?, 0, 'monthly', 'Demo', ?, ?)")
            ->execute([$cid, $loc, $vid, $start, $end, $status, $now, $now]);
        $sid = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO permits (vehicle_id, customer_id, location_id, start_date, end_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$vid, $cid, $loc, $start, $end, $status, $now]);
        if ($i < 3) {
            $db->prepare("INSERT INTO invoices (number, subscription_id, customer_id, issue_date, due_date, amount_cents, amount_paid_cents, status, payment_token, created_by, notes, created_at) VALUES (?, ?, ?, ?, ?, 15000, 0, 'unpaid', ?, ?, 'Demo invoice', ?)")
                ->execute([new_invoice_number(), $sid, $cid, today(), date('Y-m-d', strtotime('+14 days')), secure_token(), $adminId, $now]);
            $iid = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO invoice_items (invoice_id, description, qty, unit_cents, amount_cents) VALUES (?, 'Monthly parking (demo)', 1, 15000, 15000)")->execute([$iid]);
            $pay = [0, 5000, 15000][$i];
            if ($pay > 0) {
                $db->prepare("INSERT INTO payments (invoice_id, amount_cents, method, reference, paid_at, recorded_by, notes, created_at) VALUES (?, ?, 'cash', 'DEMO', ?, ?, 'Demo payment', ?)")
                    ->execute([$iid, $pay, $now, $adminId, $now]);
            }
            recalc_invoice($iid);
        }
    }
    // Two demo companies; the first has 3 vehicles and a consolidated invoice.
    foreach (['Demo Company One', 'Demo Company Two'] as $ci => $cname) {
        $db->prepare("INSERT INTO companies (name, contact_name, billing_contact, billing_email, phone, email, location_id, spaces, rate_cents, contract_start, contract_end, status, notes, created_at) VALUES (?, 'Demo Contact', 'Demo Billing', ?, '555-0199', ?, ?, 3, 14000, ?, ?, 'active', 'Demo company', ?)")
            ->execute([$cname, 'billing' . ($ci + 1) . '@example.com', 'company' . ($ci + 1) . '@example.com', $locIds[0], today(), date('Y-m-d', strtotime('+1 year')), $now]);
        $coId = (int) $db->lastInsertId();
        if ($ci === 0) {
            $total = 0;
            for ($v = 1; $v <= 3; $v++) {
                $plate = 'DCO' . $v;
                $db->prepare("INSERT INTO vehicles (company_id, make, model, plate, plate_state, plate_norm, color, created_at) VALUES (?, 'Demo', 'Van', ?, 'MI', ?, 'White', ?)")
                    ->execute([$coId, $plate, normalize_plate($plate), $now]);
                $vid = (int) $db->lastInsertId();
                $db->prepare("INSERT INTO subscriptions (company_id, location_id, vehicle_id, start_date, end_date, monthly_rate_cents, status, auto_renew, category, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 14000, 'active', 1, 'monthly', 'Demo', ?, ?)")
                    ->execute([$coId, $locIds[0], $vid, today(), date('Y-m-d', strtotime('+1 month')), $now, $now]);
                $db->prepare("INSERT INTO permits (vehicle_id, company_id, location_id, start_date, end_date, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', ?)")
                    ->execute([$vid, $coId, $locIds[0], today(), date('Y-m-d', strtotime('+1 month')), $now]);
                $total += 14000;
            }
            $db->prepare("INSERT INTO invoices (number, company_id, issue_date, due_date, amount_cents, amount_paid_cents, status, payment_token, created_by, notes, created_at) VALUES (?, ?, ?, ?, ?, 0, 'unpaid', ?, ?, 'Demo consolidated invoice', ?)")
                ->execute([new_invoice_number(), $coId, today(), date('Y-m-d', strtotime('+30 days')), $total, secure_token(), $adminId, $now]);
            $iid = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO invoice_items (invoice_id, description, qty, unit_cents, amount_cents) VALUES (?, 'Monthly parking — 3 vehicles (demo)', 3, 14000, ?)")->execute([$iid, $total]);
        }
    }
}

$installed = mv_installed();
$force = isset($_GET['force']);
$errors = [];
$done = null;

// Self-delete after a successful install.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_self') {
    verify_csrf();
    if ($installed) {
        @unlink(__FILE__);
    }
    redirect(site_url('admin/login.php'));
}

$requirements = [
    'PHP 8.1 or newer'      => version_compare(PHP_VERSION, '8.1.0', '>='),
    'pdo_sqlite extension'  => extension_loaded('pdo_sqlite'),
    'data/ folder writable' => is_writable(DATA_DIR),
];
$req_ok = !in_array(false, $requirements, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install' && (!$installed || $force)) {
    verify_csrf();
    $business = trim((string) ($_POST['business_name'] ?? '')) ?: 'Metro Valet Parking';
    $name = trim((string) ($_POST['admin_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
    $pass = (string) ($_POST['admin_password'] ?? '');
    $demo = !empty($_POST['demo']);

    if (!$req_ok) { $errors[] = 'Fix the server requirements listed above first.'; }
    if ($name === '') { $errors[] = 'Admin name is required.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'A valid admin email is required.'; }
    if (strlen($pass) < 10) { $errors[] = 'Password must be at least 10 characters.'; }

    if (!$errors) {
        $db = db();
        try {
            foreach (mv_schema() as $sql) {
                $db->exec($sql);
            }
            $db->beginTransaction();
            global $ROLE_PERMS;
            $labels = [
                'super_admin' => 'Super Admin', 'operations_manager' => 'Operations Manager',
                'location_manager' => 'Location Manager', 'finance_staff' => 'Finance Staff',
                'readonly_staff' => 'Read-only Staff',
            ];
            foreach ($ROLE_PERMS as $role => $perms) {
                $db->prepare('INSERT OR IGNORE INTO roles (name, label, permissions) VALUES (?, ?, ?)')
                    ->execute([$role, $labels[$role] ?? $role, json_encode(array_values($perms))]);
            }
            foreach (mv_templates() as $key => [$subject, $body]) {
                $db->prepare('INSERT OR IGNORE INTO notification_templates (key, subject, body, active, updated_at) VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP)')
                    ->execute([$key, $subject, $body]);
            }
            $cron_token = bin2hex(random_bytes(16));
            $defaults = [
                'business_name' => $business, 'business_email' => $email, 'business_phone' => '',
                'admin_email' => $email, 'mail_from' => $email, 'tagline' => '',
                'cron_token' => $cron_token, 'reminders_enabled' => '1', 'mfa_enforced' => '0',
                'manual_payment_methods' => '',
            ];
            foreach ($defaults as $k => $v) {
                $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)')->execute([$k, $v]);
            }
            $cron_token = (string) ($db->query("SELECT value FROM settings WHERE key = 'cron_token'")->fetchColumn() ?: $cron_token);

            $roleId = (int) $db->query("SELECT id FROM roles WHERE name = 'super_admin'")->fetchColumn();
            $st = $db->prepare('SELECT id FROM users WHERE email = ?');
            $st->execute([$email]);
            $adminId = (int) $st->fetchColumn();
            if (!$adminId) {
                $db->prepare('INSERT INTO users (name, email, password_hash, role_id, active, created_at) VALUES (?, ?, ?, ?, 1, ?)')
                    ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $roleId, date('Y-m-d H:i:s')]);
                $adminId = (int) $db->lastInsertId();
            }
            if ($demo) {
                mv_seed_demo($db, $adminId);
            }
            $db->commit();
            // Re-run bootstrap's additive seeds (templates, backup settings) now that tables exist.
            $GLOBALS['_mv_settings'] = [];
            audit('system.installed', 'system', null, ['demo' => $demo]);
            $done = ['cron_token' => $cron_token, 'demo' => $demo];
            $installed = true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = 'Install failed: ' . $e->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Install — Metro Valet Parking</title>
<link rel="stylesheet" href="<?= e(site_url('assets/css/admin.css')) ?>">
</head>
<body class="admin-standalone">
<div class="standalone-card" style="max-width:560px">
    <h1>Install Metro Valet Parking</h1>
<?php if ($done): ?>
    <p><strong>Installation complete.</strong></p>
    <p>Daily reminders cron token (also in Admin → Settings):<br><code><?= e($done['cron_token']) ?></code></p>
    <?php if ($done['demo']): ?>
        <p>Demo data loaded. Portal demo login: <code>demo.alice@example.com</code> / <code>Demo-Pass-123</code>.
        Delete demo rows before going live.</p>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_self">
        <button type="submit" class="btn btn-primary">Delete install.php and go to login</button>
    </form>
    <p><a href="<?= e(site_url('admin/login.php')) ?>">Go to admin login</a></p>
<?php elseif ($installed && !$force): ?>
    <p>The application is already installed. Delete <code>install.php</code> from the server.</p>
    <p><a href="<?= e(site_url('admin/login.php')) ?>">Go to admin login</a></p>
<?php else: ?>
    <h3>Server check</h3>
    <ul>
    <?php foreach ($requirements as $label => $ok): ?>
        <li><?= status_dot($ok ? 'green' : 'red', $ok ? 'OK' : 'Missing') ?> <?= e($label) ?></li>
    <?php endforeach; ?>
    </ul>
    <?php foreach ($errors as $err): ?>
        <div class="flash flash-error"><?= e($err) ?></div>
    <?php endforeach; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="install">
        <label for="business_name">Business name</label>
        <input type="text" id="business_name" name="business_name" value="<?= e($_POST['business_name'] ?? 'Metro Valet Parking') ?>">
        <label for="admin_name">Admin full name</label>
        <input type="text" id="admin_name" name="admin_name" required value="<?= e($_POST['admin_name'] ?? '') ?>">
        <label for="admin_email">Admin email</label>
        <input type="email" id="admin_email" name="admin_email" required value="<?= e($_POST['admin_email'] ?? '') ?>">
        <label for="admin_password">Admin password (min 10 characters)</label>
        <input type="password" id="admin_password" name="admin_password" required minlength="10">
        <label><input type="checkbox" name="demo" value="1"> Load demo data (labeled "Demo …" — delete before going live)</label>
        <p><button type="submit" class="btn btn-primary">Install</button></p>
    </form>
<?php endif; ?>
</div>
</body>
</html>
