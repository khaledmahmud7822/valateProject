<?php
// admin/_layout.php — shared admin chrome.
// Expects: $page_title, $nav_section (set by the page), and a logged-in user
// (enforced by _guard.php, which must be included BEFORE this file).
// Prints <head>, sidebar, topbar and opens <main class="content">.
// The page then prints its content and includes admin/_footer.php.
if (!isset($page_title)) {
    $page_title = 'Admin';
}
if (!isset($nav_section)) {
    $nav_section = 'dashboard';
}
$__user = current_user();
$__business = setting('business_name', 'Metro Valet Parking');

// Sidebar navigation. 'perm' = single required permission, 'any' = any-of list,
// perm omitted/null = visible to any logged-in user.
$__nav = [
    'Overview' => [
        ['label' => 'Dashboard', 'url' => 'admin/index.php', 'key' => 'dashboard'],
    ],
    'Parking Operations' => [
        ['label' => 'Locations', 'url' => 'admin/locations.php', 'key' => 'locations', 'perm' => 'locations.view'],
        ['label' => 'Monthly Parkers', 'url' => 'admin/parkers.php', 'key' => 'parkers', 'perm' => 'parkers.view'],
        ['label' => 'Resident Parking', 'url' => 'admin/parkers.php?category=resident', 'key' => 'residents', 'perm' => 'parkers.view'],
        ['label' => 'Plate Lookup', 'url' => 'admin/plate_lookup.php', 'key' => 'plate_lookup', 'perm' => 'plates.lookup'],
        ['label' => 'Corporate Accounts', 'url' => 'admin/companies.php', 'key' => 'companies', 'perm' => 'companies.view'],
        ['label' => 'Renewals', 'url' => 'admin/renewals.php', 'key' => 'renewals', 'perm' => 'renewals.view'],
    ],
    'Financial' => [
        ['label' => 'Invoices', 'url' => 'admin/invoices.php', 'key' => 'invoices', 'perm' => 'invoices.view'],
        ['label' => 'Payments', 'url' => 'admin/payments.php', 'key' => 'payments', 'any' => ['payments.record', 'invoices.view']],
        ['label' => 'Outstanding', 'url' => 'admin/invoices.php?status=unpaid', 'key' => 'outstanding', 'perm' => 'invoices.view'],
        ['label' => 'Revenue Reports', 'url' => 'admin/reports.php', 'key' => 'reports', 'perm' => 'reports.view'],
    ],
    'Business Development' => [
        ['label' => 'Monthly Requests', 'url' => 'admin/requests.php', 'key' => 'requests', 'perm' => 'requests.manage'],
        ['label' => 'Valet Leads', 'url' => 'admin/leads.php', 'key' => 'leads', 'perm' => 'leads.manage'],
        ['label' => 'Quotes', 'url' => 'admin/leads.php?stage=quoted', 'key' => 'quotes', 'perm' => 'leads.manage'],
    ],
    'Communications' => [
        ['label' => 'Reminders', 'url' => 'admin/reminders.php', 'key' => 'reminders', 'perm' => 'reminders.view'],
        ['label' => 'Email Templates', 'url' => 'admin/templates.php', 'key' => 'templates', 'perm' => 'templates.edit'],
        ['label' => 'Notification History', 'url' => 'admin/notifications.php', 'key' => 'notifications', 'perm' => 'reminders.view'],
    ],
    'Careers & Applications' => [
        ['label' => 'Job Postings', 'url' => 'admin/jobs.php', 'key' => 'jobs', 'perm' => 'careers.manage'],
        ['label' => 'Applications', 'url' => 'admin/applications.php', 'key' => 'applications', 'perm' => 'applications.manage'],
    ],
    'System' => [
        ['label' => 'Staff Users', 'url' => 'admin/staff.php', 'key' => 'staff', 'perm' => 'staff.manage'],
        ['label' => 'Roles & Permissions', 'url' => 'admin/roles.php', 'key' => 'roles', 'perm' => 'staff.manage'],
        ['label' => 'Audit Log', 'url' => 'admin/audit.php', 'key' => 'audit', 'perm' => 'audit.view'],
        ['label' => 'Settings', 'url' => 'admin/settings.php', 'key' => 'settings', 'perm' => 'settings.edit'],
    ],
];


// SVG icons for sidebar nav (16px, stroke-based).
$__nav_icons = [
    'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
    'locations' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
    'parkers' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'residents' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/></svg>',
    'plate_lookup' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>',
    'companies' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 21v-4h6v4M8 7h2M8 11h2M14 7h2M14 11h2M8 15h8"/></svg>',
    'renewals' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>',
    'invoices' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></svg>',
    'payments' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
    'outstanding' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>',
    'reports' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M7 15v3M12 10v8M17 6v12"/></svg>',
    'requests' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/></svg>',
    'leads' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>',
    'quotes' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2H2v10l9.3 9.3a1 1 0 0 0 1.4 0l8.6-8.6a1 1 0 0 0 0-1.4Z"/><circle cx="7" cy="7" r="1.5"/></svg>',
    'reminders' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
    'templates' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>',
    'notifications' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/></svg>',
    'staff' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>',
    'roles' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/></svg>',
    'audit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',
    'jobs' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>',
    'applications' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></svg>',
    'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>',
];
$__nav_visible = function ($item) {
    if (isset($item['perm']) && $item['perm']) {
        return can($item['perm']);
    }
    if (isset($item['any']) && is_array($item['any'])) {
        foreach ($item['any'] as $__p) {
            if (can($__p)) {
                return true;
            }
        }
        return false;
    }
    return true;
};

// Resolve the current user's role label for the topbar.
$__role_label = $__user['role_label'] ?? null;
if ($__role_label === null && !empty($__user['role_id'])) {
    try {
        $__st = db()->prepare('SELECT label FROM roles WHERE id = ?');
        $__st->execute([$__user['role_id']]);
        $__role_label = $__st->fetchColumn() ?: '';
    } catch (Exception $ex) {
        $__role_label = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> — <?= e($__business) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(site_url('assets/css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(site_url('assets/css/command.css')) ?>?v=1">
</head>
<body class="admin">
<div class="admin-shell" id="adminShell">
    <aside class="sidebar" id="sidebar" aria-label="Admin navigation">
        <div class="sidebar-brand">
            <a href="<?= e(site_url('admin/index.php')) ?>" class="brand-link">
                <span class="brand-mark">
                    <svg viewBox="0 0 32 32" fill="none"><rect x="2" y="2" width="28" height="28" rx="8" fill="#D7AD49"/><path d="M9 22V10l7 7 7-7v12" stroke="#0D2038" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="brand-text">
                    <span class="brand-name"><?= e($__business) ?></span>
                    <span class="brand-sub">Command Center</span>
                </span>
            </a>
        </div>
        <nav class="sidebar-nav" id="sidebarNav">
            <?php foreach ($__nav as $__section => $__items): ?>
                <?php
                $__shown = array_values(array_filter($__items, $__nav_visible));
                if ($__shown === []) {
                    continue;
                }
                ?>
                <div class="nav-group">
                    <div class="nav-group-title"><?= e($__section) ?></div>
                    <div class="nav-group-items">
                        <?php foreach ($__shown as $__item): ?>
                            <a class="nav-link<?= $nav_section === $__item['key'] ? ' active' : '' ?>"
                               href="<?= e(site_url($__item['url'])) ?>"
                               <?= $nav_section === $__item['key'] ? 'aria-current="page"' : '' ?>>
                                <span class="nav-link-icon"><?= $__nav_icons[$__item['key']] ?? '' ?></span>
                                <span class="nav-link-label"><?= e($__item['label']) ?></span>
                                <?php if ($nav_section === $__item['key']): ?><span class="nav-link-active-bar" aria-hidden="true"></span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-profile">
            <div class="profile-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($__user['name'] ?? '?', 0, 1))) ?></div>
            <div class="profile-info">
                <div class="profile-name"><?= e($__user['name'] ?? '') ?></div>
                <?php if ($__role_label !== ''): ?><div class="profile-role"><?= e($__role_label) ?></div><?php endif; ?>
            </div>
            <a class="profile-logout" href="<?= e(site_url('admin/logout.php')) ?>" title="Log out" aria-label="Log out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>
            </a>
        </div>
    </aside>
    <div class="sidebar-scrim" id="sidebarScrim" hidden></div>

    <div class="admin-main">
        <header class="topbar">
            <button class="icon-btn drawer-btn" id="drawerBtn" aria-label="Open navigation">&#9776;</button>
            <button class="icon-btn collapse-btn" id="collapseBtn" aria-label="Collapse sidebar" title="Collapse sidebar">&#10094;</button>
            <div class="topbar-title">
                <span class="topbar-business"><?= e($__business) ?></span>
                <span class="topbar-crumb"><?= e($page_title) ?></span>
            </div>
            <div class="topbar-user">
                <span class="topbar-user-name"><?= e($__user['name'] ?? '') ?></span>
                <?php if ($__role_label !== ''): ?>
                    <span class="badge badge-gray"><?= e($__role_label) ?></span>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/logout.php')) ?>">Log out</a>
            </div>
        </header>

        <main class="content" id="content">
            <?php
            $__flashes = [];
            try {
                $__flashes = flashes();
            } catch (Exception $ex) {
                $__flashes = [];
            }
            foreach ((array) $__flashes as $__f) {
                $__ftype = '';
                if (is_array($__f)) {
                    $__ftype = $__f['type'] ?? $__f['t'] ?? 'info';
                    $__fmsg = $__f['message'] ?? $__f['msg'] ?? $__f['text'] ?? '';
                } else {
                    $__fmsg = (string) $__f;
                    $__ftype = 'info';
                }
                if ($__fmsg === '') {
                    continue;
                }
                ?>
                <div class="flash flash-<?= e($__ftype) ?>" role="alert"><?= e($__fmsg) ?></div>
                <?php
            }
            unset($__f, $__ftype, $__fmsg, $__flashes);
            ?>
