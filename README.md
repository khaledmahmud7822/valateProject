# Metro Valet Parking

Valet & monthly-parking management platform for a Detroit parking operator.
PHP 8.1+, page-per-file, zero dependencies (no composer), SQLite by default —
built for Hostinger shared hosting: upload and run.

## What it does

- **Public site** — locations, monthly-parking requests, valet quote requests,
  contact form, customer login.
- **Admin** — dashboard KPIs + SVG charts, locations, parkers (subscriptions),
  plate lookup, companies, renewals with days-remaining badges, invoices +
  payments (status recalculated from payment sums — never trusted from input),
  reports with CSV export, requests/leads pipelines, reminders, notification
  templates + send log, staff + roles, activity log, settings.
- **Customer portal** — invoices, secure pay links, vehicles, profile, support.
- **Cron** — daily renewal reminders (7-day, 3-day, expired) with dedupe keys,
  token-guarded.

## Structure (under `app/`)

```
install.php            web setup wizard (delete after install)
index.php ...          public pages
includes/              site_header.php / site_footer.php (shared chrome)
admin/                 staff pages (guarded by _guard.php: login + can())
portal/                customer pages (guarded by _layout.php: require_customer())
cron/reminders.php     daily cron target (token-guarded)
pay/                   Stripe return / webhook (if configured)
lib/
  config.php           paths, $DB_PATH, MySQL switch, constants
  bootstrap.php        FIRST require in every page: timezone, session, libs, $db
  db.php               db() / db_init() — single PDO, prepared statements only
  helpers.php          e(), base_url(), site_url(), redirect(), setting(s),
                       money(), fmt_date(), today(), days_remaining(),
                       days_badge(), normalize_plate(), ref_no(),
                       secure_token(), new_invoice_number(), flash(es),
                       paginate(), recalc_invoice(), status_dot()
  csrf.php             csrf_token() / csrf_field() / verify_csrf()
  auth.php             admin + customer login helpers
  permissions.php      $PERMISSIONS, $ROLE_PERMS, can(), has_role()
  audit.php            audit() — never throws
  ratelimit.php        file-based rate limiting (logins 5/600s, forms 20/3600s)
  totp.php             pure-PHP TOTP (RFC 6238) for optional staff MFA
  mailer.php           send_mail_raw() / send_template() + notification_logs
  charts.php           chart_bars() / chart_line() — inline SVG, no libraries
  reminders.php        find_due_reminders() / process_reminders()
data/                  metrovalet.sqlite + ratelimit/ (web access denied)
uploads/               user uploads (PHP execution denied)
assets/                css / js / img
```

## Quick start

1. Upload `app/` contents to `public_html` (see `INSTALL.md` for full steps).
2. Visit `/install.php` → create the super-admin account → **delete install.php**.
3. Log in at `/admin/login.php`.

### Roles (permission matrix in `lib/permissions.php`)

| Role | Access |
|---|---|
| super_admin | everything |
| operations_manager | everything except staff.manage, settings.edit |
| location_manager | locations/parkers/plates/renewals/requests/leads/reminders view + manage — **no financials** (invoices.view denied) |
| finance_staff | parkers/plates/renewals/invoices/payments/reports/reminders/companies |
| readonly_staff | view-only (+ companies); requests.manage / leads.manage denied |

### Cron

Daily: `php cron/reminders.php --token=<cron_token>` or
`GET /cron/reminders.php?token=<cron_token>`. Safe to run twice — dedupe keys
(`renewal_7:{sub_id}:{end_date}`, …) prevent double-sends; every attempt is
logged to `notification_logs`.

### Demo data

The installer offers clearly-labeled demo data: 6 customers prefixed
`Demo ` (staggered subscription end dates: +6d, +2d, −5d expired, +7d, +3d,
+30d), invoices (unpaid / partial / paid), 2 companies (one with 3 vehicles +
consolidated invoice). Portal demo login: `demo.alice@example.com` /
`Demo-Pass-123`. **Delete demo rows before going live.**

## Conventions for builders

- Every page starts with `<?php require __DIR__ . '/lib/bootstrap.php';`
  (`/../lib/bootstrap.php` from subfolders). Lib files never require bootstrap.
- **Every query uses PDO prepared statements.** Never interpolate into SQL.
- Passwords: `password_hash()` / `password_verify()` only.
- Timezone: `America/Detroit` everywhere (set once in bootstrap); store dates
  as `Y-m-d` / `Y-m-d H:i:s` text. Days-remaining is computed in PHP, never stored.
- Money: integer cents; display with `money()`.
- Escape output with `e()`. Status indicators: `status_dot($color, $label)` —
  color dots ALWAYS carry a text label.
- `base_url()` returns the app-root path prefix (`''` at web root); it strips
  known subfolders (admin, portal, cron, …) so `site_url()` works from any page.
- Flash messages: `flash($msg, $type)` then `flashes()` in the layout.
- Invoice money math: add a `payments` row, then call `recalc_invoice($id)` —
  it recomputes `amount_paid_cents` and status in a transaction.
- `audit($action, $entity, $entity_id, $details)` for admin-visible actions.
- Public forms: `rate_ok()/rate_hit()` + `verify_csrf()` + honeypot/CAPTCHA.
- No invented stats, prices, reviews, or partnerships anywhere public.
  `monthly_rate_cents` is NULL for seeded locations ("Contact for rates").

## Testing pointers

- `php -l` every changed file.
- Fresh install: copy `app/` to a scratch dir, run the installer via
  `php -S`/CLI harness, assert 10 locations + 5 roles + 10 templates seeded.
- TOTP: RFC 6238 vector — base32 of ASCII `"12345678901234567890"`,
  `totp_code($s, 59) === '287082'`, `totp_code($s, 1111111109) === '081804'`.
- `recalc_invoice()`: partial payment → `partially_paid`; full → `paid`;
  past-due unpaid → `overdue`; `voided` stays `voided`.
- Reminders: seed subscriptions ending today+7 / today+3 / yesterday, run
  `process_reminders(true)` (dry) then for real twice — second run sends nothing new.
- Rate limit: 6th login attempt within 10 min from one IP must be blocked.
- `normalize_plate(' abc-123 ') === 'ABC123'`;
  `ref_no('MVP')` → `MVP-2026-000123` format with per-year counter.

## Security notes

- `data/` and `uploads/` are protected by `.htaccess` (deny web access to the
  DB; no PHP execution in uploads). Session cookies: `HttpOnly`, `SameSite=Lax`
  (`Secure` auto-enabled on HTTPS). Session ID regenerated on login.
- `install.php` must be deleted after setup (self-delete button provided).
- Never commit `data/metrovalet.sqlite` or `data/ratelimit/` contents.
