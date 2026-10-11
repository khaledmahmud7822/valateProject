<?php
/**
 * Metro Valet Parking — shared helpers.
 *
 * Exact public signatures (other builders call these blind — do not rename):
 *   e(), base_url(), site_url(), redirect(), setting(), set_setting(),
 *   money(), fmt_date(), today(), days_remaining(), days_badge(),
 *   normalize_plate(), ref_no(), secure_token(), new_invoice_number(),
 *   flash(), flashes(), paginate(), recalc_invoice(), status_dot()
 *
 * Defines functions only — no side effects at require time.
 */

/** HTML-escape a string (UTF-8, quotes escaped). */
function e($s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL path prefix of the app, derived from SCRIPT_NAME's directory.
 * Known app subdirectories (admin, portal, cron, ...) are stripped so the
 * prefix always points at the APP ROOT, no matter which page calls it.
 * Returns '' when the app lives at the web root.
 */
function base_url(): string {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (['admin', 'portal', 'cron', 'pay', 'includes', 'lib', 'assets', 'uploads', 'data'] as $sub) {
        if (str_ends_with($dir, '/' . $sub)) {
            $dir = substr($dir, 0, -strlen('/' . $sub));
            break;
        }
    }
    $dir = rtrim($dir, '/');
    return ($dir === '' || $dir === '.') ? '' : $dir;
}

/** Absolute URL for $p, e.g. site_url('admin/login.php'). Safe for emails/Stripe. */
function site_url(string $p): string {
    $fwd = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $fwd === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return $scheme . '://' . $host . base_url() . '/' . ltrim($p, '/');
}

/** Send a Location header and stop. */
function redirect(string $url): void {
    if (!headers_sent()) {
        header('Location: ' . $url);
    }
    exit;
}

/**
 * Read a settings value (cached per request). Returns $default when missing
 * or when the settings table is not readable yet (pre-install).
 */
function setting(string $key, $default = null) {
    if (!array_key_exists($key, $GLOBALS['_mv_settings'] ?? [])) {
        $value = $default;
        try {
            $st = db()->prepare("SELECT value FROM settings WHERE key = ?");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            if ($v !== false) {
                $value = $v;
            }
        } catch (Throwable $e) {
            // Table may not exist yet (before install). Fall through to default.
        }
        $GLOBALS['_mv_settings'][$key] = $value;
    }
    return $GLOBALS['_mv_settings'][$key] ?? $default;
}

/** Upsert a settings value (works on SQLite and MySQL). */
function set_setting(string $key, $value): void {
    $db = db();
    $up = $db->prepare("UPDATE settings SET value = ? WHERE key = ?");
    $up->execute([(string) $value, $key]);
    if ($up->rowCount() === 0) {
        $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?)")->execute([$key, (string) $value]);
    }
    $GLOBALS['_mv_settings'][$key] = $value;
}

/** Format integer cents as dollars, e.g. 123456 -> "$1,234.56". */
function money(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

/** Format a Y-m-d date as "M j, Y" ("Oct 7, 2026"); null/empty/invalid -> "—". */
function fmt_date(?string $d): string {
    if ($d === null || trim($d) === '') {
        return '—';
    }
    $ts = strtotime($d);
    if ($ts === false) {
        return '—';
    }
    return date('M j, Y', $ts);
}

/** Today's date (Y-m-d) in America/Detroit. */
function today(): string {
    return date('Y-m-d');
}

/**
 * Whole days from today (Detroit) until $end_date (Y-m-d).
 * floor((end_midnight - today_midnight) / 86400); negative when past.
 */
function days_remaining(string $end_date): int {
    $diff = strtotime($end_date) - strtotime(today());
    return (int) floor($diff / 86400);
}

/**
 * Renewal badge for a subscription end date.
 * Returns ['days' => int, 'color' => 'green|amber|red|gray', 'label' => string].
 */
function days_badge(string $end_date): array {
    $n = days_remaining($end_date);
    if ($n > 7) {
        return ['days' => $n, 'color' => 'green', 'label' => $n . ' Days Remaining'];
    }
    if ($n >= 4) {
        return ['days' => $n, 'color' => 'amber', 'label' => $n . ' Days Remaining — Renewal Reminder'];
    }
    if ($n >= 1) {
        $unit = $n === 1 ? 'Day' : 'Days';
        return ['days' => $n, 'color' => 'red', 'label' => $n . ' ' . $unit . ' Remaining — Urgent Renewal'];
    }
    if ($n === 0) {
        return ['days' => 0, 'color' => 'red', 'label' => 'Expires Today — Urgent Renewal'];
    }
    return ['days' => $n, 'color' => 'red', 'label' => 'Expired — Renewal Required'];
}

/** Normalize a license plate: uppercase, strip spaces and dashes. */
function normalize_plate(string $p): string {
    return strtoupper(preg_replace('/[\s\-]+/', '', $p) ?? '');
}

/**
 * Next reference number like "MVP-2026-000123".
 * Uses a per-year counter stored in settings as seq_YYYY (transaction-safe).
 */
function ref_no(string $prefix): string {
    $db = db();
    $year = date('Y');
    $key = 'seq_' . $year;
    // Join an outer transaction when one is active (e.g. the installer),
    // otherwise manage our own.
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare("SELECT value FROM settings WHERE key = ?");
        $st->execute([$key]);
        $n = (int) ($st->fetchColumn() ?: 0) + 1;
        $up = $db->prepare("UPDATE settings SET value = ? WHERE key = ?");
        $up->execute([(string) $n, $key]);
        if ($up->rowCount() === 0) {
            $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?)")->execute([$key, (string) $n]);
        }
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    $GLOBALS['_mv_settings'][$key] = (string) $n;
    return sprintf('%s-%s-%06d', $prefix, $year, $n);
}

/** Cryptographically secure random hex token ($bytes of entropy, hex-encoded). */
function secure_token(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

/** Next invoice number like "MVP-INV-2026-000001". */
function new_invoice_number(): string {
    return ref_no('MVP-INV');
}

/** Queue a one-time flash message for the next page load. */
function flash(string $msg, string $type = 'info'): void {
    $_SESSION['_flashes'][] = ['msg' => $msg, 'type' => $type];
}

/** Return queued flash messages and clear the queue. */
function flashes(): array {
    $f = $_SESSION['_flashes'] ?? [];
    unset($_SESSION['_flashes']);
    return is_array($f) ? $f : [];
}

/**
 * Pagination links. $base is the script path (e.g. 'parkers.php');
 * current GET params (except page) are preserved.
 */
function paginate(int $total, int $per, int $page, string $base): string {
    $per = max(1, $per);
    $pages = (int) ceil($total / $per);
    if ($pages <= 1) {
        return '';
    }
    $page = max(1, min($page, $pages));

    $q = $_GET;
    unset($q['page']);
    $qs = http_build_query($q);
    $prefix = $base . '?' . ($qs !== '' ? $qs . '&' : '');

    $link = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($prefix): string {
        if ($disabled) {
            return '<span class="pg pg-disabled" aria-disabled="true">' . e($label) . '</span>';
        }
        $cls = $active ? ' pg-current' : '';
        $aria = $active ? ' aria-current="page"' : '';
        return '<a class="pg' . $cls . '" href="' . e($prefix . 'page=' . $p) . '"' . $aria . '>' . e($label) . '</a>';
    };

    $html = '<nav class="pagination" aria-label="Pagination">';
    $html .= $link(max(1, $page - 1), '« Prev', false, $page <= 1);

    $window = 2;
    $start = max(1, $page - $window);
    $end = min($pages, $page + $window);
    if ($start > 1) {
        $html .= $link(1, '1');
        if ($start > 2) {
            $html .= '<span class="pg pg-gap" aria-hidden="true">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $link($i, (string) $i, $i === $page);
    }
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $html .= '<span class="pg pg-gap" aria-hidden="true">…</span>';
        }
        $html .= $link($pages, (string) $pages);
    }

    $html .= $link(min($pages, $page + 1), 'Next »', false, $page >= $pages);
    $html .= '</nav>';
    return $html;
}

/**
 * Recalculate an invoice's amount_paid_cents and status from its payments.
 * Runs in a transaction; NEVER trusts client input.
 *
 * Status rules: voided stays voided; refunded stays refunded;
 * sum >= amount -> paid; sum > 0 -> partially_paid;
 * past due_date with nothing paid -> overdue; otherwise unpaid.
 * Returns the resulting status.
 */
function recalc_invoice(int $invoice_id): string {
    $db = db();
    // Join an outer transaction when one is active, otherwise manage our own.
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare("SELECT amount_cents, due_date, status FROM invoices WHERE id = ?");
        $st->execute([$invoice_id]);
        $inv = $st->fetch();
        if (!$inv) {
            throw new RuntimeException('Invoice #' . $invoice_id . ' not found.');
        }
        if ($inv['status'] === 'voided' || $inv['status'] === 'refunded') {
            if ($own) {
                $db->commit();
            }
            return $inv['status'];
        }

        // Verified money: recorded by staff (recorded_by set) or a card payment that
        // only exists after server-side Stripe verification. Customer-initiated
        // "transfer started" claims (recorded_by NULL, method != card) are
        // tracked separately and NEVER flip an invoice to paid.
        $s = $db->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE invoice_id = ? AND (recorded_by IS NOT NULL OR method = 'card')");
        $s->execute([$invoice_id]);
        $paid = (int) $s->fetchColumn();

        $u = $db->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE invoice_id = ? AND recorded_by IS NULL AND method != 'card'");
        $u->execute([$invoice_id]);
        $claimed = (int) $u->fetchColumn();

        if ($paid >= (int) $inv['amount_cents']) {
            $status = 'paid';
        } elseif ($paid > 0) {
            $status = 'partially_paid';
        } elseif ($claimed > 0) {
            $status = 'pending'; // customer claims payment; awaiting staff verification
        } elseif (!empty($inv['due_date']) && $inv['due_date'] < today()) {
            $status = 'overdue';
        } else {
            $status = 'unpaid';
        }

        $db->prepare("UPDATE invoices SET amount_paid_cents = ?, status = ? WHERE id = ?")
           ->execute([$paid, $status, $invoice_id]);
        if ($own) {
            $db->commit();
        }
        // Auto-extend the subscription when a renewal invoice is paid.
        // Idempotent: maybe_auto_extend_subscription() only acts once per invoice.
        if ($status === 'paid' && function_exists('maybe_auto_extend_subscription')) {
            try {
                maybe_auto_extend_subscription($invoice_id);
            } catch (Throwable $e) { /* extension is best-effort; invoice stays paid */ }
        }
        return $status;
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Accessible status indicator: colored dot ALWAYS accompanied by a text label.
 * $color: green | amber | red | gray (anything else falls back to gray).
 */
function status_dot(string $color, string $label): string {
    $allowed = ['green', 'amber', 'red', 'gray'];
    $c = in_array($color, $allowed, true) ? $color : 'gray';
    return '<span class="status-dot status-dot-' . $c . '">'
        . '<span class="status-dot-mark" aria-hidden="true"></span>'
        . '<span class="status-dot-label">' . e($label) . '</span>'
        . '</span>';
}
