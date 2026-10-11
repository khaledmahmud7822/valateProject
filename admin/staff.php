<?php
// admin/staff.php — staff user accounts: create, edit, reset password, deactivate/delete.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Staff Users';
$nav_section = 'staff';
$required_perm = 'staff.manage';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();

$roles = $db->query('SELECT * FROM roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$role_names = [];
foreach ($roles as $r) {
    $role_names[$r['id']] = $r['label'] ?? $r['name'];
}
$super_admin_role_id = null;
foreach ($roles as $r) {
    if ($r['name'] === 'super_admin') {
        $super_admin_role_id = (int) $r['id'];
        break;
    }
}

$edit_id = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($edit_id > 0) {
    $st = $db->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$edit_id]);
    $editing = $st->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = (string) ($_POST['op'] ?? '');
    $uid = (int) ($_POST['id'] ?? 0);

    if ($op === 'save') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $role_id = (int) ($_POST['role_id'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;
        $pw = (string) ($_POST['password'] ?? '');
        $errs = [];
        if ($name === '') {
            $errs[] = 'Name is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'A valid email is required.';
        }
        if (!isset($role_names[$role_id])) {
            $errs[] = 'Choose a valid role.';
        }
        $dup = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $dup->execute([$email, $uid]);
        if ($dup->fetchColumn()) {
            $errs[] = 'That email is already in use.';
        }
        $is_new = $uid === 0;
        $send_invite = $is_new && isset($_POST['send_invite']);
        if ($is_new && !$send_invite && strlen($pw) < 8) {
            $errs[] = 'New accounts need a password of at least 8 characters (or send an invitation email).';
        }
        if ($pw !== '' && strlen($pw) < 8) {
            $errs[] = 'Password must be at least 8 characters.';
        }
        // Never deactivate/demote yourself or the last super admin.
        if (!$is_new && $uid === (int) $user['id'] && ($active === 0 || $role_id !== (int) $user['role_id'])) {
            $errs[] = 'You cannot deactivate or change your own role.';
        }
        if ($errs === []) {
            if ($is_new) {
                $initial_hash = $send_invite ? password_hash(secure_token(16), PASSWORD_DEFAULT) : password_hash($pw, PASSWORD_DEFAULT);
                $db->prepare('INSERT INTO users (name, email, password_hash, role_id, active, created_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)')
                    ->execute([$name, $email, $initial_hash, $role_id, $active]);
                $nid = (int) $db->lastInsertId();
                audit('user.created', 'user', $nid, ['email' => $email, 'role_id' => $role_id]);
                if ($send_invite) {
                    // Generate a setup token and email it (reuses the reset flow).
                    $token = secure_token(32);
                    $hash = hash('sha256', $token);
                    $expires = date('Y-m-d H:i:s', time() + 48 * 3600); // 48 hours
                    $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, used) VALUES (?,?,?,0)')
                        ->execute([$nid, $hash, $expires]);
                    $setup_url = site_url('admin/reset.php?token=' . urlencode($token));
                    $role_label = $role_names[$role_id] ?? '';
                    try {
                        $sent = send_template('staff_invitation', $email, [
                            'name' => $name,
                            'role' => $role_label,
                            'setup_url' => $setup_url,
                            'business_name' => (string) setting('business_name', 'Metro Valet Parking'),
                        ], 'invite:' . $hash);
                        audit('user.invited', 'user', $nid, ['email' => $email, 'sent' => $sent]);
                        flash($sent ? 'Staff account created — invitation email sent.' : 'Staff account created, but the invitation email failed to send. Check email settings.', $sent ? 'success' : 'error');
                    } catch (Throwable $e) {
                        flash('Staff account created, but the invitation email failed to send.', 'error');
                    }
                } else {
                    flash('Staff account created.', 'success');
                }
            } else {
                // Guard the last super admin.
                $cur = $db->prepare('SELECT role_id, active FROM users WHERE id = ?');
                $cur->execute([$uid]);
                $currow = $cur->fetch(PDO::FETCH_ASSOC);
                if ($currow && $super_admin_role_id !== null && (int) $currow['role_id'] === $super_admin_role_id
                    && ($role_id !== $super_admin_role_id || $active === 0)) {
                    $cnt = $db->prepare('SELECT COUNT(*) FROM users WHERE role_id = ? AND active = 1 AND id != ?');
                    $cnt->execute([$super_admin_role_id, $uid]);
                    if ((int) $cnt->fetchColumn() === 0) {
                        $errs[] = 'Cannot demote or deactivate the last active super admin.';
                    }
                }
                if ($errs === []) {
                    if ($pw !== '') {
                        $db->prepare('UPDATE users SET name=?, email=?, role_id=?, active=?, password_hash=? WHERE id=?')
                            ->execute([$name, $email, $role_id, $active, password_hash($pw, PASSWORD_DEFAULT), $uid]);
                        audit('user.password_reset', 'user', $uid, ['email' => $email]);
                    } else {
                        $db->prepare('UPDATE users SET name=?, email=?, role_id=?, active=? WHERE id=?')
                            ->execute([$name, $email, $role_id, $active, $uid]);
                        audit('user.updated', 'user', $uid, ['email' => $email]);
                    }
                    flash('Staff account updated.', 'success');
                }
            }
        }
        foreach ($errs as $er) {
            flash($er, 'error');
        }
        redirect(site_url('admin/staff.php'));
    }

    if ($op === 'delete') {
        if ($uid === (int) $user['id']) {
            flash('You cannot delete your own account.', 'error');
        } else {
            $st = $db->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([$uid]);
            $target = $st->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                flash('Account not found.', 'error');
            } elseif ($super_admin_role_id !== null && (int) $target['role_id'] === $super_admin_role_id) {
                $cnt = $db->prepare('SELECT COUNT(*) FROM users WHERE role_id = ? AND active = 1 AND id != ?');
                $cnt->execute([$super_admin_role_id, $uid]);
                if ((int) $cnt->fetchColumn() === 0) {
                    flash('Cannot delete the last active super admin.', 'error');
                    redirect(site_url('admin/staff.php'));
                }
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                audit('user.deleted', 'user', $uid, ['email' => $target['email']]);
                flash('Staff account deleted.', 'success');
            } else {
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                audit('user.deleted', 'user', $uid, ['email' => $target['email']]);
                flash('Staff account deleted.', 'success');
            }
        }
        redirect(site_url('admin/staff.php'));
    }

    redirect(site_url('admin/staff.php'));
}

$users = $db->query('SELECT u.*, r.label AS role_label, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.name')->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/_layout.php';
?>

<div class="page-head"><h1>Staff Users</h1></div>

<div class="grid-2" style="align-items:start">
    <div class="card">
        <h3><?= $editing ? 'Edit staff account' : 'Add staff account' ?></h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="save">
            <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : 0 ?>">
            <div class="field">
                <label for="name">Name *</label>
                <input type="text" id="name" name="name" value="<?= e($editing['name'] ?? '') ?>" required maxlength="200">
            </div>
            <div class="field">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" value="<?= e($editing['email'] ?? '') ?>" required maxlength="200">
            </div>
            <div class="field">
                <label for="role_id">Role *</label>
                <select id="role_id" name="role_id" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int) $r['id'] ?>"<?= $editing && (int) $editing['role_id'] === (int) $r['id'] ? ' selected' : '' ?>>
                            <?= e($r['label'] ?? $r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="password"><?= $editing ? 'New password (blank = keep current)' : 'Password *' ?></label>
                <input type="password" id="password" name="password" autocomplete="new-password"<?= $editing ? '' : ' required' ?> minlength="8">
                <div class="hint">At least 8 characters. Setting a password here resets the user's login.</div>
            </div>
            <?php if (!$editing): ?>
            <div class="field">
                <label class="check-line"><input type="checkbox" name="send_invite" value="1" id="send_invite" checked> Send invitation email — the user sets their own password</label>
                <div class="hint">When checked, no password is needed here. They'll get an email with a setup link (valid 48 hours).</div>
            </div>
            <script>
            document.getElementById('send_invite').addEventListener('change', function () {
                document.getElementById('password').required = !this.checked;
            });
            document.getElementById('password').required = document.getElementById('send_invite').checked ? false : true;
            </script>
            <?php endif; ?>
            <div class="field">
                <label class="check-line"><input type="checkbox" name="active" value="1"<?= !$editing || (int) $editing['active'] === 1 ? ' checked' : '' ?>> Active (can sign in)</label>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create account' ?></button>
                <?php if ($editing): ?>
                    <a class="btn btn-ghost" href="<?= e(site_url('admin/staff.php')) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Accounts</h3>
        <?php if ($users === []): ?>
            <div class="empty"><strong>No staff accounts</strong></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><strong><?= e($u['name']) ?></strong><br><span class="muted small"><?= e($u['email']) ?></span>
                                    <?= $u['mfa_secret'] ? '<br><span class="badge badge-navy">MFA on</span>' : '' ?>
                                    <?php if ((int) $u['id'] === (int) $user['id']): ?><br><span class="badge badge-gold">You</span><?php endif; ?>
                                </td>
                                <td><?= e($u['role_label'] ?? $u['role_name'] ?? '—') ?></td>
                                <td>
                                    <?php if ((int) $u['active'] === 1): ?>
                                        <span class="badge badge-green"><?= status_dot('green', 'Active') ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-gray"><?= status_dot('gray', 'Inactive') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/staff.php?edit=' . (int) $u['id'])) ?>">Edit</a>
                                        <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                                            <form method="post" class="inline-form" data-confirm="Delete this staff account? They will lose access immediately.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="op" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
