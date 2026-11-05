<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>StudentShield &middot; <?= esc(ucfirst($page)) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<?= view('partials/school_brand') ?>
<link rel="stylesheet" href="<?= base_url('css/staff-layout.css?v=' . filemtime(FCPATH . 'css/staff-layout.css')) ?>">
<link rel="stylesheet" href="<?= base_url('css/role-workspaces.css?v=' . filemtime(FCPATH . 'css/role-workspaces.css')) ?>">
<link rel="stylesheet" href="<?= base_url('css/staff-account.css?v=' . filemtime(FCPATH . 'css/staff-account.css')) ?>">
<?= view('partials/ui_polish') ?>
</head><body class="school-staff-page staff-layout role-workspace">
<?= view('partials/staff_navigation', ['staffPage' => $page, 'staffPageLabel' => ucfirst($page)]) ?>
<?php
$home = base_url(ltrim(\App\Libraries\RoleAccess::home((int) $account['role']), '/'));
$role = (int) $account['role'];
$initial = mb_strtoupper(mb_substr($account['name'], 0, 1));
$description = ['profile' => 'Your identity and access within StudentShield.', 'settings' => 'Keep your account secure.', 'help' => 'Practical guidance for your daily work.'];
?>
<main id="staffMain" class="staff-main role-main account-page">
<header class="account-page-heading"><div><h1><?= esc(ucfirst($page)) ?></h1><p><?= esc($description[$page]) ?></p></div><a class="account-back" href="<?= $home ?>">&larr; Back to workspace</a></header>
<?php foreach (['success', 'error'] as $key): if ($message = session()->getFlashdata($key)): ?><p class="role-notice" role="status"><?= esc($message) ?></p><?php endif; endforeach; ?>
<?php if ($page === 'profile'): ?>
<div class="account-page-grid">
<section class="account-identity"><div class="account-monogram" aria-hidden="true"><?= esc($initial) ?></div><h2><?= esc($account['name']) ?></h2><span class="account-role-label"><?= esc($roleName) ?></span><div class="identity-rule"></div><p>School workspace</p><strong>StudentShield</strong><p>St. Vincent Blessed School of Manila</p></section>
<div class="account-page-content">
<section class="account-surface"><div class="account-section-title"><h2>Personal details</h2><span>Account #<?= (int) $account['id'] ?></span></div>
<form method="post" action="<?= base_url('staff/profile') ?>" class="profile-edit-form">
<?= csrf_field() ?>
<label for="profileName">Account name<input id="profileName" name="name" autocomplete="name" required maxlength="255" value="<?= esc($account['name'], 'attr') ?>" aria-describedby="profileNameHint"></label>
<p id="profileNameHint" class="profile-field-hint">This is also the name you use to sign in.</p>
<label for="profilePhone"><span>Phone number <span class="profile-optional">(optional)</span></span><input id="profilePhone" name="phone" type="tel" autocomplete="tel" maxlength="20" value="<?= esc($account['phone'] ?? '', 'attr') ?>"></label>
<dl class="account-detail-list"><div><dt>Assigned role</dt><dd><?= esc($roleName) ?></dd></div><div><dt>Sign-in email</dt><dd><?= esc(($account['email'] ?? '') ?: 'Not set') ?></dd></div></dl>
<p class="profile-field-hint">Contact your account administrator to update your sign-in email or role.</p>
<label for="profilePassword">Current password<input id="profilePassword" name="current_password" type="password" autocomplete="current-password" required aria-describedby="profilePasswordHint"></label>
<p id="profilePasswordHint" class="profile-field-hint">Confirm your password to save these changes.</p>
<div class="profile-save-actions"><button type="submit">Save profile</button><button type="reset" class="secondary">Reset changes</button></div>
</form></section>
<section class="account-surface"><h2>Your workspace access</h2><p><?= $role === 2 ? 'Account management and system audit activity.' : ($role === 1 ? 'Student case review, consultations and registration decisions.' : 'School-level analytics and aggregate report exports.') ?></p><div class="account-access-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3l8 4v6c0 5-8 8-8 8s-8-3-8-8V7zM8 12l3 3 5-6"/></svg><span>Access follows your assigned role. Student clinical details are restricted to the guidance office.</span></div></section>
</div></div>
<?php endif; ?>
</main><?= view('partials/session_idle_modal') ?></body></html>
