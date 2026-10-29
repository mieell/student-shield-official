<?php
$staffPage = $staffPage ?? 'vents';
$staffPageLabel = $staffPageLabel ?? 'Vents';
$staffRole = \App\Libraries\RoleAccess::NAMES[(int) session()->get('role')] ?? 'Staff';
$staffName = (string) (session()->get('name') ?: $staffRole);
$staffInitial = mb_strtoupper(mb_substr($staffName, 0, 1));
$staffLinks = [
    'vents' => ['Vents', 'admin/vents', 'M8 10h8M8 14h5M21 12a9 9 0 01-9 9H3l2-4a9 9 0 1116-5z'],
    'incidents' => ['Incidents', 'admin/incidents', 'M12 9v2m0 4h.01M10.3 4.9L2.9 18a2 2 0 001.7 3h14.8a2 2 0 001.7-3L13.7 4.9a2 2 0 00-3.4 0z'],
    'sessions' => ['Sessions', 'admin/sessions', 'M15 10l5-3v10l-5-3M5 6h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z'],
    'alerts' => ['Alerts', 'admin/alerts', 'M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4'],
    'analytics' => ['Analytics', 'admin/analytics', 'M3 21h18M5 17V9h3v8M11 17V5h3v12M17 17V2h3v15'],
];
$staffAdminLinks = [];
if ((int) session()->get('role') === 1) {
    $staffAdminLinks['registrations'] = ['Registrations', 'admin/pending-registrations', 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M13 7a4 4 0 11-8 0 4 4 0 018 0M20 8v6M17 11h6'];
} elseif ((int) session()->get('role') === 2) {
    $staffLinks = [
        'users' => ['Accounts', 'admin/users', 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M13 7a4 4 0 11-8 0 4 4 0 018 0M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75'],
        'audit-logs' => ['Audit Logs', 'admin/audit-logs', 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6M8 13h8M8 17h6'],
    ];
    $showSessionRequests = false;
} elseif ((int) session()->get('role') === 3) {
    $staffLinks = ['principal' => ['School overview', 'principal/dashboard', 'M3 21h18M5 17V9h3v8M11 17V5h3v12M17 17V2h3v15']];
    $showSessionRequests = false;
}
?>
<link rel="stylesheet" href="<?= base_url('css/staff-filters.css?v=' . filemtime(FCPATH . 'css/staff-filters.css')) ?>">
<link rel="stylesheet" href="<?= base_url('css/staff-refinement.css?v=' . filemtime(FCPATH . 'css/staff-refinement.css')) ?>">
<a class="staff-skip-link" href="#staffMain">Skip to content</a>
<button type="button" id="staffBackdrop" class="staff-backdrop" aria-label="Close navigation" tabindex="-1" hidden></button>
<aside id="staffSidebar" class="staff-sidebar" aria-label="Staff navigation" tabindex="-1">
    <div class="staff-brand">
        <img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM logo" width="44" height="44">
        <div class="staff-brand-copy"><strong>StudentShield</strong><span>Saint Vincent Blessed School</span></div>
        <button type="button" id="staffSidebarClose" class="staff-sidebar-close" aria-label="Close navigation">×</button>
    </div>
    <div class="staff-sidebar-scroll">
        <?php foreach (['Workspace' => $staffLinks, 'Administration' => $staffAdminLinks] as $group => $links): ?>
        <?php if (empty($links)) continue; ?>
        <p class="staff-nav-label <?= $group === 'Workspace' ? 'staff-workspace-navigation' : '' ?>"><?= esc($group) ?></p>
        <nav class="<?= $group === 'Workspace' ? 'staff-workspace-navigation' : '' ?>" aria-label="<?= $group === 'Workspace' ? 'Staff pages' : 'Administration' ?>">
            <?php foreach ($links as $key => [$label, $url, $path]): ?>
            <a href="<?= base_url($url) ?>" class="staff-nav-link" <?= $staffPage === $key ? 'aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= esc($path, 'attr') ?>"/></svg>
                <span><?= esc($label) ?></span>
                <?php if ($key === 'registrations'): ?>
                <span id="navRegistrationsBadge" class="staff-nav-count" hidden></span>
                <?php endif; ?>
                <?php if ($key === 'sessions' && !empty($showSessionRequests)): ?>
                <span id="navSessionsBadge" class="staff-nav-count <?= ($pending_meetings_count ?? 0) > 0 ? '' : 'hidden' ?>"><?= esc($pending_meetings_count ?? 0) ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endforeach; ?>
    </div>
    <div class="staff-sidebar-account">
        <details id="staffAccountMenu" class="staff-account-menu">
            <summary class="staff-account" aria-label="Account options for <?= esc($staffName, 'attr') ?>"><span class="staff-avatar"><?= esc($staffInitial) ?></span><span class="staff-account-name"><strong><?= esc($staffName) ?></strong><?php if (strcasecmp(trim($staffName), $staffRole) !== 0): ?><span><?= esc($staffRole) ?></span><?php endif; ?></span><svg class="staff-account-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M8 14l4-4 4 4"/></svg></summary>
            <div class="staff-account-popover">
                <a class="staff-utility-link" href="<?= base_url('staff/profile') ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 12a4 4 0 100-8 4 4 0 000 8M5 21v-2a7 7 0 0114 0v2"/></svg>Profile</a>
                <a class="staff-utility-link" href="<?= base_url('staff/settings') ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 7h16M4 17h16M8 4v6M16 14v6"/></svg>Settings</a>
                <form action="<?= base_url('logout') ?>" method="POST" class="staff-signout">
                    <?= csrf_field() ?>
                    <button type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M9 5H5v14h4M14 8l4 4-4 4M8 12h10"/></svg>Sign out</button>
                </form>
            </div>
        </details>
    </div>
</aside>
<header id="dashboardHeader" class="staff-topbar">
    <div id="mainNavBar" class="staff-topbar-inner">
        <button type="button" id="staffMenuButton" class="staff-menu-button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="staffSidebar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="staff-mobile-brand"><img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM logo" width="28" height="28"><strong>StudentShield</strong></div>
        <div class="staff-school-name">Saint Vincent Blessed School of Manila</div>
        <div class="staff-topbar-tools">
            <?php if ((int) session()->get('role') === 1): ?>
            <a id="registrationNotification" class="staff-registration-notice" hidden href="<?= base_url('admin/pending-registrations') ?>" data-count-url="<?= base_url('admin/api/pending-registrations') ?>" aria-label="Review student registrations">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M13 7a4 4 0 11-8 0 4 4 0 018 0M20 8v6M17 11h6"/></svg>
                <span class="staff-registration-label">Registrations</span>
                <span id="registrationNotificationCount" class="staff-nav-count" hidden></span>
            </a>
            <span id="registrationNotificationStatus" class="sr-only" role="status" aria-live="polite"></span>
            <?php endif; ?>
            <div class="staff-clock"><span id="liveDayDate"><?= date('D, j M Y') ?></span><span id="liveTime"><?= date('g:i:s A') ?></span></div>
            <?php if (!empty($showSessionRequests)): ?><?= view('partials/staff_session_requests', ['pending_meetings_count' => $pending_meetings_count ?? 0]) ?><?php endif; ?>
        </div>
    </div>
</header>
<nav class="staff-mobile-bottom-nav" aria-label="Mobile staff pages" style="--staff-nav-count: <?= count($staffLinks) ?>">
    <?php foreach ($staffLinks as $key => [$label, $url, $path]): ?>
    <a href="<?= base_url($url) ?>" <?= $staffPage === $key ? 'aria-current="page"' : '' ?>>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= esc($path, 'attr') ?>"/></svg>
        <span><?= esc($label) ?></span>
    </a>
    <?php endforeach; ?>
</nav>
<script src="<?= base_url('js/staff-layout.js?v=' . filemtime(FCPATH . 'js/staff-layout.js')) ?>" defer></script>
