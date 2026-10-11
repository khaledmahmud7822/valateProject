# INSTALL.md — Deploying Metro Valet Parking on Hostinger

Target: Hostinger shared hosting, PHP 8.1+, no composer, no SSH required.
Everything runs from a plain file upload.

## 1. Upload the files

1. Download/copy the contents of `app/` (not the `app/` folder itself — its *contents*).
2. In hPanel → **File Manager**, open `public_html` (or your subdomain/addon-domain folder).
3. Upload everything there, preserving the directory structure:
   `lib/`, `data/`, `uploads/`, `admin/`, `portal/`, `cron/`, `includes/`, `assets/`, `*.php`, `.htaccess`, `INSTALL.md`, `README.md`.

## 2. Check the PHP version

hPanel → **PHP Configuration** → set **PHP 8.1 or higher** for the domain.
Confirm `pdo_sqlite` is enabled (it is on by default on Hostinger; the installer
will tell you if anything is missing).

## 3. Run the web installer

Visit `https://yourdomain.com/install.php` and fill in:

- **Business name** (default: Metro Valet Parking)
- **Admin full name / email / password** (min 10 characters) — this creates the
  super-admin account. There are no hardcoded credentials.
- **Load demo data** (optional, clearly labeled "Demo …" — delete before going live).

The installer creates the SQLite database at `data/metrovalet.sqlite`, seeds
5 staff roles, the 10 parking locations, 10 notification templates, and default
settings (including a random `cron_token`).

The installer refuses to run twice. If you must re-run it, use
`install.php?force=1` (existing rows are kept — seeds use `INSERT OR IGNORE`).

## 4. Delete install.php

**Do not leave `install.php` on a live server.** Either:

- click **"Delete install.php"** on the installer's success page, or
- delete it manually in File Manager, or
- uncomment the `install.php` block rule in the app-root `.htaccess`.

## 5. Set up the daily reminders cron

The renewal-reminder engine lives in `cron/reminders.php` and is token-guarded:
the `?token=` value must match the `cron_token` setting (shown once on the
install success page, also visible in Admin → Settings).

**Option A — Hostinger cron (recommended):**
hPanel → **Cron Jobs** → add, run **once daily** (e.g. 7:00 AM Detroit time):

```
/usr/bin/php /home/USERNAME/public_html/cron/reminders.php --token=YOUR_CRON_TOKEN
```

(replace `USERNAME` with your hosting username and `YOUR_CRON_TOKEN` with the
value from settings; verify the exact invocation in `cron/reminders.php` —
it accepts both `--token=` and `token=` CLI forms.)

**Option B — web cron (if your plan lacks cron jobs):**
Point an external cron service (e.g. cron-job.org) at:

```
https://yourdomain.com/cron/reminders.php?token=YOUR_CRON_TOKEN
```

Test it once by hand: open the URL in your browser. You should get a small
JSON/plain summary like `{"seven":0,"three":1,...}`. Run it twice — the second
run must NOT resend the same reminders (dedupe keys in `notification_logs`).

## 6. File permissions

- `data/` must be writable by PHP: **755** (or 775 if 755 fails).
- `data/.htaccess` and `uploads/.htaccess` must be present — they block direct
  web access to the database and disable PHP execution in uploads.
- PHP files: 644. Never 777 anything.

## 7. Email notes (mail() is the default transport)

- Outgoing mail uses PHP `mail()` with a branded HTML wrapper; the From address
  comes from the `mail_from` setting (default `info@metrovaletparking.com`).
- **Important:** set `mail_from` to an address on YOUR domain (Admin → Settings)
  and make sure the domain has an **SPF record** including Hostinger, otherwise
  mail may land in spam. hPanel → **Email → Email Deliverability** shows this.
- Every send attempt is logged in `notification_logs` (Admin → Notifications)
  with status `sent`/`failed` — check there first if mail seems missing.
- If you need SMTP instead of `mail()`, configure it in Admin → Settings
  (SMTP transport) — the default `mail()` path keeps working regardless.

## 8. HTTPS

Once SSL is active (hPanel → **SSL**, usually automatic), uncomment the
"Force HTTPS" block in the app-root `.htaccess`. Session cookies are already
marked `Secure` automatically when the request is HTTPS.

## 9. Stripe card payments (optional but recommended)

1. In the Stripe Dashboard, get your **publishable key** (`pk_...`) and
   **secret key** (`sk_...`). Start in **Test mode**.
2. Admin → **Settings** → *Payments — Stripe*: paste both keys and Save.
   The page shows a status badge (off / checkout-on / fully-enabled).
3. In Stripe Dashboard → **Developers → Webhooks** → **Add endpoint**:
   - URL: `https://yourdomain.com/pay/stripe_webhook.php`
     (shown under the settings fields — copy it from there)
   - Events: `checkout.session.completed`, `checkout.session.expired`,
     `payment_intent.payment_failed`
   - Copy the **signing secret** (`whsec_...`) into Settings → Save.
4. Test: create an invoice, copy its pay link, pay with card
   `4242 4242 4242 4242` (any future expiry, any CVC). The invoice should flip
   to Paid and the customer gets the paid-invoice email with PDF.
5. Go live: switch Stripe to **Live mode** and repeat steps 2–3 with the
   live keys and a live webhook. Test mode and live mode have separate keys
   and separate webhook secrets.

Notes:

- Apple Pay / Google Pay appear automatically in Stripe Checkout when enabled
  in your Stripe Dashboard (Settings → Payment methods). No code change needed.
- Never paste secret keys anywhere except Admin → Settings (they are stored
  server-side and never shown in page HTML).
- Webhooks must use your real domain — temporary preview links do not work
  for Stripe webhooks.

## 10. MySQL instead of SQLite (optional)

SQLite is fine for this app's scale. To switch to MySQL:

1. Create a database + user in hPanel → **Databases → MySQL**.
2. Import the schema: copy the SQL from `install.php` (`mv_schema_sql()`),
   replacing `AUTOINCREMENT` with `AUTO_INCREMENT` and `INSERT OR IGNORE`
   with `INSERT IGNORE` where used.
3. In `lib/config.php`, comment out the SQLite `$DB_PATH` line and uncomment
   the MySQL block with your host/db/user/pass.
4. Seed data manually (roles, settings, locations, templates) or ask for help.
5. Delete `install.php`.

## 10. Timezone

The app forces `America/Detroit` in `lib/bootstrap.php`. The server's own
timezone setting does not matter for app dates.

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Installer: "pdo_sqlite missing" | Enable it in hPanel → PHP Configuration → Extensions |
| Installer: "data/ is not writable" | File Manager → right-click `data/` → Permissions → 755/775 |
| Blank page / 500 error | Check `public_html/error_log`; usually a PHP version < 8.1 or a partial upload — re-upload |
| Login rate-limited | 5 attempts / 10 min / IP; wait, or delete `data/ratelimit/*.json` via File Manager |
| Mail not arriving | Check Admin → Notifications log for `failed`; verify `mail_from` domain + SPF |
| Cron URL returns 403 | Token mismatch — copy `cron_token` exactly from Admin → Settings |
