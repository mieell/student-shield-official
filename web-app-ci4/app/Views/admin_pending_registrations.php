<?php $registrationStatus = $registrationStatus ?? 'pending'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudentShield — Pending Registrations</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?= view('partials/school_brand', ['studentPalette' => true]) ?>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        heading: ['Nunito', 'sans-serif']
                    },
                    colors: {
                        primary: StudentShieldBrand.primary,
                        gold: { DEFAULT: '#D9AA2E', light: '#EAC255', dark: '#B5881D' },
                    }
                }
            }
        }
    </script>
    <style>
        @media (max-width: 1440px) { html { font-size: 15px; } }
        @media (max-width: 1280px) { html { font-size: 14px; } }
        @media (max-width: 768px) { html { font-size: 16px; } } /* Reset for mobile */
        body { background-color: #f8faf6; font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4 { font-family: 'Nunito', sans-serif; }
        .glass-header {
            background: linear-gradient(160deg, #0D7B34 0%, #096328 60%, #075826 100%);
            border-bottom: 3px solid #EAB308;
            box-shadow: 0 4px 20px -2px rgba(7, 88, 38, 0.35);
        }
        .dashboard-card {
            background: #ffffff;
            border: 1px solid #e5ebe5;
            border-radius: 14px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        }
        .section-header {
            background: #ffffff;
            color: #0B5E2E;
            padding: 16px 24px;
            border-bottom: 1px solid #e5ebe5;
            border-left: 4px solid #0B5E2E;
        }
        .thead-green {
            background: linear-gradient(90deg, #0B5E2E, #096328);
            color: #fff;
        }
        .badge-pending {
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid #FDE68A;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes modalPop {
            from { opacity: 0; transform: translateY(10px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .animate-slide-up { animation: slideUp 0.4s ease both; }
        .animate-modal-pop { animation: modalPop 0.24s cubic-bezier(0.16, 1, 0.3, 1) both; }
        #manualConsentModal[hidden], #manualConsentBanner[hidden] { display: none !important; }
        .approve-btn {
            background: #096328;
            color: white;
            transition: all 0.2s ease;
        }
        .approve-btn:hover {
            background: #075826;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(11, 94, 46, 0.3);
        }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f5f7f5; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #c8dac8; border-radius: 10px; }
        .live-dot { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }
        .registration-table { min-width: 64rem; }
        .registration-contact > div { max-width: 16rem; overflow-wrap: anywhere; }
        .registration-contact svg { flex-shrink: 0; }
        .guardian-verification { min-width: 18rem; max-width: 28rem; margin: 0 auto; padding: 1rem; background: #f7fbf8; border: 1px solid #cfe2d4; border-radius: 12px; text-align: left; }
        .registration-manage { min-width: 200px; text-align: left; }
        .registration-manage > summary { display: flex; align-items: center; justify-content: space-between; gap: .75rem; list-style: none; border: 1px solid #d4e1d8; border-radius: 8px; background: #fff; }
        .registration-manage > summary::-webkit-details-marker { display: none; }
        .registration-manage > summary strong::after { content: " +"; }
        .registration-manage[open] > summary strong::after { content: " −"; }
        .registration-manage > summary:focus-visible { outline: 2px solid #096328; outline-offset: 2px; }
        .registration-panel { margin-top: .5rem; padding: .85rem; border: 1px solid #d4e1d8; border-radius: 8px; background: #f6faf7; }
        .registration-actions .consent-action { display: inline-flex; align-items: center; min-height: 40px; padding: .5rem .85rem; border-radius: 8px; font-size: .8rem; }
        .registration-actions .archive-action { margin-top: .65rem; color: #41584b; border: 1px solid #becfc3; background: #fff; }
        .registration-actions .archive-action:hover { background: #edf4ef; }
        .registration-actions summary { cursor: pointer; font-size: .8rem; color: #174d30; padding: .55rem; }
        .guardian-verification h3 { color: #174d30; font-size: .9rem; font-weight: 700; }
        .guardian-verification p { margin-top: .2rem; color: #526b5b; font-size: .75rem; line-height: 1.45; }
        .guardian-verification .guardian-field { margin-top: .75rem; }
        .guardian-verification label { display: block; color: #284b35; font-size: .75rem; font-weight: 600; }
        .guardian-verification input[type='text'], .guardian-verification input[type='tel'] { display: block; width: 100%; min-height: 42px; margin-top: .3rem; padding: .55rem .7rem; border: 1px solid #becfc3; border-radius: 8px; background: #fff; color: #173a2a; }
        .guardian-verification input:focus-visible { outline: 3px solid #b5dec0; outline-offset: 1px; }
        .guardian-verification .guardian-check { display: flex; align-items: flex-start; gap: .55rem; margin: .85rem 0; font-weight: 400; line-height: 1.45; }
        .guardian-verification .guardian-check input { width: 16px; height: 16px; flex: 0 0 16px; margin-top: 1px; accent-color: #096328; }
        .guardian-verification button { min-height: 42px; padding: .55rem .9rem; border-radius: 8px; font-size: .8rem; }
        @media (max-width: 767px) { .guardian-verification { min-width: 0; max-width: none; } .guardian-verification button { width: 100%; } }
        .registration-nav > *, .registration-nav svg { flex-shrink: 0; }
        .registration-nav > a, .registration-nav > span, .registration-nav button { white-space: nowrap; }
        @media (max-width: 1023px) {
            nav .registration-header { flex-direction: column; align-items: stretch; gap: 12px; }
            nav .registration-nav { width: 100%; gap: 8px; overflow-x: auto; }
            .registration-nav > a, .registration-nav > span, .registration-nav button { min-height: 44px; }
        }
        @media (max-width: 767px) {
            main.registration-main { padding-top: 24px; }
            .registration-main .registration-section-header { flex-direction: column; align-items: flex-start; gap: 4px; padding: 16px; }
            .registration-section-header svg { flex-shrink: 0; }
            .registration-table, .registration-table tbody { display: block; width: 100%; min-width: 0; }
            /* Keep column headers available to screen readers when rows stack. */
            .registration-table thead { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
            .registration-table tbody tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 16px; padding: 20px 16px; }
            .registration-table td { display: block; min-width: 0; padding: 0; text-align: left; overflow-wrap: anywhere; }
            .registration-table td::before { content: attr(data-label); display: block; margin-bottom: 4px; font-size: .75rem; font-weight: 600; color: #4b5563; }
            .registration-table .registration-number { display: none; }
            .registration-name, .registration-contact, .registration-actions { grid-column: 1 / -1; }
            .registration-name::before, .registration-status::before, .registration-actions::before { display: none !important; }
            .registration-name > div { align-items: flex-start; }
            .registration-name > div > span { font-size: 1rem; line-height: 1.5; }
            .registration-status { align-self: center; justify-self: end; }
            .registration-actions > div { margin-top: 4px; }
            .registration-actions button { flex: 1; min-width: 0; min-height: 44px; justify-content: center; font-size: .8125rem; }
            #toastContainer { left: 16px; right: 16px; top: 16px; }
        }
    </style>
    <link rel="stylesheet" href="<?= base_url('css/staff-layout.css?v=' . filemtime(FCPATH . 'css/staff-layout.css')) ?>">
<?= view('partials/ui_polish') ?>
</head>
<body class="school-staff-page staff-layout min-h-screen flex flex-col">

    <?= view('partials/staff_navigation', ['staffPage' => 'registrations', 'staffPageLabel' => 'Registrations']) ?>

    <!-- Main Content -->
    <main id="staffMain" class="staff-main registration-main container mx-auto px-4 sm:px-6 py-8 flex-1">

        <!-- Page Header -->
        <div class="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 animate-slide-up">
            <div>
                <h2 class="text-2xl font-bold text-[#0B5E2E] flex items-center gap-3">
                    <svg class="w-7 h-7 text-[#F2C300] flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                    Student Registrations
                </h2>
                <p class="text-gray-500 text-sm mt-1"><?= $canVerifyContact
                    ? 'Check guardian contacts against school records and send the consent form.'
                    : 'Review guardian responses and decide which registrations to approve.' ?></p>
            </div>
            <div class="flex items-center gap-3">
                <?php
                    $hdrBadge = $registrationStatus === 'approved' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : ($registrationStatus === 'rejected' ? 'bg-rose-50 text-rose-800 border-rose-200' : 'bg-amber-50 text-amber-700 border-amber-200');
                    $hdrDot = $registrationStatus === 'approved' ? 'bg-emerald-500' : ($registrationStatus === 'rejected' ? 'bg-rose-500' : 'bg-amber-400');
                ?>
                <div class="flex items-center gap-2 <?= $hdrBadge ?> px-4 py-2.5 rounded-xl border">
                    <span class="w-2 h-2 rounded-full <?= $hdrDot ?> live-dot"></span>
                    <span class="text-xs font-bold uppercase tracking-widest">
                        <?= count($pendingUsers) ?> <?= esc(ucfirst($registrationStatus)) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if (session()->getFlashdata('success')): ?>
            <div class="mb-6 bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3 animate-slide-up">
                <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-green-800 font-semibold text-sm"><?= session()->getFlashdata('success') ?></p>
            </div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-center gap-3 animate-slide-up">
                <svg class="w-5 h-5 text-red-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <p class="text-red-800 font-semibold text-sm"><?= session()->getFlashdata('error') ?></p>
            </div>
        <?php endif; ?>

        <?php
            $manualLink = $canVerifyContact ? (string) session()->getFlashdata('manual_consent_link') : '';
            $rawMeta = ($manualLink !== '' && is_array(session()->getFlashdata('manual_consent_meta'))) ? session()->getFlashdata('manual_consent_meta') : [];
            $metaStudent = trim((string) ($rawMeta['student_name'] ?? ''));
            $metaGuardian = trim((string) ($rawMeta['guardian_name'] ?? ''));
            $metaPhone = trim((string) ($rawMeta['phone'] ?? ''));
            $metaExpires = trim((string) ($rawMeta['expires_at'] ?? ''));
            $hasManualContext = ($metaStudent !== '' || $metaGuardian !== '' || $metaPhone !== '' || $metaExpires !== '');
            $greetingName = $metaGuardian !== '' ? $metaGuardian : 'Guardian';
            $studentSubject = $metaStudent !== '' ? $metaStudent . "'s" : "your child's";
            $inviteMessage = $manualLink !== ''
                ? "Good day {$greetingName}, this is St. Vincent Blessed School of Manila regarding {$studentSubject} StudentShield registration. Please complete the guardian consent form within 72 hours using this private link: {$manualLink}"
                : '';
        ?>
        <?php if ($manualLink !== ''): ?>
            <div id="manualConsentBanner" hidden class="mb-6 bg-emerald-50/90 border border-emerald-200 rounded-xl px-4 py-3 flex flex-wrap items-center justify-between gap-3 animate-slide-up">
                <div class="flex items-center gap-2.5 text-sm text-[#174d30]">
                    <svg class="w-5 h-5 text-[#096328] flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    <span class="font-semibold">
                        Private guardian consent link generated<?= $metaStudent !== '' ? ' for ' . esc($metaStudent) : '' ?>
                    </span>
                    <span class="text-xs font-medium text-[#3e614c]">(expires in 72 hours)</span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="reopenManualConsentBtn" onclick="reopenManualConsentModal()" class="approve-btn inline-flex items-center gap-1.5 px-3.5 py-2 min-h-[38px] rounded-lg text-xs font-bold cursor-pointer">
                        View & Copy Link
                    </button>
                    <button type="button" onclick="dismissManualConsentBanner()" aria-label="Dismiss reminder" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-[#41584b] hover:bg-emerald-100 transition-colors cursor-pointer">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Table Card -->
        <div class="dashboard-card overflow-hidden animate-slide-up" style="animation-delay: 0.1s">
            <div class="registration-section-header section-header flex justify-between items-center gap-4">
                <h3 class="text-base font-bold flex items-center gap-2">
                    <svg class="h-5 w-5 text-[#0B5E2E]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <?= esc(ucfirst($registrationStatus)) ?> Student Registrations
                </h3>
                <span class="text-xs text-gray-500 font-medium">Accounts are inactive until approved</span>
            </div>

            <div class="staff-filter-bar px-6 py-3 flex justify-between items-center bg-white border-b border-gray-100 flex-wrap gap-3">
                <form method="get" class="flex gap-2.5 items-center flex-wrap">
                    <label for="registrationStatus" class="text-xs font-semibold text-gray-600">Status:</label>
                    <select id="registrationStatus" name="status" class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg outline-none focus:border-[#0B5E2E] focus:ring-2 focus:ring-[#0B5E2E]/20 bg-white font-medium cursor-pointer transition-all">
                        <?php foreach (['pending' => 'Pending Review', 'approved' => 'Approved Accounts', 'rejected' => 'Rejected Requests', 'archived' => 'Archived Requests'] as $statusVal => $statusLabel): ?>
                            <option value="<?= $statusVal ?>" <?= $registrationStatus === $statusVal ? 'selected' : '' ?>><?= esc($statusLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="text-xs font-semibold text-white bg-[#096328] px-3.5 py-1.5 min-h-[36px] rounded-lg">View</button></noscript>
                </form>
                <div class="flex items-center gap-2 flex-1 min-w-[200px] max-w-xs relative ml-auto">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input type="text" id="searchRegistrations" aria-label="Search registrations" placeholder="Filter by name, ID, section..." class="w-full pl-9 pr-4 py-1.5 text-sm border border-gray-200 rounded-lg outline-none focus:border-[#0B5E2E] focus:ring-2 focus:ring-[#0B5E2E]/20 transition-all">
                </div>
            </div>
            <div class="overflow-x-auto custom-scrollbar">
                <?php if (empty($pendingUsers)): ?>
                    <div class="flex flex-col items-center justify-center py-20 px-6 text-center">
                        <div class="w-20 h-20 rounded-full bg-green-50 flex items-center justify-center mb-4">
                            <svg class="w-10 h-10 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <h4 class="text-lg font-bold text-gray-700 mb-1">No matching registrations</h4>
                        <p class="text-gray-500 text-sm max-w-sm">No <?= esc($registrationStatus) ?> student registrations match this view.</p>
                    </div>
                <?php else: ?>
                    <table class="registration-table w-full text-left border-collapse" aria-label="Student registrations">
                        <thead>
                            <tr class="thead-green text-xs uppercase tracking-wider">
                                <th class="px-6 py-4">#</th>
                                <th class="px-6 py-4">Student Name</th>
                                <th class="px-6 py-4">Student ID</th>
                                <th class="px-6 py-4">Grade & Section</th>
                                <th class="px-6 py-4">Parent Contact</th>
                                <th class="px-6 py-4">Registered On</th>
                                <th class="px-6 py-4 text-center">Status</th>
                                <th class="px-6 py-4 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($pendingUsers as $i => $student): 
                                $uid = (int)($student['user_id'] ?? $student['id']);
                            ?>
                            <tr id="row-user-<?= $uid ?>" class="hover:bg-gray-50 transition-all duration-300">
                                <td class="registration-number px-6 py-4 text-sm text-gray-400 font-medium"><?= $i + 1 ?></td>
                                <td data-label="Student Name" class="registration-name px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full bg-[#0B5E2E]/10 flex items-center justify-center flex-shrink-0">
                                            <span class="text-sm font-bold text-[#0B5E2E]">
                                                <?= strtoupper(substr($student['name'], 0, 1)) ?>
                                            </span>
                                        </div>
                                        <span class="text-sm font-semibold text-gray-800"><?= esc($student['name']) ?></span>
                                    </div>
                                </td>
                                <td data-label="Student ID" class="px-6 py-4">
                                    <span class="text-sm font-mono font-medium text-gray-700"><?= esc($student['student_id'] ?? '—') ?></span><span class="block text-xs text-gray-600 mt-1">LRN: <?= esc($student['lrn'] ?? 'Not provided') ?></span>
                                </td>
                                <td data-label="Grade &amp; Section" class="px-6 py-4">
                                    <div class="text-sm text-gray-800">
                                        <span class="font-semibold"><?= esc($student['grade_level'] ?? '—') ?></span>
                                        <?php if (!empty($student['section'])): ?>
                                            <span class="text-gray-400"> · </span>
                                            <span class="text-gray-600"><?= esc($student['section']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Parent Contact" class="registration-contact px-6 py-4">
                                    <div class="text-sm text-gray-700 space-y-0.5">
                                        <?php if (!empty($student['parent_phone'])): ?>
                                            <div class="flex items-center gap-1">
                                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                                <span><?= esc($student['parent_phone']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($student['parent_email'])): ?>
                                            <div class="flex items-center gap-1">
                                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                                <span class="text-xs"><?= esc($student['parent_email']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (empty($student['parent_phone']) && empty($student['parent_email'])): ?>
                                            <span class="text-gray-400">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Registered On" class="px-6 py-4">
                                    <span class="text-sm text-gray-600">
                                        <?= date('M d, Y', strtotime($student['created_at'])) ?>
                                    </span>
                                    <div class="text-xs text-gray-400"><?= date('g:i A', strtotime($student['created_at'])) ?></div>
                                </td>
                                <td data-label="Status" class="registration-status px-6 py-4 text-center">
                                    <?php
                                        $rowBadge = $registrationStatus === 'approved' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : ($registrationStatus === 'rejected' ? 'bg-rose-50 text-rose-800 border-rose-200' : 'badge-pending');
                                        $rowDot = $registrationStatus === 'approved' ? 'bg-emerald-500' : ($registrationStatus === 'rejected' ? 'bg-rose-500' : 'bg-amber-400');
                                    ?>
                                    <span class="<?= $rowBadge ?> inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wide border">
                                        <span class="w-1.5 h-1.5 rounded-full <?= $rowDot ?>"></span>
                                        <?= esc(ucfirst($registrationStatus)) ?>
                                    </span>
                                </td>
                                <td data-label="Action" class="registration-actions px-6 py-4 text-center">
                                    <?php $consentStatus = empty($student['verified_phone']) ? 'Contact needs verification' : match ($student['response']) {
                                                'accepted' => 'Consent accepted',
                                                'declined' => 'Consent declined',
                                                default => strtotime($student['expires_at']) <= time() ? 'Consent request expired' : match ($student['sms_state']) {
                                                    'Queued' => 'SMS queued · awaiting guardian',
                                                    'Unknown' => 'SMS status uncertain',
                                                    'Manual' => 'Manual link generated; awaiting guardian',
                                                    default => 'SMS was not queued',
                                                },
                                            }; ?>
                                    <?php if ($registrationStatus === 'pending'): ?>
                                    <details class="registration-manage">
                                        <summary><span><?= esc($consentStatus) ?></span><strong>Manage</strong></summary>
                                        <div class="registration-panel">
                                    <?php endif; ?>
                                    <?php if (!empty($student['verified_phone'])): ?>
                                        <div class="text-xs mb-3 space-y-1 leading-relaxed">
                                            <p>Verified number: <?= esc($student['verified_phone']) ?></p>
                                            <p>Guardian on school record: <?= esc($student['verified_name']) ?></p>
                                            <p class="font-semibold"><?= esc($consentStatus) ?></p>
                                            <?php if ($student['guardian_name']): ?>
                                                <p>Signed by <?= esc($student['guardian_name']) ?> · <?= esc($student['responded_at']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($registrationStatus === 'pending'): ?>
                                    <?php if ($canVerifyContact && !empty($student['verified_phone']) && empty($student['response'])): ?>
                                        <form method="post" action="<?= esc(site_url('admin/registrations/' . $uid . '/send-consent'), 'attr') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="use_verified" value="1">
                                            <button type="submit" name="delivery" value="manual" class="approve-btn consent-action font-semibold" onclick="return confirm('Generate a new consent link? Any previous link will stop working.')">Generate replacement link</button>
                                            <p class="text-xs mt-1">Share privately if SMS delivery is uncertain.</p>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canVerifyContact && empty($student['response'])):
                                        $canSendSms = \App\Libraries\GuardianConsent::canSend($student['requested_at'] ? $student : null, time()); ?>
                                        <details <?= empty($student['verified_phone']) ? 'open' : '' ?>>
                                        <summary>Verify or update guardian contact</summary>
                                        <form method="post" action="<?= esc(site_url('admin/registrations/' . $uid . '/send-consent'), 'attr') ?>" class="guardian-verification">
                                            <?= csrf_field() ?>
                                            <h3>Verify guardian contact</h3>
                                            <p>Check the details against school records before sending the consent code or link.</p>
                                            <div class="guardian-field"><label for="verified-name-<?= $uid ?>">Guardian name from school records</label>
                                            <input class="w-full border rounded-lg px-2 py-2 text-sm" type="text" id="verified-name-<?= $uid ?>" name="verified_name" maxlength="120" required>
                                            </div>
                                            <div class="guardian-field"><label for="verified-phone-<?= $uid ?>">Guardian mobile from school records</label>
                                            <input class="w-full border rounded-lg px-2 py-2 text-sm" type="tel" id="verified-phone-<?= $uid ?>" name="verified_phone" placeholder="09123456789" maxlength="32" required>
                                            </div>
                                            <label class="guardian-check">
                                                <input type="checkbox" name="checked_records" value="1" required>
                                                <span>I confirmed the guardian and this number against current school records.</span>
                                            </label>
                                            <?php if ($canSendSms): ?>
                                            <button type="submit" name="delivery" value="sms" class="approve-btn font-semibold">Send consent code by SMS</button>
                                            <?php endif; ?>
                                            <button type="submit" name="delivery" value="manual" class="approve-btn font-semibold">Generate link for private sharing</button>
                                        </form>
                                        </details>
                                    <?php endif; ?>
                                    <?php if (!$canVerifyContact): ?>
                                    <?php if (!$student['requested_at']): ?><p class="text-xs mb-2">Waiting for Super Admin to verify the guardian contact.</p><?php endif; ?>
                                    <div class="flex items-center justify-center gap-2">
                                        <?php if ($student['response'] === 'accepted'): ?>
                                        <button type="button" onclick="handleAction('approve', <?= $uid ?>, '<?= esc(addslashes($student['name'])) ?>', this)" class="approve-btn inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wide shadow-sm cursor-pointer">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            <span>Approve</span>
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" onclick="handleAction('reject', <?= $uid ?>, '<?= esc(addslashes($student['name'])) ?>', this)" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-medium text-red-600 hover:text-red-800 hover:bg-red-50 border border-red-200 transition-colors cursor-pointer">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                            <span>Reject</span>
                                        </button>
                                    </div>
                                    <?php elseif ($student['response'] === 'accepted'): ?>
                                        <p class="text-xs">Waiting for counselor approval.</p>
                                    <?php endif; ?>
                                    <?php if ($canVerifyContact): ?>
                                        <form method="post" action="<?= esc(site_url('admin/registrations/' . $uid . '/archive'), 'attr') ?>" onsubmit="return confirm('Archive this registration? Any outstanding consent link will close.')">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="consent-action archive-action">Archive registration</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php else: ?><span class="text-sm text-gray-500">Decision recorded</span><?php endif; ?>
                                    <?php if ($registrationStatus === 'pending'): ?>
                                        </div>
                                    </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr id="noRegistrationsFound" style="display:none;"><td colspan="8" class="px-6 py-12 text-center text-gray-500 font-medium">No registrations match your search filter.</td></tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Toast Notification Container -->
        <div id="toastContainer" class="fixed top-20 right-6 z-[1100] flex flex-col gap-2 pointer-events-none"></div>

        <!-- Info Note -->
        <div class="mt-6 flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-xl p-4 animate-slide-up" style="animation-delay: 0.2s">
            <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="text-blue-800 text-sm font-semibold">Approval Policy</p>
                <p class="text-blue-700 text-xs mt-0.5 leading-relaxed">
                    Super Admin verifies guardian contact details against school records. After the guardian gives consent, only a Guidance Counselor can approve the registration. Students who lack a safe guardian contact can ask the Guidance Office for help.
                </p>
            </div>
        </div>

        <?php if ($manualLink !== ''): ?>
            <!-- Generated Guardian Consent Link Modal -->
            <div id="manualConsentModal" role="dialog" aria-modal="true" aria-labelledby="manualConsentModalTitle" aria-hidden="false" tabindex="-1" onclick="if (event.target === this) closeManualConsentModal();" class="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-[#072613]/45 backdrop-blur-sm">
                <div class="relative w-full max-w-2xl bg-white rounded-2xl border border-[#dce6dd] shadow-[0_24px_64px_-16px_rgba(7,56,28,0.34)] overflow-hidden max-h-[90vh] flex flex-col animate-modal-pop">
                    <div class="h-1.5 w-full bg-gradient-to-r from-[#096328] via-[#128f42] to-[#D9AA2E] flex-shrink-0"></div>

                    <div class="px-6 pt-5 pb-4 border-b border-[#edf2ed] flex-shrink-0">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-[#0B5E2E] text-[11px] font-bold uppercase tracking-wider">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#128f42]"></span>
                                    Private Share Link
                                </span>
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-semibold">
                                    <svg class="w-3.5 h-3.5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Expires in 72 hours
                                </span>
                            </div>
                            <button type="button" onclick="closeManualConsentModal()" aria-label="Close modal" class="w-10 h-10 min-w-[40px] min-h-[40px] rounded-full bg-[#f4f7f4] hover:bg-[#e7efe7] text-[#3e5948] flex items-center justify-center transition-colors cursor-pointer">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <h3 id="manualConsentModalTitle" class="text-xl font-extrabold text-[#15492c] mt-3 leading-snug">Guardian Consent Link Ready</h3>
                        <p class="text-xs sm:text-sm text-[#526b5b] mt-1 leading-relaxed">Copy this private URL or ready-to-send message to share directly with the verified guardian.</p>
                    </div>

                    <div class="px-6 py-5 space-y-4 overflow-y-auto custom-scrollbar">
                        <?php if ($hasManualContext): ?>
                            <div class="rounded-xl bg-[#f8faf6] border border-[#e3ebe3] p-3.5 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                <?php if ($metaStudent !== ''): ?>
                                    <div>
                                        <span class="block uppercase tracking-wider text-[10px] font-bold text-[#61756b]">Student</span>
                                        <span class="block font-semibold text-[#173a2a] text-sm mt-0.5 break-words"><?= esc($metaStudent) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($metaGuardian !== '' || $metaPhone !== ''): ?>
                                    <div>
                                        <span class="block uppercase tracking-wider text-[10px] font-bold text-[#61756b]">Verified Guardian</span>
                                        <span class="block font-medium text-[#173a2a] text-sm mt-0.5 break-words">
                                            <?= esc($metaGuardian) ?>
                                            <?php if ($metaGuardian !== '' && $metaPhone !== ''): ?> · <?php endif; ?>
                                            <?php if ($metaPhone !== ''): ?><span class="tabular-nums text-[#3f594a]"><?= esc($metaPhone) ?></span><?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($metaExpires !== ''): ?>
                                    <div class="sm:col-span-2 pt-2 border-t border-[#e7efe7] flex items-center gap-1.5 text-[#476453]">
                                        <svg class="w-3.5 h-3.5 text-[#096328] flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        <span>Valid until <strong class="text-[#173a2a] tabular-nums"><?= esc($metaExpires) ?></strong></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="rounded-xl bg-[#f4f8f5] border border-[#cfe2d4] p-3.5 space-y-3 focus-within:border-[#096328] focus-within:ring-2 focus-within:ring-[#096328]/15 transition-all">
                            <div class="flex items-center justify-between gap-2">
                                <label for="manual-consent-link" class="text-xs font-bold uppercase tracking-wider text-[#284b35]">New guardian consent link</label>
                                <span class="text-[11px] text-[#61756b]">Tap URL to select all</span>
                            </div>
                            <input type="text" id="manual-consent-link" readonly value="<?= esc($manualLink, 'attr') ?>" onclick="this.select()" class="w-full min-w-0 bg-white border border-[#dce7df] rounded-lg px-3.5 py-2.5 text-xs sm:text-[13px] font-mono text-[#133824] focus:outline-none">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2.5 pt-0.5">
                                <button type="button" id="copyConsentLinkBtn" onclick="copyManualConsentField('link')" class="approve-btn inline-flex items-center justify-center gap-2 px-4 py-2.5 min-h-[44px] rounded-lg text-sm font-bold whitespace-nowrap cursor-pointer shadow-sm">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                    <span data-label>Copy Link</span>
                                </button>
                                <button type="button" id="copyConsentMessageBtn" data-message="<?= esc($inviteMessage, 'attr') ?>" onclick="copyManualConsentField('message')" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 min-h-[44px] rounded-lg border border-[#cfe2d4] bg-white hover:bg-[#eaf4ed] text-[#174d30] text-sm font-bold whitespace-nowrap transition-colors cursor-pointer">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                                    <span data-label>Copy Message</span>
                                </button>
                            </div>
                        </div>

                        <details class="rounded-xl border border-[#e5ebe5] bg-white px-3.5 py-2.5 text-xs text-[#476453]">
                            <summary class="cursor-pointer font-semibold text-[#284b35] select-none py-1">Preview ready-to-send message (Messenger / Viber / SMS)</summary>
                            <p class="text-xs text-[#526b5b] bg-[#f8faf6] border border-[#ecf1ec] rounded-lg p-3 mt-2 leading-relaxed break-words select-all"><?= esc($inviteMessage) ?></p>
                        </details>

                        <div class="flex items-start gap-2.5 text-xs text-[#745112] bg-amber-50/75 border border-amber-200/80 rounded-xl px-3.5 py-2.5 leading-relaxed">
                            <svg class="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            <span>Copy this link now and share it privately with the verified guardian only. Generating a replacement link immediately invalidates older links.</span>
                        </div>
                    </div>

                    <div class="px-6 py-4 bg-[#f8faf6] border-t border-[#e5ebe5] flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-2.5 flex-shrink-0">
                        <span class="text-[11px] text-[#61756b] text-center sm:text-left">Press <kbd class="px-1.5 py-0.5 rounded bg-white border border-gray-200 font-mono text-[10px]">Esc</kbd> to minimize and keep the recall banner</span>
                        <button type="button" id="closeManualConsentBtn" onclick="closeManualConsentModal()" class="inline-flex items-center justify-center px-5 py-2.5 min-h-[44px] rounded-xl bg-white hover:bg-[#edf4ef] text-[#174d30] border border-[#cfe0d5] text-sm font-bold transition-colors cursor-pointer">
                            Done & Close
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer class="mt-auto py-4 bg-white border-t border-gray-100">
        <div class="container mx-auto px-6 text-center text-xs text-gray-400">
            © <?= date('Y') ?> StudentShield — St. Vincent Blessed School of Manila
        </div>
    </footer>

    <script>
        const registrationStatus = document.getElementById('registrationStatus');
        registrationStatus?.addEventListener('change', () => registrationStatus.form.requestSubmit());
        function showToast(message, isSuccess = true) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `px-5 py-3 rounded-xl shadow-lg border text-sm font-semibold flex items-center gap-2 transform transition-all duration-300 pointer-events-auto ${
                isSuccess 
                    ? 'bg-green-50 text-green-800 border-green-200' 
                    : 'bg-red-50 text-red-800 border-red-200'
            }`;
            toast.innerHTML = `
                <svg class="w-5 h-5 flex-shrink-0 ${isSuccess ? 'text-green-600' : 'text-red-600'}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${isSuccess ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'}" />
                </svg>
                <span>${message}</span>
            `;
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-10px)';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        async function handleAction(action, userId, studentName, btn) {
            const isApprove = (action === 'approve');
            const endpoint = isApprove ? `/admin/approve-student/${userId}` : `/admin/reject-student/${userId}`;
            
            // Visual loading state on button
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span class="inline-block animate-spin mr-1">⏳</span> ${isApprove ? 'Approving...' : 'Rejecting...'}`;

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    }
                });

                const data = await response.json();

                if (response.ok && data.status === 'success') {
                    showToast(isApprove ? `Account for "${studentName}" approved!` : `Registration for "${studentName}" rejected.`, true);

                    // Animate row removal
                    const row = document.getElementById(`row-user-${userId}`);
                    if (row) {
                        row.style.transition = 'all 0.4s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            row.remove();
                            // Check if no more rows left
                            const remainingRows = document.querySelectorAll('tbody tr');
                            if (remainingRows.length === 0) {
                                location.reload();
                            }
                        }, 400);
                    }
                } else {
                    showToast(data.message || 'Action could not be completed. Please try again.', false);
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            } catch (err) {
                console.error('Fetch error:', err);
                // Fallback: direct browser navigation if fetch is blocked
                window.location.href = endpoint;
            }
        }

        const searchRegInput = document.getElementById('searchRegistrations');
        if (searchRegInput) {
            searchRegInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                const rows = document.querySelectorAll('.registration-table tbody tr:not(#noRegistrationsFound)');
                let visible = 0;
                rows.forEach(row => {
                    const match = !query || row.innerText.toLowerCase().includes(query);
                    row.style.display = match ? '' : 'none';
                    if (match) visible++;
                });
                const emptyRow = document.getElementById('noRegistrationsFound');
                if (emptyRow) {
                    emptyRow.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
                }
            });
        }

        function setCopyButtonState(btn, copiedLabel, defaultLabel) {
            if (!btn) return;
            const labelEl = btn.querySelector('[data-label]');
            if (!labelEl) return;
            clearTimeout(btn._copyResetTimer);
            labelEl.textContent = copiedLabel;
            btn._copyResetTimer = setTimeout(() => {
                labelEl.textContent = defaultLabel;
            }, 2200);
        }

        async function copyTextSafely(text) {
            if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
                try {
                    await navigator.clipboard.writeText(text);
                    return true;
                } catch (err) {
                    // Fall back to execCommand below when Clipboard API permission is restricted.
                }
            }
            const helper = document.createElement('textarea');
            helper.value = text;
            helper.setAttribute('readonly', '');
            helper.style.position = 'fixed';
            helper.style.top = '-9999px';
            document.body.appendChild(helper);
            helper.select();
            helper.setSelectionRange(0, text.length);
            let ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (err) {
                ok = false;
            }
            helper.remove();
            return ok;
        }

        async function copyManualConsentField(mode) {
            const linkInput = document.getElementById('manual-consent-link');
            const isLink = (mode === 'link');
            const btn = document.getElementById(isLink ? 'copyConsentLinkBtn' : 'copyConsentMessageBtn');
            const text = isLink ? (linkInput?.value || '') : (btn?.dataset.message || '');
            if (!text) return;

            const copied = await copyTextSafely(text);
            if (copied) {
                setCopyButtonState(btn, 'Copied!', isLink ? 'Copy Link' : 'Copy Message');
                showToast(isLink ? 'Consent link copied to clipboard.' : 'Ready-to-send message copied to clipboard.', true);
            } else {
                if (linkInput && isLink) {
                    linkInput.focus();
                    linkInput.select();
                }
                showToast('Clipboard blocked by browser. Select and press Ctrl+C to copy.', false);
            }
        }

        function closeManualConsentModal() {
            const modal = document.getElementById('manualConsentModal');
            const banner = document.getElementById('manualConsentBanner');
            if (!modal) return;
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            if (banner) {
                banner.hidden = false;
                document.getElementById('reopenManualConsentBtn')?.focus();
            }
        }

        function reopenManualConsentModal() {
            const modal = document.getElementById('manualConsentModal');
            const banner = document.getElementById('manualConsentBanner');
            if (!modal) return;
            if (banner) banner.hidden = true;
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            document.getElementById('copyConsentLinkBtn')?.focus();
        }

        function dismissManualConsentBanner() {
            const banner = document.getElementById('manualConsentBanner');
            if (banner) banner.hidden = true;
        }

        (function initManualConsentModal() {
            const modal = document.getElementById('manualConsentModal');
            if (!modal) return;
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !modal.hidden) {
                    closeManualConsentModal();
                }
            });
            setTimeout(() => {
                document.getElementById('copyConsentLinkBtn')?.focus();
            }, 60);
        })();
    </script>
    <?= view('partials/session_idle_modal') ?>
    <!-- ── Floating Scroll-to-Top Button ── -->
    <button id="scrollTopBtn" title="Back to top"
        aria-label="Scroll back to top"
        style="
            position: fixed;
            bottom: 28px;
            right: 24px;
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, #D9AA2E 0%, #B5881D 100%);
            color: #083318;
            box-shadow: 0 6px 20px -4px rgba(0,0,0,0.35), 0 0 0 3px rgba(217,170,46,0.20);
            opacity: 0;
            transform: translateY(16px) scale(0.85);
            pointer-events: none;
            transition: opacity 0.28s ease, transform 0.28s cubic-bezier(0.34,1.56,0.64,1), box-shadow 0.2s ease;
        ">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round">
            <polyline points="18 15 12 9 6 15"/>
        </svg>
    </button>
    <script>
    (function () {
        var btn = document.getElementById('scrollTopBtn');
        var SHOW_AT = 400;
        function update() {
            var scrolled = window.scrollY || document.documentElement.scrollTop;
            if (scrolled > SHOW_AT) {
                btn.style.opacity = '1';
                btn.style.transform = 'translateY(0) scale(1)';
                btn.style.pointerEvents = 'auto';
            } else {
                btn.style.opacity = '0';
                btn.style.transform = 'translateY(16px) scale(0.85)';
                btn.style.pointerEvents = 'none';
            }
        }
        window.addEventListener('scroll', update, { passive: true });
        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        btn.addEventListener('mouseenter', function () {
            this.style.boxShadow = '0 10px 28px -4px rgba(0,0,0,0.45), 0 0 0 4px rgba(217,170,46,0.30)';
            this.style.transform = 'translateY(-2px) scale(1.07)';
        });
        btn.addEventListener('mouseleave', function () {
            this.style.boxShadow = '0 6px 20px -4px rgba(0,0,0,0.35), 0 0 0 3px rgba(217,170,46,0.20)';
            this.style.transform = 'translateY(0) scale(1)';
        });
    })();
    </script>
</body>
</html>
