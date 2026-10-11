<?php
// admin/logout.php — end the staff session.
require __DIR__ . '/../lib/bootstrap.php';

$user = null;
try {
    $user = current_user();
} catch (Exception $ex) {
    $user = null;
}
if ($user) {
    // audit() never throws; the actor is derived from the session.
    audit('user.logout', 'user', $user['id']);
}

// Clears the admin + customer session namespaces and regenerates the ID.
logout_user();

redirect(site_url('admin/login.php'));
