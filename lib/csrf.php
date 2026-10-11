<?php
/**
 * Metro Valet Parking — CSRF protection.
 *
 * One token per session. Every state-changing form includes csrf_field();
 * every POST handler calls verify_csrf() before doing anything.
 * Defines functions only — no side effects at require time.
 */

/** Get (or create) the session's CSRF token. */
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Hidden input HTML to embed in forms. */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the POSTed token against the session token.
 * Dies with HTTP 403 on mismatch — call before any state change.
 */
function verify_csrf(): void {
    $posted = $_POST['_csrf'] ?? null;
    $session = $_SESSION['_csrf'] ?? null;
    $ok = is_string($posted) && is_string($session) && hash_equals($session, $posted);
    if (!$ok) {
        http_response_code(403);
        exit('Forbidden: invalid or missing CSRF token.');
    }
}
