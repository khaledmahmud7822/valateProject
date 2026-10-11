<?php
/**
 * Metro Valet Parking — bootstrap.
 *
 * FIRST require in every page:
 *   app root:   <?php require __DIR__ . '/lib/bootstrap.php';
 *   subfolders: <?php require __DIR__ . '/../lib/bootstrap.php';
 *
 * Does: config -> America/Detroit timezone -> secure session -> require all
 * lib files -> create global $db (PDO, SQLite, ERRMODE_EXCEPTION, FK ON).
 * base_url() is defined in lib/helpers.php (required below).
 */

require __DIR__ . '/config.php';

// Single timezone for the whole application.
date_default_timezone_set(APP_TIMEZONE);

// Secure session defaults (must precede session_start()).
$__mv_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $__mv_secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
unset($__mv_secure);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Library files (functions/constants only — none of them require bootstrap).
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/billing.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/permissions.php';
require __DIR__ . '/audit.php';
require __DIR__ . '/ratelimit.php';
require __DIR__ . '/totp.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/pdf.php';
require __DIR__ . '/charts.php';
require __DIR__ . '/reminders.php';

// Global database handle used by db().
$db = db_init();

// Lightweight additive schema updates for existing installs.
try {
    $cols = $db->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('stripe_session_id', $cols, true)) {
        $db->exec('ALTER TABLE invoices ADD COLUMN stripe_session_id TEXT');
    }
    if (!in_array('extend_months', $cols, true)) {
        $db->exec('ALTER TABLE invoices ADD COLUMN extend_months INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('extended_at', $cols, true)) {
        $db->exec('ALTER TABLE invoices ADD COLUMN extended_at TEXT');
    }
    if (!in_array('deleted_at', $cols, true)) {
        $db->exec('ALTER TABLE invoices ADD COLUMN deleted_at TEXT');
    }
    if (!in_array('bill_name', $cols, true)) {
        $db->exec('ALTER TABLE invoices ADD COLUMN bill_name TEXT');
    }
    if (!in_array('bill_email', $cols, true)) {
        $db->exec('ALTER TABLE invoices ADD COLUMN bill_email TEXT');
    }
    $vcols = $db->query('PRAGMA table_info(vehicles)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('tag_number', $vcols, true)) {
        $db->exec('ALTER TABLE vehicles ADD COLUMN tag_number TEXT');
    }
    foreach (['driver_name' => 'TEXT', 'driver_email' => 'TEXT', 'driver_phone' => 'TEXT'] as $dc => $dt) {
        if (!in_array($dc, $vcols, true)) {
            $db->exec("ALTER TABLE vehicles ADD COLUMN $dc $dt");
        }
    }
    $ccols = $db->query('PRAGMA table_info(companies)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('deleted_at', $ccols, true)) {
        $db->exec('ALTER TABLE companies ADD COLUMN deleted_at TEXT');
    }
    $scols = $db->query('PRAGMA table_info(subscriptions)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('hours_allowed', $scols, true)) {
        $db->exec('ALTER TABLE subscriptions ADD COLUMN hours_allowed TEXT');
    }
    if (!in_array('spaces', $scols, true)) {
        $db->exec('ALTER TABLE subscriptions ADD COLUMN spaces INTEGER');
    }
    if (!in_array('category', $scols, true)) {
        $db->exec("ALTER TABLE subscriptions ADD COLUMN category TEXT NOT NULL DEFAULT 'monthly'");
    }
    if (!in_array('deleted_at', $scols, true)) {
        $db->exec('ALTER TABLE subscriptions ADD COLUMN deleted_at TEXT');
    }
    // Customer portal password resets.
    $db->exec("CREATE TABLE IF NOT EXISTS customer_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL,
        token_hash TEXT UNIQUE NOT NULL, kind TEXT NOT NULL DEFAULT 'customer',
        expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
    )");
    // Careers: job postings.
    $db->exec("CREATE TABLE IF NOT EXISTS jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL, job_type TEXT NOT NULL DEFAULT 'full-time',
        location TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '',
        qualifications TEXT NOT NULL DEFAULT '', pay_range TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'open', sort_order INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )");
    // Careers: job applications (all sections in one row).
    $db->exec("CREATE TABLE IF NOT EXISTS applications (
        id INTEGER PRIMARY KEY AUTOINCREMENT, reference_id TEXT UNIQUE NOT NULL,
        job_id INTEGER REFERENCES jobs(id),
        full_name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT NOT NULL,
        city TEXT NOT NULL, state TEXT NOT NULL, contact_method TEXT NOT NULL DEFAULT 'email',
        has_license TEXT NOT NULL DEFAULT 'no', license_state TEXT NOT NULL DEFAULT '',
        license_class TEXT NOT NULL DEFAULT '', has_valet_cert TEXT NOT NULL DEFAULT 'notsure',
        has_attendant_permit TEXT NOT NULL DEFAULT 'notsure',
        comfortable_automatic TEXT NOT NULL DEFAULT 'yes', comfortable_manual TEXT NOT NULL DEFAULT 'no',
        prev_valet_company TEXT NOT NULL DEFAULT 'no', prev_company_name TEXT NOT NULL DEFAULT '',
        prev_position TEXT NOT NULL DEFAULT '', prev_dates TEXT NOT NULL DEFAULT '',
        valet_years TEXT NOT NULL DEFAULT '', prev_responsibilities TEXT NOT NULL DEFAULT '',
        prev_industry_exp TEXT NOT NULL DEFAULT '',
        employment_type TEXT NOT NULL DEFAULT 'either', preferred_days TEXT NOT NULL DEFAULT '',
        preferred_shifts TEXT NOT NULL DEFAULT '', earliest_start TEXT NOT NULL DEFAULT '',
        preferred_location TEXT NOT NULL DEFAULT '', can_perform_duties TEXT NOT NULL DEFAULT 'yes',
        resume_path TEXT NOT NULL DEFAULT '', cover_letter TEXT NOT NULL DEFAULT '',
        comments TEXT NOT NULL DEFAULT '', accuracy_confirmed INTEGER NOT NULL DEFAULT 0,
        privacy_acknowledged INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'new', interview_date TEXT NOT NULL DEFAULT '',
        ip_address TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS application_notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER NOT NULL REFERENCES applications(id),
        admin_id INTEGER, note TEXT NOT NULL, created_at TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS application_activity (
        id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER NOT NULL REFERENCES applications(id),
        actor TEXT NOT NULL, action TEXT NOT NULL, details TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL
    )");
    // Backup system tables (additive).
    $db->exec("CREATE TABLE IF NOT EXISTS backup_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT NOT NULL, size_bytes INTEGER NOT NULL DEFAULT 0, sha256 TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'manual', status TEXT NOT NULL DEFAULT 'ok', verified INTEGER NOT NULL DEFAULT 0, verify_note TEXT NOT NULL DEFAULT '', emailed INTEGER NOT NULL DEFAULT 0, cloud INTEGER NOT NULL DEFAULT 0, manifest TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS backup_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT UNIQUE NOT NULL, backup_id INTEGER NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)");
    // Backup default settings.
    $bdefs = [
        'backup_email' => '',
        'backup_attach_max_mb' => '12',
        'backup_keep' => '12',
        'hiring_email' => '',
        'backup_cloud_provider' => 'none',
        'backup_cloud_endpoint' => '',
        'backup_cloud_region' => 'auto',
        'backup_cloud_bucket' => '',
        'backup_cloud_prefix' => 'metrovalet',
        'backup_cloud_key' => '',
        'backup_cloud_secret' => '',
    ];
    foreach ($bdefs as $k => $v) {
        try {
            $chk = $db->prepare('SELECT 1 FROM settings WHERE key = ? LIMIT 1');
            $chk->execute([$k]);
            if (!$chk->fetchColumn()) {
                $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute([$k, $v]);
            }
        } catch (Throwable $e) { /* settings table not ready */ }
    }
    // Seed the staff-invitation email template if missing.
    try {
        $has_inv = $db->prepare("SELECT 1 FROM notification_templates WHERE key = 'staff_invitation' LIMIT 1");
        $has_inv->execute();
        if (!$has_inv->fetchColumn()) {
            $db->prepare("INSERT INTO notification_templates (key, subject, body, active, updated_at) VALUES ('staff_invitation', ?, ?, 1, CURRENT_TIMESTAMP)")
                ->execute([
                    'Your {{business_name}} staff account is ready',
                    '<p>Hi {{name}},</p><p>A staff account has been created for you with the role: <strong>{{role}}</strong>.</p><p>Click the link below to set your password (expires in 48 hours):</p><p><a href="{{setup_url}}">{{setup_url}}</a></p><p>{{business_name}}</p>',
                ]);
        }
    } catch (Throwable $e) { /* templates table not ready yet */ }
    // Seed the password-reset email template if missing.
    try {
        $has_tpl = $db->prepare("SELECT 1 FROM notification_templates WHERE key = 'password_reset' LIMIT 1");
        $has_tpl->execute();
        if (!$has_tpl->fetchColumn()) {
            $db->prepare("INSERT INTO notification_templates (key, subject, body, active, updated_at) VALUES ('password_reset', ?, ?, 1, CURRENT_TIMESTAMP)")
                ->execute([
                    'Reset your {{business_name}} staff password',
                    '<p>Hi {{name}},</p><p>Someone requested a password reset for your staff account. Click the link below to set a new password (expires in 1 hour):</p><p><a href="{{reset_url}}">{{reset_url}}</a></p><p>If you did not request this, just ignore this email.<br>{{business_name}}</p>',
                ]);
        }
    } catch (Throwable $e) { /* templates table not ready yet */ }
} catch (Throwable $e) {
    // install.php creates the schema; a missing table here is fine.
}

// One-time Stripe key installer (runs once, then disables itself via flag).
if (file_exists(__DIR__ . '/stripe_keys.php')) {
    require __DIR__ . '/stripe_keys.php';
}
