<?php
/**
 * Metro Valet Parking — role/permission matrix.
 *
 * $PERMISSIONS: every permission key (key => human label).
 * $ROLE_PERMS:  role name => list of permission keys ('*' = everything).
 * The roles table seeds its JSON from $ROLE_PERMS; can() reads the logged-in
 * user's role permissions. Defines data + functions only.
 */

$PERMISSIONS = [
    'locations.view'  => 'View parking locations',
    'locations.edit'  => 'Create / edit parking locations',
    'parkers.view'    => 'View monthly parkers',
    'parkers.edit'    => 'Create / edit monthly parkers',
    'plates.lookup'   => 'Plate lookup',
    'companies.view'  => 'View companies',
    'companies.edit'  => 'Create / edit companies',
    'renewals.view'   => 'View renewals',
    'invoices.view'   => 'View invoices',
    'invoices.edit'   => 'Create / edit invoices',
    'payments.record' => 'Record payments',
    'payments.refund' => 'Issue Stripe refunds',
    'reports.view'    => 'View reports',
    'requests.manage' => 'Manage monthly requests',
    'leads.manage'    => 'Manage valet leads',
    'reminders.view'  => 'View reminders',
    'reminders.send'  => 'Send reminders manually',
    'templates.edit'  => 'Edit notification templates',
    'staff.manage'    => 'Manage staff & roles',
    'audit.view'      => 'View activity log',
    'settings.edit'   => 'Edit settings',
];

$__mv_all = array_keys($PERMISSIONS);
$ROLE_PERMS = [
    // Full access.
    'super_admin' => ['*'],
    // Everything except staff/role management and settings.
    'operations_manager' => array_values(array_diff($__mv_all, ['staff.manage', 'settings.edit'])),
    // Operational, non-financial: NO invoices.view (no financials at all).
    'location_manager' => [
        'locations.view', 'parkers.view', 'plates.lookup', 'renewals.view',
        'requests.manage', 'leads.manage', 'reminders.view',
    ],
    // Financial focus.
    'finance_staff' => [
        'parkers.view', 'plates.lookup', 'renewals.view',
        'invoices.view', 'invoices.edit', 'payments.record', 'payments.refund',
        'reports.view', 'reminders.view', 'companies.view',
    ],    // View-only (+ companies). requests.manage / leads.manage DENIED.
    'readonly_staff' => [
        'locations.view', 'parkers.view', 'plates.lookup', 'renewals.view',
        'invoices.view', 'reports.view', 'reminders.view', 'companies.view',
    ],
];
unset($__mv_all);

/** Does the current staff user hold $perm? False when logged out. */
function can(string $perm): bool {
    $u = current_user();
    if (!$u) {
        return false;
    }
    $perms = json_decode((string) ($u['permissions'] ?? '[]'), true);
    if (!is_array($perms)) {
        return false;
    }
    return in_array('*', $perms, true) || in_array($perm, $perms, true);
}

/** Is the current staff user in one of the given role names? */
function has_role(string ...$roles): bool {
    $u = current_user();
    if (!$u) {
        return false;
    }
    return in_array($u['role_name'] ?? '', $roles, true);
}
