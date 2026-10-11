<?php
require __DIR__ . '/../lib/bootstrap.php';

/* ------------------------------------------------------------------
 * cron/reminders.php — daily renewal-reminder cron target.
 *
 * Token-guarded: web => ?token= must equal setting('cron_token');
 * CLI  => php cron/reminders.php <token>   or   --token=<token>.
 * Calls Builder A's process_reminders() (lib/reminders.php) and prints
 * a plain-text summary. Logs a system activity entry per run.
 * ------------------------------------------------------------------ */

$is_cli = (php_sapi_name() === 'cli');
$token  = '';

if ($is_cli) {
    $args = isset($argv) && is_array($argv) ? $argv : array();
    array_shift($args); /* script name */
    foreach ($args as $a) {
        if (strpos((string)$a, '--token=') === 0) {
            $token = substr((string)$a, 8);
            break;
        }
    }
    if ($token === '') {
        foreach ($args as $a) {
            $a = (string)$a;
            if ($a !== '' && $a[0] !== '-') { $token = $a; break; }
        }
    }
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $token = (string)($_GET['token'] ?? '');
}

$expected = (string)setting('cron_token', '');
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo "Forbidden: invalid cron token.\n";
    exit(1);
}

$started = date('Y-m-d H:i:s');

if (!function_exists('process_reminders')) {
    http_response_code(500);
    echo "ERROR: process_reminders() is not available (lib/reminders.php not loaded).\n";
    exit(1);
}

try {
    $res = process_reminders();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: reminder run failed: ' . $e->getMessage() . "\n";
    exit(1);
}

$sent    = 0;
$skipped = 0;
$expired = 0;
if (is_array($res)) {
    $sent    = (int)($res['sent'] ?? 0);
    $skipped = (int)($res['skipped'] ?? 0);
    $expired = (int)($res['expired'] ?? $res['marked_expired'] ?? 0);
}

echo 'Metro Valet reminders — run at ' . $started . " America/Detroit\n";
echo 'sent: ' . $sent . ' | skipped: ' . $skipped . ' | marked expired: ' . $expired . "\n";

// --- Trash auto-delete: permanently remove parkers trashed > 7 days ago. ---
$trashed = 0;
try {
    $db = db();
    $ids = $db->query(
        "SELECT id FROM subscriptions WHERE deleted_at IS NOT NULL AND deleted_at < datetime('now', '-7 days')"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $tid) {
        $tid = (int) $tid;
        $db->beginTransaction();
        try {
            $sub = $db->query('SELECT * FROM subscriptions WHERE id = ' . $tid)->fetch(PDO::FETCH_ASSOC);
            if (!$sub) {
                $db->rollBack();
                continue;
            }
            $vid = $sub['vehicle_id'] ? (int) $sub['vehicle_id'] : null;
            $cid = $sub['customer_id'] ? (int) $sub['customer_id'] : null;
            // Invoices of this subscription.
            $inv = $db->prepare('SELECT id FROM invoices WHERE subscription_id = ?');
            $inv->execute([$tid]);
            foreach ($inv->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                $iid = (int) $iid;
                $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$iid]);
                $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
                $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
            }
            $db->prepare('DELETE FROM subscriptions WHERE id = ?')->execute([$tid]);
            if ($vid) {
                $db->prepare('DELETE FROM permits WHERE vehicle_id = ?')->execute([$vid]);
                $c = $db->prepare('SELECT COUNT(*) FROM subscriptions WHERE vehicle_id = ?');
                $c->execute([$vid]);
                if ((int) $c->fetchColumn() === 0) {
                    $db->prepare('DELETE FROM vehicles WHERE id = ?')->execute([$vid]);
                }
            }
            if ($cid) {
                $c = $db->prepare('SELECT COUNT(*) FROM subscriptions WHERE customer_id = ?');
                $c->execute([$cid]);
                if ((int) $c->fetchColumn() === 0) {
                    $inv = $db->prepare('SELECT id FROM invoices WHERE customer_id = ?');
                    $inv->execute([$cid]);
                    foreach ($inv->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                        $iid = (int) $iid;
                        $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$iid]);
                        $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
                        $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
                    }
                    $db->prepare('DELETE FROM permits WHERE customer_id = ?')->execute([$cid]);
                    $db->prepare('DELETE FROM vehicles WHERE customer_id = ?')->execute([$cid]);
                    $db->prepare('UPDATE payments SET recorded_by_customer = NULL WHERE recorded_by_customer = ?')->execute([$cid]);
                    $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
                }
            }
            $db->commit();
            $trashed++;
            try {
                audit('subscription.auto_deleted', 'subscription', $tid, ['reason' => 'trash_expired_7d']);
            } catch (Throwable $e2) {
            }
        } catch (Throwable $e) {
            $db->rollBack();
        }
    }
} catch (Throwable $e) {
    echo 'trash cleanup error: ' . $e->getMessage() . "\n";
}
echo 'trash auto-deleted: ' . $trashed . "\n";

// --- Company trash auto-delete: permanently remove companies trashed > 7 days ago. ---
$ctrashed = 0;
try {
    $db = db();
    $cids = $db->query(
        "SELECT id FROM companies WHERE deleted_at IS NOT NULL AND deleted_at < datetime('now', '-7 days')"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cids as $cid) {
        $cid = (int) $cid;
        $db->beginTransaction();
        try {
            // Invoices (items + payments + invoice).
            $inv = $db->prepare('SELECT id FROM invoices WHERE company_id = ?');
            $inv->execute([$cid]);
            foreach ($inv->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                $iid = (int) $iid;
                $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$iid]);
                $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
                $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
            }
            // Subscriptions of this company.
            $subs = $db->prepare('SELECT id, vehicle_id FROM subscriptions WHERE company_id = ?');
            $subs->execute([$cid]);
            foreach ($subs->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $sid = (int) $s['id'];
                $inv2 = $db->prepare('SELECT id FROM invoices WHERE subscription_id = ?');
                $inv2->execute([$sid]);
                foreach ($inv2->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                    $iid = (int) $iid;
                    $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$iid]);
                    $db->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
                    $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
                }
                $db->prepare('DELETE FROM subscriptions WHERE id = ?')->execute([$sid]);
                if (!empty($s['vehicle_id'])) {
                    $db->prepare('DELETE FROM permits WHERE vehicle_id = ?')->execute([(int) $s['vehicle_id']]);
                }
            }
            $db->prepare('DELETE FROM vehicles WHERE company_id = ?')->execute([$cid]);
            $db->prepare('DELETE FROM company_contacts WHERE company_id = ?')->execute([$cid]);
            $db->prepare('DELETE FROM companies WHERE id = ?')->execute([$cid]);
            $db->commit();
            $ctrashed++;
            try {
                audit('company.auto_deleted', 'company', $cid, ['reason' => 'trash_expired_7d']);
            } catch (Throwable $e2) {
            }
        } catch (Throwable $e) {
            $db->rollBack();
        }
    }
} catch (Throwable $e) {
    echo 'company trash cleanup error: ' . $e->getMessage() . "\n";
}
echo 'company trash auto-deleted: ' . $ctrashed . "\n";

/* Per-run system activity entry (best effort — never fail the run on it). */
try {
    audit('cron_reminders_run', 'system', 0, array(
        'sent' => $sent, 'skipped' => $skipped, 'expired' => $expired, 'at' => $started,
    ));
} catch (Throwable $e) { /* ignore */ }

exit(0);
