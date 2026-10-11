<?php
/**
 * Metro Valet Parking — activity logging.
 *
 * audit() never throws: logging must never break the request it observes.
 * Actor is derived from the session (admin user, portal customer /
 * company contact, or 'system'). Defines functions only.
 */

/**
 * Write one row to activity_logs.
 *
 * @param string $action    e.g. 'invoice.created', 'subscription.expired'
 * @param string $entity    e.g. 'invoice', 'subscription', 'user'
 * @param mixed  $entity_id row id (int) or null
 * @param array  $details   extra context, stored as JSON
 */
function audit(string $action, string $entity, $entity_id, array $details = []): void {
    $actor_id = null;
    $actor_type = 'system';

    if (!empty($_SESSION['admin_id'])) {
        $actor_id = (int) $_SESSION['admin_id'];
        $actor_type = 'admin';
    } elseif (!empty($_SESSION['customer_id'])) {
        $actor_id = (int) $_SESSION['customer_id'];
        $actor_type = (($_SESSION['customer_kind'] ?? 'customer') === 'company_contact')
            ? 'company_contact'
            : 'customer';
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    try {
        $st = db()->prepare(
            "INSERT INTO activity_logs (actor_id, actor_type, action, entity, entity_id, details, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $actor_id,
            $actor_type,
            $action,
            $entity,
            $entity_id === null ? null : (int) $entity_id,
            json_encode($details, JSON_UNESCAPED_SLASHES),
            $ip,
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Audit must never break the request. Swallow silently.
    }
}
