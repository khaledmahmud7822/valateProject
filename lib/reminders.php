<?php
/**
 * Metro Valet Parking — renewal reminder engine.
 *
 * find_due_reminders(): subscriptions needing 7-day / 3-day / expired handling
 *   (all dates in America/Detroit).
 * process_reminders(): sends via send_template() with dedupe keys, marks
 *   expired subscriptions, returns a summary array.
 * Used by BOTH cron/reminders.php and the admin reminders UI.
 * Defines functions only.
 */

/**
 * Subscriptions due for renewal handling, keyed by bucket:
 *   ['seven' => [...], 'three' => [...], 'expired' => [...]]
 * Each row carries customer_name, customer_email (customer or company billing),
 * location_name, plate, and the subscription fields.
 */
function find_due_reminders(): array {
    $db = db();
    $t = today();
    $d7 = date('Y-m-d', strtotime($t . ' +7 days'));
    $d3 = date('Y-m-d', strtotime($t . ' +3 days'));

    $select = "SELECT s.id, s.customer_id, s.company_id, s.location_id, s.vehicle_id,
                      s.start_date, s.end_date, s.monthly_rate_cents, s.status,
                      COALESCE(c.name, co.name) AS customer_name,
                      COALESCE(c.email, co.billing_email, co.email) AS customer_email,
                      l.name AS location_name, v.plate AS plate
               FROM subscriptions s
               LEFT JOIN customers c ON c.id = s.customer_id
               LEFT JOIN companies co ON co.id = s.company_id
               LEFT JOIN parking_locations l ON l.id = s.location_id
               LEFT JOIN vehicles v ON v.id = s.vehicle_id
               WHERE s.deleted_at IS NULL AND s.status = 'active'";

    $by_date = function (string $date) use ($db, $select): array {
        $st = $db->prepare($select . " AND s.end_date = ?");
        $st->execute([$date]);
        return $st->fetchAll();
    };

    $stx = $db->prepare($select . " AND s.end_date < ?");
    $stx->execute([$t]);

    return [
        'seven'   => $by_date($d7),
        'three'   => $by_date($d3),
        'expired' => $stx->fetchAll(),
    ];
}

/** Template variables shared by the renewal/expired templates. */
function reminder_template_vars(array $s): array {
    return [
        'customer_name' => $s['customer_name'] ?? 'Valued Customer',
        'location_name' => $s['location_name'] ?? 'your location',
        'plate'         => $s['plate'] ?? '—',
        'end_date'      => fmt_date($s['end_date'] ?? null),
        'days'          => (string) max(0, days_remaining($s['end_date'] ?? today())),
        'business_name' => (string) setting('business_name', 'Metro Valet Parking'),
        'phone'         => (string) setting('phone', ''),
        'support_email' => (string) setting('email', ''),
    ];
}

/**
 * Process due reminders.
 *
 * - end_date = today+7d -> renewal_7 (dedupe renewal_7:{id}:{end_date})
 * - end_date = today+3d -> renewal_3 (dedupe renewal_3:{id}:{end_date})
 * - end_date < today & active -> status='expired' + expired_notice email
 *   (dedupe expired_notice:{id}:{end_date})
 * - $dry = true: compute only, change nothing.
 *
 * Returns summary: ['date','dry','seven','three','expired','skipped','errors',...].
 * Safe to run twice: send_template() dedupe keys prevent double-sends.
 */
function process_reminders(bool $dry = false): array {
    $due = find_due_reminders();
    $summary = [
        'date'    => today(),
        'dry'     => $dry,
        'seven'   => 0,
        'three'   => 0,
        'expired' => 0,
        'skipped' => 0,
        'errors'  => [],
    ];

    $enabled = setting('reminders_enabled', '1') === '1';
    if (!$enabled && !$dry) {
        $summary['disabled'] = true;
        return $summary;
    }

    $send_bucket = function (array $rows, string $tpl_key, string $count_key) use ($dry, &$summary): void {
        foreach ($rows as $s) {
            $dedupe = $tpl_key . ':' . $s['id'] . ':' . $s['end_date'];
            if ($dry || empty($s['customer_email'])) {
                $summary['skipped']++;
                continue;
            }
            if (send_template($tpl_key, $s['customer_email'], reminder_template_vars($s), $dedupe)) {
                $summary[$count_key]++;
            } else {
                $summary['skipped']++;
                $summary['errors'][] = $tpl_key . ' failed for subscription ' . $s['id'];
            }
        }
    };

    $send_bucket($due['seven'], 'renewal_7', 'seven');
    $send_bucket($due['three'], 'renewal_3', 'three');

    foreach ($due['expired'] as $s) {
        $summary['expired']++;
        if ($dry) {
            continue;
        }
        try {
            db()->prepare("UPDATE subscriptions SET status = 'expired', updated_at = ? WHERE id = ? AND status = 'active'")
                ->execute([date('Y-m-d H:i:s'), $s['id']]);
            audit('subscription.expired', 'subscription', $s['id'], ['end_date' => $s['end_date'], 'via' => 'reminder_cron']);
            if (!empty($s['customer_email'])) {
                send_template(
                    'expired_notice',
                    $s['customer_email'],
                    reminder_template_vars($s),
                    'expired_notice:' . $s['id'] . ':' . $s['end_date']
                );
            }
        } catch (Throwable $e) {
            $summary['errors'][] = 'expired handling failed for subscription ' . $s['id'] . ': ' . $e->getMessage();
        }
    }

    return $summary;
}
