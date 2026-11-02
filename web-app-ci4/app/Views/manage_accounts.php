<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>StudentShield · System accounts</title>
<script src="https://cdn.tailwindcss.com"></script>
<?= view('partials/school_brand') ?>
<link rel="stylesheet" href="<?= base_url('css/staff-layout.css?v=' . filemtime(FCPATH . 'css/staff-layout.css')) ?>">
<link rel="stylesheet" href="<?= base_url('css/role-workspaces.css?v=' . filemtime(FCPATH . 'css/role-workspaces.css')) ?>">
<link rel="stylesheet" href="<?= base_url('css/accounts-directory.css?v=' . filemtime(FCPATH . 'css/accounts-directory.css')) ?>">
<?= view('partials/ui_polish') ?>
</head><body class="school-staff-page staff-layout role-workspace">
<?= view('partials/staff_navigation', ['staffPage' => 'users', 'staffPageLabel' => 'System accounts']) ?>
<main id="staffMain" class="staff-main role-main accounts-workspace">
<header class="accounts-heading"><div class="workspace-title"><span class="workspace-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m20 0v-2a4 4 0 00-3-3.87M16 3a4 4 0 010 8"/><circle cx="9" cy="7" r="4"/></svg></span><div><h1>System accounts</h1><p>Manage accounts, roles and access.</p></div></div><button type="button" id="createAccountToggle" aria-controls="accountCreatePanel" aria-expanded="false" hidden>+ Create account</button></header>
<?php foreach (['success', 'error'] as $key): if ($message = session()->getFlashdata($key)): ?><p role="status" class="role-notice"><?= esc($message) ?></p><?php endif; endforeach; ?>
<details id="accountCreatePanel" class="role-panel account-create"><summary>Create an account</summary>
<form method="post" action="<?= base_url('admin/users/create') ?>" class="account-fields">
<?= csrf_field() ?>
<label>Account name<input name="name" required maxlength="255" autocomplete="off" placeholder="e.g. Guidance Counselor 1"></label>
<label>Role<select name="role"><?php foreach (\App\Libraries\RoleAccess::NAMES as $value => $label): ?><option value="<?= $value ?>"><?= esc($label) ?></option><?php endforeach; ?></select></label>
<label>Password<input name="passcode" type="password" minlength="8" required autocomplete="new-password" placeholder="Minimum 8 characters"></label>
<label>Staff email<input name="email" type="email" placeholder="staff@school.edu.ph (for OTP verification)"></label>
<p>New Student accounts remain pending until a Counselor approves them. Student profile and guardian details are managed by the guidance office.</p>
<button type="submit">Create account</button>
</form></details>
<div class="accounts-master-detail">
<section class="role-panel directory-panel" aria-label="Account directory">
<div class="accounts-directory-heading"><h2>Account directory</h2><span id="accountResultCount" role="status"><?= count($users) ?> <?= count($users) === 1 ? 'account' : 'accounts' ?></span></div>
<div class="account-role-tabs" aria-label="Filter accounts by role" hidden>
<?php foreach (['' => 'All', 0 => 'Students', 1 => 'Counselors', 2 => 'Admins', 3 => 'Principal'] as $value => $label): ?>
<button type="button" data-role-filter="<?= $value ?>" aria-pressed="<?= $value === '' ? 'true' : 'false' ?>"><?= $label ?><span><?= count(array_filter($users, static fn ($user) => $value === '' || (int) $user['role'] === (int) $value)) ?></span></button>
<?php endforeach; ?></div>
<div class="accounts-search-line"><label for="accountSearch">Search accounts<input type="search" id="accountSearch" placeholder="Search name, student ID or role"></label>
<details class="account-filter-panel"><summary>Filters</summary><div class="account-toolbar">
<label>Role<select id="accountRole"><option value="">All roles</option><?php foreach (\App\Libraries\RoleAccess::NAMES as $value => $label): ?><option value="<?= $value ?>"><?= esc($label) ?></option><?php endforeach; ?></select></label>
<label>Account access<select id="accountAccess"><option value="">All access statuses</option><option value="0">Enabled</option><option value="1">Disabled</option></select></label>
</div></details></div>
<div class="account-list" role="table" aria-label="System accounts">
<div class="account-column-headings" role="row"><span role="columnheader">Name</span><span role="columnheader">Role</span><span role="columnheader">Access</span><span role="columnheader">Details</span></div>
<?php foreach ($users as $user): 
    $user['name'] = $user['name'] ?? ('Student account #' . (int) $user['id']); 
    $role = (int) $user['role'];
    $roleLabel = \App\Libraries\RoleAccess::NAMES[$role] ?? 'Unknown';
    $roleBadgeClass = $role === 2 ? 'badge-admin' : ($role === 3 ? 'badge-principal' : ($role === 1 ? 'badge-counselor' : 'badge-student'));
?>
<article class="account-row" role="row" data-name="<?= esc($user['name'], 'attr') ?>" data-role="<?= $role ?>" data-disabled="<?= (int) $user['access_disabled'] ?>" data-account="<?= esc(strtolower($user['name'] . ' ' . $roleLabel . ' ' . ($user['student_id'] ?? '')), 'attr') ?>">
<div class="directory-identity" role="cell"><span class="directory-avatar" aria-hidden="true"><?= esc(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span><div>
    <strong><?= esc($user['name']) ?></strong>
    <p>
        <span><?= esc(($user['student_id'] ?? '') ?: ('Account #' . (int) $user['id'])) ?></span>
    </p>
</div></div>
<div role="cell" class="directory-role"><span class="role-badge <?= $roleBadgeClass ?>"><?= esc($roleLabel) ?></span></div>
<div role="cell" class="directory-status">
        <span class="status-badge <?= $user['access_disabled'] ? 'badge-disabled' : 'badge-enabled' ?>"><?= $user['access_disabled'] ? 'Disabled' : 'Enabled' ?></span>
        <?php if ($role === 0 && !empty($user['registration_status'])): ?>
            <small><?= esc(ucfirst($user['registration_status'])) ?> registration</small>
        <?php endif; ?>
</div>
<div role="cell" class="directory-actions">
<button type="button" class="account-inspect-button secondary" hidden aria-controls="accountInspector" aria-expanded="false" aria-label="View details for <?= esc($user['name'], 'attr') ?>">View <span aria-hidden="true">→</span></button>
<details class="account-access-editor"><summary>Edit access</summary>
<form method="post" action="<?= base_url('admin/users/update/' . (int) $user['id']) ?>" class="account-fields">
<?= csrf_field() ?>
<?php if ($role !== 0): ?><label>Account name<input name="name" value="<?= esc($user['name'], 'attr') ?>" maxlength="255" required></label><?php endif; ?>
<label>Role<select name="role"><?php foreach (\App\Libraries\RoleAccess::NAMES as $value => $label): ?><option value="<?= $value ?>" <?= $role === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
<label>Reset password<input name="passcode" type="password" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep current"></label>
<label>Update staff email<input name="email" type="email" placeholder="Leave blank to keep current"></label>
<button type="submit">Save account</button></form>
<?php if ((int) $user['id'] !== (int) session()->get('user_id')): ?>
<form method="post" action="<?= base_url('admin/users/access/' . (int) $user['id']) ?>" class="account-access"><?= csrf_field() ?><input type="hidden" name="disabled" value="<?= $user['access_disabled'] ? '0' : '1' ?>"><button type="submit" class="secondary"><?= $user['access_disabled'] ? 'Enable account' : 'Disable account' ?></button></form>
<?php endif; ?>
</details>
<?php if ($role === 0): ?>
<details class="basic-student-profile"><summary>View basic profile</summary>
<dl class="basic-profile-fields">
<?php foreach (['student_id' => 'Student ID', 'grade_level' => 'Grade level', 'section' => 'Section', 'adviser_name' => 'Adviser', 'created_at' => 'Registered'] as $field => $label): ?>
<div><dt><?= esc($label) ?></dt><dd><?= esc(($user[$field] ?? '') ?: 'Not set') ?></dd></div>
<?php endforeach; ?>
<div><dt>LRN</dt><dd><?= !empty($user['lrn_suffix']) ? '••••••••' . esc($user['lrn_suffix']) : 'Not set' ?></dd></div>
</dl>
<p>Basic identification only. Student entries, case records and guardian contacts are restricted to the guidance office.</p>
</details>
<?php endif; ?>
</div>
</article>
<?php endforeach; ?>
<div id="noAccountsFound" class="hidden text-center py-8 text-gray-500 text-sm">No accounts match your search query.</div>
</div><div class="directory-pagination" hidden><span id="accountPageLabel"></span><div><button type="button" id="accountPrevious" class="secondary">Previous</button><button type="button" id="accountNext" class="secondary">Next</button></div></div></section>
<aside id="accountInspector" class="role-panel account-inspector" aria-label="Account details" hidden>
<div class="inspector-heading"><h2>Account details</h2><button type="button" id="closeAccountInspector" class="secondary" aria-label="Close account details">×</button></div>
<div id="accountInspectorIdentity"></div><div id="accountInspectorContent"></div>
</aside></div></main>
<script>
const createToggle = document.getElementById('createAccountToggle');
const createPanel = document.getElementById('accountCreatePanel');
createToggle.hidden = false;
createPanel.classList.add('account-create-enhanced');
createToggle.addEventListener('click', () => {
    createPanel.open = !createPanel.open;
    if (createPanel.open) createPanel.querySelector('input[name=name]').focus();
});
createPanel.addEventListener('toggle', () => {
    createToggle.setAttribute('aria-expanded', String(createPanel.open));
    createToggle.textContent = createPanel.open ? 'Close account form' : '+ Create account';
});
</script>
<script src="<?= base_url('js/accounts-directory.js?v=' . filemtime(FCPATH . 'js/accounts-directory.js')) ?>" defer></script>
<?= view('partials/session_idle_modal') ?>
</body></html>
