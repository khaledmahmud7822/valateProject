<?php
require __DIR__ . '/../lib/bootstrap.php';

/* Destroy the session completely, then start a fresh one so we can flash. */
$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
session_start();

flash('You have been signed out.', 'info');
redirect(site_url('portal/login.php'));
