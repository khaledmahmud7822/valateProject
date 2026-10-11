<?php
/**
 * Metro Valet Parking — authentication (admin + customer portal).
 *
 * Two separate sessions namespaces in one PHP session:
 *   $_SESSION['admin_id']                        — logged-in staff user
 *   $_SESSION['customer_id'] / ['customer_kind']  — logged-in portal user
 *   $_SESSION['customer_scope'] / ['customer_scope_id'] — 'customer' or 'company'
 *
 * customer_kind is 'customer' (customers table) or 'company_contact'
 * (company_contacts table, sees company scope). Defines functions only.
 */

/** Currently logged-in staff user row (with role name/label/permissions), or null. */
function current_user(): ?array {
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $st = db()->prepare(
        "SELECT u.*, r.name AS role_name, r.label AS role_label, r.permissions
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND u.active = 1"
    );
    $st->execute([(int) $_SESSION['admin_id']]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Redirect to the admin login page unless a staff user is logged in. */
function require_login(): void {
    if (!current_user()) {
        redirect(site_url('admin/login.php'));
    }
}

/**
 * Currently logged-in portal user, or null.
 * Returns the customers row (kind='customer') or the company_contacts row
 * (kind='company_contact', with company_name joined in).
 */
function current_customer(): ?array {
    if (empty($_SESSION['customer_id'])) {
        return null;
    }
    $id = (int) $_SESSION['customer_id'];
    $kind = $_SESSION['customer_kind'] ?? 'customer';
    if ($kind === 'company_contact') {
        $st = db()->prepare(
            "SELECT cc.*, 'company_contact' AS kind, co.name AS company_name
             FROM company_contacts cc
             LEFT JOIN companies co ON co.id = cc.company_id
             WHERE cc.id = ?"
        );
        $st->execute([$id]);
    } else {
        $st = db()->prepare(
            "SELECT c.*, 'customer' AS kind
             FROM customers c
             WHERE c.id = ? AND c.status = 'active'"
        );
        $st->execute([$id]);
    }
    $row = $st->fetch();
    return $row ?: null;
}

/** Redirect to the portal login unless a customer is logged in. Returns the row. */
function require_customer(): array {
    $c = current_customer();
    if (!$c) {
        redirect(site_url('portal/login.php'));
    }
    return $c;
}

/** Log a staff user in: regenerate session ID, set admin namespace. */
function login_user(array $user): void {
    session_regenerate_id(true);
    unset($_SESSION['customer_id'], $_SESSION['customer_kind'], $_SESSION['customer_scope'], $_SESSION['customer_scope_id']);
    $_SESSION['admin_id'] = (int) ($user['id'] ?? 0);
}

/** Log everyone out of this session (admin + customer). */
function logout_user(): void {
    unset($_SESSION['admin_id'], $_SESSION['customer_id'], $_SESSION['customer_kind'], $_SESSION['customer_scope'], $_SESSION['customer_scope_id']);
    session_regenerate_id(true);
}

/**
 * Log a portal user in.
 * $customer: customers row or company_contacts row (should carry 'kind').
 * $scope: 'customer' (own data) or 'company' (whole company).
 * $scope_id: company id when $scope is 'company', else the customer id.
 */
function customer_login(array $customer, string $scope = 'customer', $scope_id = null): void {
    session_regenerate_id(true);
    unset($_SESSION['admin_id']);
    $_SESSION['customer_id'] = (int) ($customer['id'] ?? 0);
    $_SESSION['customer_kind'] = $customer['kind'] ?? 'customer';
    $_SESSION['customer_scope'] = $scope;
    $_SESSION['customer_scope_id'] = $scope_id !== null
        ? (int) $scope_id
        : (int) ($customer['company_id'] ?? $customer['id'] ?? 0);
}
