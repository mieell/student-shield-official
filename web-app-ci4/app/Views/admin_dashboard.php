<?php
$dashboardPage = $dashboard_page ?? 'vents';
$pageLabels = ['vents' => 'Vents', 'incidents' => 'Incidents', 'sessions' => 'Sessions', 'alerts' => 'Alerts', 'analytics' => 'Analytics'];
$pageDescriptions = [
    'vents' => 'Review student wellness entries and follow up on students who need support.',
    'incidents' => 'Review, verify, and follow up on bullying incident reports.',
    'sessions' => 'Review pending counseling requests and open the student’s case to arrange a session.',
    'alerts' => 'Review notification attempts and their recorded messages.',
    'analytics' => 'Explore risk distribution and student entry volume over the last seven days.',
];
?>
<!DOCTYPE html>
<html lang="en" style="scroll-behavior:smooth">

<head>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudentShield - <?= session()->get('role') == 2 ? 'Super Admin' : 'Counselor' ?> — <?= esc($dashboardPage === 'vents' ? 'Student wellness' : $pageLabels[$dashboardPage]) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?= view('partials/school_brand', ['studentPalette' => true]) ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        heading: ['Nunito', 'sans-serif']
                    },
                    colors: {
                        white: '#FBFDFB', // Anti-glare soft white
                        black: '#131A15', // Softened black
                        gray: {
                            50: '#F4F7F5',
                            100: '#E6EBE8',
                            200: '#CFD9D4',
                            300: '#AAB8B1',
                            400: '#82958B',
                            500: '#61756B',
                            600: '#4D5D55',
                            700: '#3D4943',
                            800: '#2E3833',
                            900: '#212825', // Replaces harsh text-gray-900 with deep sage
                        },
                        red: {
                            50: '#FDF7F7',
                            100: '#FAEDED',
                            200: '#F4D6D6',
                            500: '#D95A5A', // Less neon, softer warning red
                            600: '#C24545',
                            700: '#A33535',
                        },
                        primary: StudentShieldBrand.primary,
                        gold: { DEFAULT: '#D9AA2E', light: '#EAC255', bright: '#FCEEA1', dark: '#B5881D' },
                        calm: { 50: '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0', 600: '#16a34a' }
                    }
                }
            }
        }
    </script>
    <style>
        /* Responsive scaling for smaller monitors/laptops */
        @media (max-width: 1440px) {
            html {
                font-size: 15px;
            }
        }

        @media (max-width: 1280px) {
            html {
                font-size: 14px;
            }
        }

        @media (max-width: 1024px) {
            html {
                font-size: 13px;
            }
        }

        @media (max-width: 768px) {
            html {
                font-size: 16px;
            }
        }

        /* Reset for mobile */

        html, body {
            overflow-x: hidden;
            max-width: 100vw;
            width: 100%;
        }

        body {
            /* Keep horizontal clipping from creating a scroll container around the sticky header. */
            overflow-x: clip;
            background-color: #F4F7F5;
            background-image: radial-gradient(circle at 25px 25px, rgba(22, 101, 52, 0.02) 2px, transparent 0);
            background-size: 50px 50px;
        }

        .glass-header {
            background: linear-gradient(160deg, #2F754A 0%, #235D3A 60%, #19452B 100%);
            border-bottom: 3px solid #EAB308;
            box-shadow: 0 4px 20px -2px rgba(7, 88, 38, 0.35);
        }

        .dashboard-card {
            background: #ffffff;
            border: 1px solid #e5ebe5;
            border-radius: 14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        .dashboard-card:hover {
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.12);
            border-color: #cddacd;
        }

        .section-header {
            background: #ffffff;
            color: #235D3A;
            padding: 16px 24px;
            border-bottom: 1px solid #e5ebe5;
            border-left: 4px solid #235D3A;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid #e5ebe5;
            border-radius: 12px;
            padding: 20px 24px;
            transition: all 0.25s ease;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        .stat-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, #EAB308, transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .stat-card:hover {
            border-color: #d4ddd4;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }

        .stat-card.gold-accent {
            border-left: 4px solid #EAB308;
        }

        .stat-card.green-accent {
            border-left: 4px solid #235D3A;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-family: 'Nunito', sans-serif;
        }

        /* Custom Scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f5f7f5;
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #c8dac8;
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #235D3A;
        }

        /* Entry animations */
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .animate-slide-up {
            animation: slideUp 0.5s ease-out forwards;
        }

        .animate-fade-in {
            animation: fadeIn 0.4s ease-out forwards;
        }

        .live-dot {
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse-live 2s infinite;
        }

        @keyframes pulse-live {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            }

            70% {
                transform: scale(1);
                box-shadow: 0 0 0 8px rgba(16, 185, 129, 0);
            }

            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        .thead-green {
            background: #235D3A;
            color: white;
        }

        .thead-green th {
            color: rgba(255, 255, 255, 0.92);
            font-weight: 600;
            letter-spacing: 0.05em;
            font-size: 0.7rem;
        }

        /* Row highlight animation for new entries */
        @keyframes newRow {
            0% {
                background-color: rgba(242, 195, 0, 0.12);
            }

            100% {
                background-color: transparent;
            }
        }

        .footer-bar {
            background: linear-gradient(160deg, #2F754A 0%, #235D3A 60%, #19452B 100%);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.75rem;
            padding: 12px 0;
            text-align: center;
            letter-spacing: 0.03em;
        }


        /* ═══════════════════════════════════════
           UNIFIED STICKY DASHBOARD HEADER & NAV
        ═══════════════════════════════════════ */
        #dashboardHeader {
            position: sticky;
            top: 0;
            z-index: 40;
            width: 100%;
            max-width: 100vw;
            box-shadow: 0 4px 18px -4px rgba(7, 88, 38, 0.20);
        }

        #dashboardHeader.is-scrolled #navContainer {
            padding-top: 0.35rem;
            padding-bottom: 0.35rem;
        }

        #dashboardHeader.is-scrolled .dash-logo-img {
            height: 2rem;
            width: 2rem;
        }

        .section-nav-strip {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 2px solid rgba(11, 94, 46, 0.12);
            border-top: 1px solid rgba(255, 255, 255, 0.20);
            box-shadow: 0 2px 8px -2px rgba(0, 0, 0, 0.04);
            width: 100%;
            max-width: 100vw;
            overflow: hidden;
        }

        .section-tabs-scroller {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 2px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            width: 100%;
            min-width: 0;
            flex: 1 1 0%;
        }
        .section-tabs-scroller::-webkit-scrollbar {
            display: none;
        }

        .dash-tab {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 11px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #4b6354;
            text-decoration: none;
            white-space: nowrap;
            flex-shrink: 0;
            background: transparent;
            border: 1px solid transparent;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @media (min-width: 640px) {
            .dash-tab {
                gap: 6px;
                padding: 6px 13px;
                font-size: 0.78rem;
            }
        }

        .dash-tab:hover {
            color: #235D3A;
            background: rgba(11, 94, 46, 0.08);
            border-color: rgba(11, 94, 46, 0.15);
        }

        .dash-tab.is-active {
            color: #ffffff !important;
            background: linear-gradient(135deg, #2F754A 0%, #19452B 100%) !important;
            border-color: #19452B !important;
            font-weight: 700;
            box-shadow: 0 2px 8px -1px rgba(7, 88, 38, 0.35);
        }

        .dash-tab.is-active .tab-icon {
            color: #FACC15 !important;
        }

        .tab-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 9999px;
            background: #EAB308;
            color: #083318;
            font-size: 10px;
            font-weight: 800;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }

        .dash-tab.is-active .tab-badge {
            background: #FACC15;
            color: #19452B;
        }

        .tab-badge.hidden {
            display: none;
        }

        html {
            scroll-behavior: smooth;
        }

        [id^="section-"] {
            scroll-margin-top: 130px;
        }

        /* Collapsed table rows — show first 5, hide the rest until expanded */
        .tbl-row-hidden {
            display: none;
        }

        .show-all-row {
            cursor: pointer;
        }

        /* Mobile Card Tables */
        @media (max-width: 768px) {

            .mobile-card-table,
            .mobile-card-table thead,
            .mobile-card-table tbody,
            .mobile-card-table th,
            .mobile-card-table td,
            .mobile-card-table tr {
                display: block;
                width: 100%;
            }

            .mobile-card-table thead {
                display: none;
            }

            .mobile-card-table tr {
                margin-bottom: 1rem;
                background: #fff;
                border-radius: 12px;
                border: 1px solid #e5ebe5;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
                overflow: hidden;
            }

            .mobile-card-table td {
                border-bottom: 1px dashed #f3f4f6;
                padding: 12px 16px !important;
                display: flex;
                flex-direction: column;
                gap: 4px;
                text-align: left !important;
            }

            .mobile-card-table td:last-child {
                border-bottom: none;
                background: #f8faf6;
            }

            .mobile-card-table td::before {
                content: attr(data-label);
                font-size: 0.65rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                font-weight: 800;
                color: #9ca3af;
            }
        }

        .staff-header-action { min-width: 44px; min-height: 44px; justify-content: center; }
        .school-staff-page #meetingDropdown {
            position: fixed; left: 12px; right: 12px; top: 72px;
            width: auto; max-width: calc(100vw - 24px); background: #FBFDFB;
            color: #212825; border: 1px solid #CFD9D4; border-radius: 16px;
            box-shadow: 0 16px 48px rgba(10, 28, 17, .18); overflow: hidden; z-index: 50;
        }
        .meeting-dropdown-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #E6EBE8; }
        .meeting-dropdown-heading h2 { font-size: 16px; line-height: 24px; font-weight: 700; }
        .meeting-close { width: 44px; height: 44px; flex: 0 0 44px; border-radius: 8px; font-size: 24px; color: #61756B; }
        .school-staff-page #meetingDropdown a:hover, .school-staff-page #meetingDropdown button:hover { background: #F4F7F5; }
        .meeting-empty { padding: 28px 20px; text-align: center; color: #61756B; font-size: 14px; line-height: 22px; }
        .meeting-empty-icon { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; margin: 0 auto 10px; border-radius: 50%; background: #E6EBE8; color: #235D3A; font-size: 18px; }
        .meeting-dropdown-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 12px 16px; border-top: 1px solid #E6EBE8; font-size: 12px; line-height: 18px; color: #61756B; }
        .meeting-dropdown-footer a { color: #235D3A; font-weight: 600; padding: 6px 0; }
        @media (min-width: 640px) {
            .school-staff-page #meetingDropdown { position: absolute; left: auto; right: 0; top: calc(100% + 12px); width: 384px; }
        }
    </style>
    <link rel="stylesheet" href="<?= base_url('css/staff-layout.css?v=' . filemtime(FCPATH . 'css/staff-layout.css')) ?>">
</head>

<body class="school-staff-page staff-layout font-sans antialiased text-gray-800 min-h-screen flex flex-col relative">

    <?= view('partials/staff_navigation', [
        'staffPage' => $dashboardPage,
        'staffPageLabel' => $pageLabels[$dashboardPage],
        'showSessionRequests' => true,
        'pending_meetings_count' => $pending_meetings_count ?? 0,
    ]) ?>

    <!-- Main Content -->
    <main id="staffMain" class="staff-main container mx-auto px-3 sm:px-6 max-w-full flex-1 animate-fade-in pt-4 sm:pt-6">

        <!-- Welcome Metric Row -->
        <div class="staff-page-intro mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 animate-slide-up"
            style="animation-delay: 0.1s">
            <div>
                <h2 class="text-xl sm:text-2xl font-bold text-[#235D3A] flex items-center gap-2">
                    <svg class="w-6 h-6 text-[#F2C300]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    <?= esc($dashboardPage === 'vents' ? 'Student wellness' : $pageLabels[$dashboardPage]) ?>
                </h2>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1">
                    <p class="text-gray-600 text-xs sm:text-sm"><?= esc($pageDescriptions[$dashboardPage]) ?></p>

                </div>
            </div>
            <?php if (in_array($dashboardPage, ['vents', 'analytics'], true)): ?>
            <div class="staff-metrics <?= $dashboardPage === 'vents' ? 'staff-metrics-compact' : '' ?> grid grid-cols-2 sm:grid-cols-3 gap-3 w-full md:w-auto">
                <div class="stat-card green-accent flex items-center gap-3 p-3.5 sm:p-5">
                    <div class="h-9 w-9 rounded-lg bg-[#235D3A]/10 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5 text-[#235D3A]" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span
                            class="text-[9px] sm:text-[10px] text-gray-600 font-semibold uppercase tracking-wider truncate">Vent entries</span>
                        <span class="text-xl sm:text-2xl font-bold text-[#235D3A] leading-tight"
                            id="stat-total">--</span>
                    </div>
                </div>
                <div class="stat-card gold-accent flex items-center gap-3 p-3.5 sm:p-5">
                    <div class="h-9 w-9 rounded-lg bg-[#F2C300]/15 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5 text-[#B89100]" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span
                            class="text-[9px] sm:text-[10px] text-gray-600 font-semibold uppercase tracking-wider truncate">Critical</span>
                        <span class="text-xl sm:text-2xl font-bold text-[#E04B3F] leading-tight"
                            id="stat-critical">--</span>
                    </div>
                </div>
                <?php if ($dashboardPage === 'analytics'): ?>
                <a href="<?= base_url('admin/sessions') ?>"
                    class="stat-card flex items-center gap-3 p-3.5 sm:p-5 border-l-4 border-l-emerald-600 hover:border-emerald-700 col-span-2 sm:col-span-1 transition-all">
                    <div class="h-9 w-9 rounded-lg bg-emerald-500/10 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5 text-emerald-600" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span
                            class="text-[9px] sm:text-[10px] text-gray-600 font-semibold uppercase tracking-wider truncate">Session requests</span>
                        <span class="text-xl sm:text-2xl font-bold text-emerald-700 leading-tight"
                            id="stat-sessions"><?= esc($pending_meetings_count ?? 0) ?></span>
                    </div>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Live Feed Table -->
        <?php if ($dashboardPage === 'vents'): ?>
        <div id="section-vents" class="dashboard-card overflow-hidden animate-slide-up flex flex-col"
            style="animation-delay: 0.2s">
            <div class="queue-heading"><h3>Entries</h3></div>
            <!-- Table Container -->
            <div class="overflow-x-auto custom-scrollbar flex flex-col">
                <div
                    class="staff-filter-bar px-6 py-2 flex justify-between items-center bg-white border-b border-gray-100 flex-wrap gap-2">
                    <div class="flex items-center gap-2 flex-1 min-w-[200px] max-w-sm relative">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input type="text" id="searchVents" aria-label="Search entries, names, or risk levels" placeholder="Search entries, names, or risk levels..."
                            class="w-full pl-9 pr-4 py-1.5 text-sm border border-gray-200 rounded-lg outline-none focus:border-[#235D3A] focus:ring-1 focus:ring-[#235D3A]/20 transition-all">
                    </div>
                    <div class="staff-filter-controls flex items-center gap-2 flex-wrap">
                        <select id="filterStatusVents" aria-label="Filter entry status"
                            class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg outline-none focus:border-[#235D3A]">
                            <option value="all">All Statuses</option>
                            <option value="resolved">Resolved</option>
                            <option value="unresolved">Action Required</option>
                            <option value="archived">Archived Records</option>
                        </select>
                        <input type="date" id="filterDateVents" aria-label="Filter entry date"
                            class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg outline-none focus:border-[#235D3A] text-gray-600">
                    </div>
                    <button type="button" onclick="submitBulkDeleteVents()"
                        class="text-xs font-bold text-[#235D3A] bg-[#235D3A]/5 hover:bg-[#235D3A]/10 px-4 py-2 rounded-lg border border-[#235D3A]/20 transition-colors shadow-sm delete-btn-vents hidden">
                        📦 Archive Selected
                    </button>
                    <span id="feedUpdatedAt" class="staff-feed-updated text-xs text-gray-500" role="status">Loading entries…</span>
                </div>
                <table class="staff-vents-table w-full text-left border-collapse mobile-card-table">
                    <thead>
                        <tr class="thead-green text-xs uppercase tracking-wider">
                            <th class="px-4 py-4 w-10 text-center"><input type="checkbox" id="selectAllVents" aria-label="Select all entries"
                                    class="rounded border-white/40 text-[#F2C300]"></th>
                            <th class="px-6 py-4">Student</th>
                            <th class="px-6 py-4 w-1/3">Entry preview</th>
                            <th class="px-6 py-4 text-center">Screening score</th>
                            <th class="px-6 py-4 text-center">Risk level</th>
                            <th class="px-6 py-4">Timestamp</th>
                            <th class="px-6 py-4 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="ventsTableBody" class="divide-y divide-gray-100">
                        <!-- Populated by AJAX -->
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <svg class="animate-spin h-8 w-8 mx-auto mb-4 text-[#235D3A]"
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                        stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                    </path>
                                </svg>
                                <p>Initiating secure connection to StudentShield Core...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Bullying Incidents Table -->
        <?php endif; ?>

        <?php if ($dashboardPage === 'incidents'): ?>
        <div id="section-incidents" class="dashboard-card overflow-hidden animate-slide-up flex flex-col"
            style="animation-delay: 0.3s">
            <div class="section-header flex justify-between items-center sm:flex-row flex-col gap-4">
                <h2 class="text-lg font-bold flex items-center gap-3">
                    <svg class="h-5 w-5 text-[#E04B3F]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    Bullying Incident Reports
                </h2>
                <div class="flex items-center gap-4">
                </div>
            </div>

            <div class="overflow-x-auto custom-scrollbar flex flex-col">
                <div
                    class="staff-filter-bar px-6 py-2 flex justify-between items-center bg-white border-b border-gray-100 flex-wrap gap-2">
                    <div class="flex items-center gap-2 flex-1 min-w-[200px] max-w-sm relative">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input type="text" id="searchIncidents"
                            placeholder="Search reporters, accused, or descriptions..."
                            class="w-full pl-9 pr-4 py-1.5 text-sm border border-gray-200 rounded-lg outline-none focus:border-[#E04B3F] focus:ring-1 focus:ring-[#E04B3F]/20 transition-all">
                    </div>
                    <div class="staff-filter-controls flex items-center gap-2 flex-wrap">
                        <select id="filterStatusIncidents" aria-label="Filter incident status"
                            class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg outline-none focus:border-[#E04B3F]">
                            <option value="all">All Statuses</option>
                            <option value="resolved">Resolved</option>
                            <option value="unresolved">Action Required</option>
                            <option value="archived">Archived Records</option>
                        </select>
                        <input type="date" id="filterDateIncidents" aria-label="Filter incident date"
                            class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg outline-none focus:border-[#E04B3F] text-gray-600">
                    </div>
                    <button type="button" onclick="submitBulkDeleteIncidents()"
                        class="text-xs font-bold text-[#235D3A] bg-[#235D3A]/5 hover:bg-[#235D3A]/10 px-4 py-2 rounded-lg border border-[#235D3A]/20 transition-colors shadow-sm delete-btn-incidents hidden">
                        📦 Archive Selected
                    </button>
                </div>
                <table class="w-full text-left border-collapse mobile-card-table">
                    <thead>
                        <tr class="thead-green text-xs uppercase tracking-wider">
                            <th class="px-4 py-4 w-10 text-center"><input type="checkbox" id="selectAllIncidents"
                                    class="rounded border-white/40 text-[#F2C300]"></th>
                            <th class="px-6 py-4">Date</th>
                            <th class="px-6 py-4">Reporter</th>
                            <th class="px-6 py-4">Perpetrator</th>
                            <th class="px-6 py-4">Type</th>
                            <th class="px-6 py-4 w-1/3">Description</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            <th class="px-6 py-4 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="incidentsTableBody" class="divide-y divide-gray-100">
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                <p>Loading reports...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Session Requests (Counseling / Meeting) Section -->
        <?php endif; ?>

        <?php if ($dashboardPage === 'sessions'): ?>
        <div id="section-sessions" class="dashboard-card overflow-hidden animate-slide-up flex flex-col"
            style="animation-delay: 0.35s">
            <div class="section-header flex justify-between items-center sm:flex-row flex-col gap-3">
                <div>
                    <h2 class="text-lg font-bold flex items-center gap-3">
                        <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        Pending Counseling Session Requests
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">Students requesting 1-on-1 counselor meetings or virtual
                        sessions</p>
                </div>
                <div class="flex items-center gap-3">
                    <span id="sessionSectionCountBadge"
                        class="px-3 py-1 rounded-full text-xs font-bold bg-[#dcfce7] text-[#235D3A] border border-[#bbf7d0]">
                        <?= esc($pending_meetings_count ?? 0) ?> Pending
                    </span>
                </div>
            </div>

            <!-- Content Area: Responsive Card Grid / List -->
            <div class="p-4 sm:p-6" id="dashboardSessionContainer">
                <div id="dashboardSessionEmpty"
                    class="<?= ($pending_meetings_count ?? 0) > 0 ? 'hidden' : '' ?> py-8 text-center text-gray-500">
                    <svg class="w-10 h-10 mx-auto text-gray-300 mb-2" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm font-medium text-gray-600">No pending session requests at this time.</p>
                    <p class="text-xs text-gray-400 mt-1">When students request a session from their portal, it will
                        appear here in real-time.</p>
                </div>
                <div id="dashboardSessionList"
                    class="<?= ($pending_meetings_count ?? 0) > 0 ? '' : 'hidden' ?> grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <!-- Populated dynamically via JS fetchMeetings() -->
                </div>
            </div>
        </div>

        <!-- Alert Logs Monitor -->
        <?php endif; ?>

        <?php if ($dashboardPage === 'alerts'): ?>
        <div id="section-alerts" class="dashboard-card overflow-hidden animate-slide-up flex flex-col"
            style="animation-delay: 0.4s">
            <div class="section-header flex justify-between items-center sm:flex-row flex-col gap-4">
                <h2 class="text-lg font-bold flex items-center gap-3">
                    <svg class="h-5 w-5 text-[#2A5DB0]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    Alert Delivery Monitor
                </h2>
                <div class="flex items-center gap-2 bg-blue-50 px-3 py-1.5 rounded-full border border-blue-200">
                    <span class="w-2 h-2 rounded-full bg-blue-500 live-dot"></span>
                    <span class="text-[10px] font-bold text-blue-700 uppercase tracking-widest">System Log</span>
                </div>
            </div>

            <div class="overflow-x-auto custom-scrollbar flex flex-col max-h-[340px]">
                <table class="w-full text-left border-collapse relative mobile-card-table">
                    <thead class="sticky top-0 thead-green z-10">
                        <tr class="text-xs uppercase tracking-wider">
                            <th class="px-6 py-4">Timestamp</th>
                            <th class="px-6 py-4">Method</th>
                            <th class="px-6 py-4">Recipient</th>
                            <th class="px-6 py-4 w-1/2">Payload / Message</th>
                        </tr>
                    </thead>
                    <tbody id="alertsTableBody" class="divide-y divide-gray-100">
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-gray-500">
                                <p>Loading delivery logs...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Analytics Charts (below actionable records) -->
        <?php endif; ?>

        <?php if ($dashboardPage === 'analytics'): ?>
        <div id="section-charts" class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-2 animate-slide-up"
            style="animation-delay: 0.5s">
            <div class="dashboard-card p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-base font-semibold text-[#235D3A] flex items-center gap-2">
                        <svg class="h-5 w-5 text-[#F2C300]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                        </svg>
                        Risk Distribution
                    </h2>
                </div>
                <div class="relative h-64 w-full">
                    <canvas id="riskPieChart"></canvas>
                </div>
            </div>
            <div class="dashboard-card p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-base font-semibold text-[#235D3A] flex items-center gap-2">
                        <svg class="h-5 w-5 text-[#F2C300]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                        </svg>
                        Last 7 Days Volume
                    </h2>
                </div>
                <div class="relative h-64 w-full">
                    <div id="ventsBarChart"></div>
                </div>
            </div>
        </div>

        <?= view('partials/risk_level_guide') ?>
        <?php endif; ?>

    </main>

    <!-- Universal Detail Modal -->
    <div id="detailModal" class="fixed inset-0 hidden items-center justify-center p-4 sm:p-6" style="z-index: 99999;" role="dialog" aria-modal="true" aria-label="Entry Details">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm transition-opacity" id="detailBackdrop"
            onclick="closeDetailModal()"></div>
        <div
            class="bg-white max-w-2xl w-[96%] rounded-2xl shadow-[0_20px_60px_-15px_rgba(0,0,0,0.3)] relative z-10 flex flex-col max-h-[90dvh] border border-[#e5ebe5] overflow-hidden">
            <div class="p-5 sm:p-7 flex flex-col min-h-0 flex-1 overflow-y-auto custom-scrollbar"><div class="flex justify-between items-start mb-4 shrink-0">
                <div>
                    <div id="modalBadge"
                        class="inline-flex px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest mb-2 border">
                    </div>
                    <h2 id="modalTitle" class="text-2xl font-heading font-extrabold text-[#235D3A] tracking-tight">
                        Report Details</h2>
                </div>
                <button onclick="closeDetailModal()"
                    class="text-gray-500 hover:text-red-500 transition-colors p-2 bg-gray-50 hover:bg-red-50 rounded-full">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

            <div class="flex items-center gap-6 mb-6 pb-6 border-b border-gray-200 shrink-0">
                <div>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Involved Party /
                        Sender</p>
                    <p id="modalSender" class="text-sm font-semibold text-gray-800">--</p>
                </div>
                <div>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Timestamp</p>
                    <p id="modalTime" class="text-sm font-semibold text-gray-800">--</p>
                </div>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar pr-2 pb-4">
                <p class="text-[11px] font-bold text-[#235D3A] uppercase tracking-widest mb-2">Full Context Payload</p>
                <div id="modalContent"
                    class="text-[16px] leading-[1.85] tracking-wide text-gray-800 bg-[#f8faf6] border border-[#e5ebe5] shadow-inner p-7 rounded-2xl whitespace-pre-wrap font-medium">
                    --
                </div>
                <div id="modalEvidenceContainer" class="mt-4 hidden">
                    <p class="text-[11px] font-bold text-[#235D3A] uppercase tracking-widest mb-2">Attached Evidence</p>

                    <div id="evidencePreviewArea"
                        class="mt-2 mb-3 bg-gray-50 rounded-xl overflow-hidden flex justify-center items-center border border-gray-200">
                        <img id="evidenceImagePreview" src="" alt="Evidence"
                            class="hidden max-w-full max-h-[250px] object-contain" />
                        <video id="evidenceVideoPreview" src="" controls
                            class="hidden max-w-full max-h-[250px]"></video>
                    </div>

                    <a id="modalEvidenceLink" href="#" target="_blank"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg border border-blue-200 transition-colors font-semibold text-sm w-full justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                        Open in New Tab
                    </a>
                </div>
            </div>

            </div><div class="px-5 sm:px-7 py-4 border-t border-gray-200 flex justify-end shrink-0 bg-white">
                <button onclick="closeDetailModal()"
                    class="px-6 py-3 bg-primary hover:bg-primary-darker text-white text-sm font-bold rounded-xl transition-colors shadow-sm">Confirm
                    & Close</button>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer-bar mt-auto">
        <div class="container mx-auto px-6">
            <span>© <?= date('Y') ?> StudentShield — St. Vincent Blessed School of Manila</span>
            <span class="mx-2">·</span>
            <span>Intelligent Student Wellness Platform</span>
        </div>
    </footer>

    <!-- AJAX Logic & Chart Rendering -->
    <script>
        const API_URL = "<?= base_url('admin/api/vents') ?>";
        let pieChart, barChart;
        let previousVentsDataString = "";
        let isFirstLoadVents = true;

        // Enable jQuery AJAX caching to rely on browser cache if Etags match
        $.ajaxSetup({ cache: true });

        // Set modern defaults for Chart.js
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#64748b';
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.9)';
        Chart.defaults.plugins.tooltip.padding = 12;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;

        const pieCtx = document.getElementById('riskPieChart')?.getContext('2d');
        const pieConfig = {
            type: 'doughnut',
            data: {
                labels: ['Critical', 'High', 'Medium', 'Low'],
                datasets: [{
                    data: [0, 0, 0, 0],
                    backgroundColor: ['#E04B3F', '#F59E0B', '#2A5DB0', '#235D3A'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }
                }
            }
        };
        if (pieCtx) pieChart = new Chart(pieCtx, pieConfig);

        const barCtx = document.querySelector("#ventsBarChart");

        const barConfig = {
            series: [{
                name: 'Vents Processed',
                data: [0, 0, 0, 0, 0, 0, 0]
            }],
            chart: {
                type: 'area',
                height: 250,
                toolbar: { show: false },
                fontFamily: 'Inter, sans-serif',
                animations: { enabled: true, easing: 'easeinout', speed: 800 }
            },
            colors: ['#235D3A'],
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.7, opacityTo: 0.1, stops: [0, 90, 100] }
            },
            dataLabels: { enabled: false },
            stroke: { curve: 'straight', width: 3 },
            markers: { size: 5, strokeWidth: 2, strokeColors: '#ffffff', hover: { size: 7 } },
            tooltip: { enabled: true, shared: false, intersect: false, y: { formatter: value => value + (value === 1 ? ' entry' : ' entries') } },
            xaxis: {
                categories: ['6d', '5d', '4d', '3d', '2d', '1d', 'Today'],
                axisBorder: { show: false },
                axisTicks: { show: false }
            },
            yaxis: { show: true, min: 0, forceNiceScale: true, decimalsInFloat: 0, title: { text: 'Entries' }, labels: { formatter: value => Number.isInteger(value) ? String(value) : '' } },
            grid: {
                borderColor: 'rgba(0,0,0,0.05)',
                strokeDashArray: 4,
                yaxis: { lines: { show: true } }
            }
        };

        let areaChart = barCtx ? new ApexCharts(barCtx, barConfig) : null;
        if (areaChart) areaChart.render();

        let cachedDateLabels = null;

        function updateCharts(data) {
            let critical = 0, high = 0, medium = 0, low = 0;
            let criticalPending = 0;

            data.forEach(v => {
                if (v.risk_level === 'CRITICAL') {
                    critical++;
                    if (v.status !== 'Resolved') criticalPending++;
                }
                else if (v.risk_level === 'HIGH') high++;
                else if (v.risk_level === 'MEDIUM') medium++;
                else low++;
            });

            $('#stat-total').text(data.length);
            $('#stat-critical').text(criticalPending);

            // Update Pie Chart seamlessly without destroying
            if (pieChart) {
                pieChart.data.datasets[0].data = [critical, high, medium, low];
                pieChart.update('none');
            }
            if (!areaChart) return; // Update without full animation redraw

            // Only calculate Date labels once per session to save CPU
            if (!cachedDateLabels) {
                cachedDateLabels = [];
                let today = new Date();
                today.setHours(0, 0, 0, 0);
                for (let i = 6; i >= 0; i--) {
                    let d = new Date(today);
                    d.setDate(d.getDate() - i);
                    let label = d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
                    cachedDateLabels.push(label);
                }
                areaChart.updateOptions({ xaxis: { categories: cachedDateLabels } });
            }

            let daysData = [0, 0, 0, 0, 0, 0, 0];
            let today = new Date();
            today.setHours(0, 0, 0, 0);

            data.forEach(v => {
                let vDate = parsePHDate(v.created_at);
                vDate.setHours(0, 0, 0, 0);
                let diffTime = Math.abs(today - vDate);
                let diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
                if (diffDays <= 6 && diffDays >= 0) {
                    daysData[6 - diffDays]++;
                }
            });

            areaChart.updateSeries([{ data: daysData }]);
        }

        // Bulletproof Date parsing for Safari & mobile browsers turning PHT times into UTC
        function parsePHDate(dateStr) {
            if (!dateStr) return new Date();
            if (typeof dateStr === 'string' && dateStr.includes(' ') && !dateStr.includes('+')) {
                return new Date(dateStr.replace(' ', 'T') + '+08:00');
            }
            return new Date(dateStr);
        }

        function escapeHtml(unsafe) {
            return (unsafe || '').toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        let ventsRequestVersion = 0;
        let incidentsRequestVersion = 0;
        let showArchivedVents = false;
        let showArchivedIncidents = false;

        function toggleVentsView(isArchived) {
            showArchivedVents = isArchived;
            previousVentsDataString = "";
            fetchLiveVents();

            let form = document.getElementById('bulkDeleteVentsForm');
            let btn = document.querySelector('.delete-btn-vents');
            if (!btn) return;
            if (isArchived) {
                form.action = "<?= base_url('admin/vent/restore-multiple') ?>";
                btn.innerHTML = "⟲ Restore Selected";
                btn.className = "text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 px-4 py-2 rounded-lg border border-blue-200 transition-colors shadow-sm delete-btn-vents " + (btn.classList.contains('hidden') ? 'hidden' : '');
            } else {
                form.action = "<?= base_url('admin/vent/delete-multiple') ?>";
                btn.innerHTML = "📦 Archive Selected";
                btn.className = "text-xs font-bold text-[#235D3A] bg-[#235D3A]/5 hover:bg-[#235D3A]/10 px-4 py-2 rounded-lg border border-[#235D3A]/20 transition-colors shadow-sm delete-btn-vents " + (btn.classList.contains('hidden') ? 'hidden' : '');
            }
        }

        function toggleIncidentsView(isArchived) {
            showArchivedIncidents = isArchived;
            previousIncidentsDataString = "";
            fetchLiveIncidents();

            let form = document.getElementById('bulkDeleteIncidentsForm');
            let btn = document.querySelector('.delete-btn-incidents');
            if (!btn) return;
            if (isArchived) {
                form.action = "<?= base_url('admin/incident/restore-multiple') ?>";
                btn.innerHTML = "⟲ Restore Selected";
                btn.className = "text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 px-4 py-2 rounded-lg border border-blue-200 transition-colors shadow-sm delete-btn-incidents " + (btn.classList.contains('hidden') ? 'hidden' : '');
            } else {
                form.action = "<?= base_url('admin/incident/delete-multiple') ?>";
                btn.innerHTML = "📦 Archive Selected";
                btn.className = "text-xs font-bold text-[#235D3A] bg-[#235D3A]/5 hover:bg-[#235D3A]/10 px-4 py-2 rounded-lg border border-[#235D3A]/20 transition-colors shadow-sm delete-btn-incidents " + (btn.classList.contains('hidden') ? 'hidden' : '');
            }
        }

        function refreshTable(tbodyId, html) {
            const tbody = document.getElementById(tbodyId);
            if (!tbody) return;
            const selected = new Set([...tbody.querySelectorAll('input[type="checkbox"]:checked')].map(input => input.value));
            const open = new Set([...tbody.querySelectorAll('tr[data-entry-id]')].flatMap(row => [...row.querySelectorAll('details')].map((detail, index) => detail.open ? row.dataset.entryId + ':' + index : null).filter(Boolean)));
            tbody.innerHTML = html;
            tbody.querySelectorAll('input[type="checkbox"]').forEach(input => { input.checked = selected.has(input.value); });
            tbody.querySelectorAll('tr[data-entry-id]').forEach(row => row.querySelectorAll('details').forEach((detail, index) => { detail.open = open.has(row.dataset.entryId + ':' + index); }));
        }

        function fetchLiveVents() {
            if (!document.getElementById('ventsTableBody') && !document.getElementById('riskPieChart')) return;
            const requestVersion = ++ventsRequestVersion;
            const archived = showArchivedVents;
            $.ajax({
                url: API_URL + (archived ? "?archived=1" : ""),
                dataType: 'json',
                success: function (data) {
                    if (requestVersion !== ventsRequestVersion || archived !== showArchivedVents) return;
                    const feedStatus = document.getElementById('feedUpdatedAt');
                    if (feedStatus) feedStatus.textContent = 'Updated ' + new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
                    let currentDataString = JSON.stringify(data);
                    if (currentDataString === previousVentsDataString) return;
                    previousVentsDataString = currentDataString;
                    if (!document.getElementById('ventsTableBody')) {
                        updateCharts(data);
                        return;
                    }

                    let html = '';
                    window.ventsDataMap = {};

                    if (data.length === 0) {
                        html = `<tr><td colspan="7" class="px-6 py-8 text-center text-gray-600">No entries found yet.</td></tr>`;
                    } else {
                        data.forEach((vent, index) => {
                            window.ventsDataMap[vent.id] = vent;
                            let textSnippet = vent.raw_text.length > 55 ? vent.raw_text.substring(0, 55) + '...' : vent.raw_text;
                            let bgColor = 'bg-white';
                            let badgeClass = 'bg-gray-100 text-gray-800';
                            let statusIcon = '';

                            // Threat logic (Color-coded Triage)
                            if (vent.risk_level === 'CRITICAL') {
                                bgColor = 'bg-white hover:bg-gray-50 border-l-4 border-l-[#E04B3F] relative';
                                badgeClass = 'bg-[#E04B3F] text-white font-bold border-none';
                            } else if (vent.risk_level === 'HIGH') {
                                bgColor = 'bg-white hover:bg-gray-50 border-l-4 border-l-[#F59E0B] relative';
                                badgeClass = 'bg-[#F59E0B] text-white font-bold border-none';
                            } else if (vent.risk_level === 'MEDIUM') {
                                bgColor = 'bg-white hover:bg-gray-50 border-l-4 border-l-[#2A5DB0]';
                                badgeClass = 'bg-[#2A5DB0]/10 text-[#2A5DB0] border border-[#2A5DB0]/20 font-semibold';
                            } else {
                                bgColor = 'bg-white hover:bg-gray-50 border-l-4 border-l-[#235D3A]';
                                badgeClass = 'bg-[#235D3A]/10 text-[#235D3A] border border-[#235D3A]/20';
                            }

                            // Status formatting
                            let isResolved = vent.status === 'Resolved' || vent.status === 'Reviewed';
                            let statusColor = isResolved ? 'text-[#235D3A] bg-[#235D3A]/10' : 'text-gray-600 bg-gray-50';

                            // Formatting dates with day name for Philippine context
                            const dateObj = parsePHDate(vent.created_at);
                            const timeStr = dateObj.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
                            const dayName = dateObj.toLocaleDateString('en-PH', { weekday: 'short' });
                            const dateStr = dateObj.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });

                            // Animation delay for new loads
                            const animDelay = isFirstLoadVents ? `style="animation-delay: ${index * 0.05}s"` : '';
                            const animClass = isFirstLoadVents ? 'animate-slide-up opacity-0' : '';

                            const contactDetails = `                                        ${vent.adviser_name ? `<span class="text-[11px] text-gray-600 font-bold block mb-1">Adviser: <span class="font-normal text-gray-600">${escapeHtml(vent.adviser_name)}</span></span>` : ''}
                                        ${vent.parent_phone ? `<span class="text-[10px] text-gray-500 block pt-0.5 border-t border-gray-200 mt-1">📞 ${escapeHtml(vent.parent_phone)}</span>` : ''}
                                        ${vent.parent_email ? `<span class="text-[10px] text-gray-500 block">✉️ ${escapeHtml(vent.parent_email)}</span>` : ''}`;

                            const rawDate = dateObj.toISOString().split('T')[0]; const ventStatus = isResolved ? "resolved" : "unresolved"; html += `<tr data-entry-id="${vent.id}" data-status="${ventStatus}" data-date="${rawDate}" data-risk="${escapeHtml(vent.risk_level)}" class="transition-all duration-300 transform hover:-translate-y-[1px] ${bgColor} ${animClass}" ${animDelay}>
                            ${vent.risk_level === 'CRITICAL' && !isResolved ? `` : ''}
                            <td data-label="Select" class="px-4 py-4 whitespace-nowrap text-center relative z-10">
                                <label class="staff-entry-select"><input type="checkbox" name="vent_ids[]" value="${vent.id}" aria-label="Select entry by ${escapeHtml(vent.name)}" class="vent-checkbox rounded border-gray-300 text-primary h-4 w-4"></label>
                            </td>
                            <td data-label="Student" class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center cursor-pointer group" onclick="event.stopPropagation(); openStudentProfile(${vent.user_id})">
                                    <div class="h-8 w-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xs mr-3 group-hover:bg-primary group-hover:text-white transition-colors">
                                        ${escapeHtml(vent.name.charAt(0))}
                                    </div>
                                    <div>
                                        <span class="text-sm font-medium text-gray-900 block group-hover:text-primary transition-colors hover:underline">${escapeHtml(vent.name)}</span>
                                        ${vent.section ? `<span class="text-[11px] text-gray-600 font-bold block mt-0.5">Section: <span class="font-normal text-gray-600">${escapeHtml(vent.section)}</span></span>` : ''}
                                        ${vent.student_id ? `<span class="staff-student-number">${escapeHtml(vent.student_id)}</span>` : ''}
                                        ${contactDetails.trim() ? `<details class="staff-student-details" onclick="event.stopPropagation()"><summary>Student details</summary><div>${contactDetails}</div></details>` : ''}
                                    </div>
                                </div>
                            </td>
                            <td data-label="NLP Extract" class="px-6 py-4 cursor-pointer group" onclick="openVentModal(${vent.id})">
                                <p class="text-sm text-gray-600 italic line-clamp-2">"${escapeHtml(textSnippet)}"</p>
                            </td>
                            <td data-label="Risk Indicator" class="px-6 py-4 whitespace-nowrap text-center">
                                ${(() => {
                                    let s = vent.risk_percent;
                                    let s_float = parseFloat(s);

                                    // Fallback UI mapping for legacy database records (decimals)
                                    if (Math.abs(s_float) <= 1 && s_float !== 0 && s.toString().indexOf('.') !== -1) {
                                        // Legacy: -1 was bad, 1 was good
                                        // Convert to new Threat Percentage where 100 is bad, 1 is good
                                        s = Math.round(((1.0 - s_float) / 2.0) * 99) + 1;
                                    } else {
                                        s = Math.round(s_float);
                                    }

                                    // High percentage = High Threat
                                    let c = s >= 50 ? 'text-[#E04B3F] bg-[#E04B3F]/10' : 'text-[#235D3A] bg-[#235D3A]/10';
                                    return `<span class="text-sm font-mono font-medium ${c} px-2 py-1 rounded min-w-[3rem] inline-block">${s}%</span>`;
                                })()}
                            </td>
                            <td data-label="Threat Level" class="px-6 py-4 whitespace-nowrap text-center">
                                <span class="px-3 py-1 inline-flex text-xs leading-5 font-bold rounded-full ${badgeClass} shadow-sm">
                                    ${escapeHtml(vent.risk_level)}
                                </span>
                            </td>
                            <td data-label="Timestamp" class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-800">${timeStr}</div>
                                <div class="text-xs text-gray-600">${dayName}, ${dateStr}</div>
                            </td>
                            <td data-label="Action" class="px-6 py-4 whitespace-nowrap text-center">
                                <div class="staff-action-row">
                                <button type="button" class="staff-review-button" onclick="openVentModal(${vent.id})">Review entry</button>
                                <details class="staff-entry-actions">
                                    <summary aria-label="More actions for entry by ${escapeHtml(vent.name)}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg></summary>
                                    <div class="staff-entry-action-menu">
                                ${!isResolved && !showArchivedVents ? `
                                    <form action="<?= base_url('admin/vent/resolve/') ?>${vent.id}" method="POST" class="block w-full mb-1">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="w-full text-xs font-semibold text-primary bg-primary/10 hover:bg-primary/20 px-3 py-1.5 rounded-md transition-colors">
                                            Mark Resolved
                                        </button>
                                    </form>
                                ` : (!showArchivedVents ? `
                                    <span class="text-xs font-semibold px-3 py-1.5 rounded-md ${statusColor} border border-[#235D3A]/20 flex items-center justify-center gap-1 mx-auto w-full mb-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        Resolved
                                    </span>
                                ` : '')}

                                ${showArchivedVents ? `
                                <form action="<?= base_url('admin/vent/restore/') ?>${vent.id}" method="POST" class="block w-full">
                                    <?= csrf_field() ?>
                                    <button type="submit" onclick="return confirm('Restore this vent to the active dashboard?');" class="w-full text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-md transition-colors">
                                        ⟲ Unarchive
                                    </button>
                                </form>
                                ` : `
                                <form action="<?= base_url('admin/vent/delete/') ?>${vent.id}" method="POST" class="block w-full">
                                    <?= csrf_field() ?>
                                    <button type="submit" onclick="return confirm('Archive this vent?');" class="w-full text-xs font-semibold text-gray-600 bg-gray-50 hover:bg-gray-100 px-3 py-1.5 rounded-md transition-colors">
                                        Archive
                                    </button>
                                </form>
                                `}
                                    </div>
                                </details>
                                </div>
                            </td>
                        </tr>`;
                        });
                    }

                    refreshTable('ventsTableBody', html);

                    updateCharts(data);
                    isFirstLoadVents = false;

                    toggleDeleteBtn('.vent-checkbox', '.delete-btn-vents');
                    applyVentsFilter();
                },
                error: function (xhr) {
                    if (requestVersion !== ventsRequestVersion || archived !== showArchivedVents) return;
                    const feedStatus = document.getElementById('feedUpdatedAt');
                    if (feedStatus) feedStatus.textContent = xhr.status === 401 ? 'Session expired — sign in again' : 'Could not refresh entries';
                    if (xhr.status === 401) {
                        console.warn('Session expired. Please refresh and log in again.');
                    } else {
                        console.error("Vents API Error:", xhr.responseText);
                    }
                }
            });
        }

        // Incident Reports Polling
        const INCIDENTS_API_URL = "<?= base_url('admin/api/incidents') ?>";
        let previousIncidentsDataString = "";

        function fetchLiveIncidents() {
            if (!document.getElementById('incidentsTableBody')) return;
            const requestVersion = ++incidentsRequestVersion;
            const archived = showArchivedIncidents;
            $.ajax({
                url: INCIDENTS_API_URL + (archived ? "?archived=1" : ""),
                dataType: 'json',
                success: function (data) {
                    if (requestVersion !== incidentsRequestVersion || archived !== showArchivedIncidents) return;
                    let currentDataString = JSON.stringify(data);
                    if (currentDataString === previousIncidentsDataString) return;
                    previousIncidentsDataString = currentDataString;

                    let html = '';
                    window.incidentsDataMap = {};

                    if (data.length === 0) {
                        html = `<tr><td colspan="8" class="px-6 py-8 text-center text-gray-600">No incident reports available.</td></tr>`;
                    } else {
                        data.forEach((incident, index) => {
                            window.incidentsDataMap[incident.id] = incident;
                            let textSnippet = incident.description.length > 55 ? incident.description.substring(0, 55) + '...' : incident.description;
                            let statusBadge = '';
                            let isPending = incident.status === 'pending_review';
                            let isVerified = incident.status === 'verified';
                            let isRejected = incident.status === 'rejected_fake';
                            let isResolved = incident.status === 'resolved' || incident.status === 'Resolved';

                            if (isVerified) {
                                statusBadge = `<span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold w-max mx-auto border border-green-200 block">✓ Verified</span>`;
                            } else if (isRejected) {
                                statusBadge = `<span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs font-bold w-max mx-auto border border-red-200 block">✗ Rejected</span>`;
                            } else if (isResolved) {
                                statusBadge = `<span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold w-max mx-auto border border-green-200 block">Resolved</span>`;
                            } else {
                                statusBadge = `<span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-xs font-bold w-max mx-auto border border-yellow-200 animate-pulse block">⏳ Pending Review</span>`;
                            }

                            let reporterName = incident.name ? incident.name : 'Anonymous';
                            let perpetratorName = incident.perpetrator_name ? incident.perpetrator_name : 'Unknown';

                            const dateObj = parsePHDate(incident.created_at);
                            const dayName = dateObj.toLocaleDateString('en-PH', { weekday: 'short' });
                            const dateStr = dayName + ', ' + dateObj.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

                            let bgColor = isPending ? 'bg-white hover:bg-gray-50 border-l-4 border-l-amber-400' : (isRejected ? 'bg-white hover:bg-gray-50 border-l-4 border-l-red-500' : 'bg-white hover:bg-gray-50 border-l-4 border-l-transparent');

                            let evidenceBadge = incident.evidence_file ? `<span class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">📎 Evidence Attached</span>` : '';

                            const rawDate = dateObj.toISOString().split('T')[0]; const incidentStatus = isResolved ? "resolved" : "unresolved"; html += `<tr data-entry-id="${incident.id}" data-status="${incidentStatus}" data-date="${rawDate}" class="transition-colors duration-200 ${bgColor}">
                            <td data-label="Select" class="px-4 py-4 whitespace-nowrap text-center">
                                <input type="checkbox" name="incident_ids[]" value="${incident.id}" class="incident-checkbox rounded border-gray-300 text-primary h-4 w-4">
                            </td>
                            <td data-label="Date" class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">${dateStr}</td>
                            <td data-label="Reporter" class="px-6 py-4 whitespace-nowrap">
                                <div ${incident.reporter_id ? `class="cursor-pointer group" onclick="event.stopPropagation(); openStudentProfile(${incident.reporter_id})"` : ''}>
                                    <span class="text-sm font-medium text-gray-900 block ${incident.reporter_id ? 'group-hover:text-primary transition-colors hover:underline' : ''}">${escapeHtml(reporterName)}</span>
                                    ${incident.parent_phone ? `<span class="text-xs text-gray-600 block">📞 ${escapeHtml(incident.parent_phone)}</span>` : ''}
                                    ${incident.parent_email ? `<span class="text-xs text-gray-600 block">✉️ ${escapeHtml(incident.parent_email)}</span>` : ''}
                                </div>
                            </td>
                            <td data-label="Perpetrator" class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">${escapeHtml(perpetratorName)}</td>
                            <td data-label="Type" class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-semibold">
                                Bullying Report
                                ${evidenceBadge ? '<br>' + evidenceBadge : ''}
                            </td>
                            <td data-label="Description" class="px-6 py-4 cursor-pointer group" onclick="openIncidentModal(${incident.id})">
                                <p class="text-sm text-gray-600 italic line-clamp-2">"${escapeHtml(textSnippet)}"</p>
                                <span class="text-[10px] text-[#2A5DB0] font-bold mt-1 inline-block opacity-0 group-hover:opacity-100 transition-opacity">READ FULL ↗</span>
                            </td>
                            <td data-label="Status" class="px-6 py-4 whitespace-nowrap text-center">${statusBadge}</td>
                            <td data-label="Action" class="px-6 py-4 whitespace-nowrap text-center">
                                <div class="flex flex-col gap-2 min-w-[140px]">
                                ${isPending && !showArchivedIncidents ? `
                                    <form action="<?= base_url('admin/incident/verify/') ?>${incident.id}" method="POST">
                                        <?= csrf_field() ?>
                                        <button type="submit" onclick="return confirm('VERIFY this report and send automated SMS & Email alerts to parents?');" class="w-full text-xs font-bold text-white bg-primary hover:bg-primary-darker px-3 py-2.5 rounded-lg transition-colors shadow-sm">
                                            ✓ Verify & Alert Parents
                                        </button>
                                    </form>
                                    <form action="<?= base_url('admin/incident/reject/') ?>${incident.id}" method="POST">
                                        <?= csrf_field() ?>
                                        <button type="submit" onclick="return confirm('REJECT this report as fake/unverified? No parent alerts will be sent.');" class="w-full text-xs font-semibold text-red-600 bg-red-50 hover:bg-red-100 px-3 py-2 rounded-lg transition-colors border border-red-200">
                                            ✗ Reject (Fake)
                                        </button>
                                    </form>
                                ` : ''}
                                ${isVerified && !showArchivedIncidents ? `
                                    <span class="text-xs font-semibold px-3 py-2 rounded-lg text-[#235D3A] bg-[#235D3A]/10 border border-[#235D3A]/20 flex items-center justify-center gap-1.5 w-full">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        Alerts Sent
                                    </span>
                                    <form action="<?= base_url('admin/incident/undo/') ?>${incident.id}" method="POST">
                                        <?= csrf_field() ?>
                                        <button type="submit" onclick="return confirm('Undo verification and reset to Pending Review? (Note: alerts already sent cannot be unsent)');" class="w-full text-[10px] font-semibold text-gray-600 hover:text-orange-600 bg-gray-50 hover:bg-orange-50 px-2 py-1.5 rounded-lg transition-colors border border-gray-300 hover:border-orange-200">
                                            ↩ Undo (Nagkamali?)
                                        </button>
                                    </form>
                                ` : ''}
                                ${isRejected && !showArchivedIncidents ? `
                                    <span class="text-xs font-semibold px-3 py-2 rounded-lg text-red-500 bg-red-50 border border-red-200 flex items-center justify-center gap-1 w-full">
                                        No Alerts Sent
                                    </span>
                                    <form action="<?= base_url('admin/incident/undo/') ?>${incident.id}" method="POST">
                                        <?= csrf_field() ?>
                                        <button type="submit" onclick="return confirm('Undo rejection and reset to Pending Review so you can re-evaluate?');" class="w-full text-[10px] font-semibold text-gray-600 hover:text-orange-600 bg-gray-50 hover:bg-orange-50 px-2 py-1.5 rounded-lg transition-colors border border-gray-300 hover:border-orange-200">
                                            ↩ Undo (Nagkamali?)
                                        </button>
                                    </form>
                                ` : ''}
                                ${isResolved && !showArchivedIncidents ? `
                                    <span class="text-xs font-semibold px-3 py-2 rounded-lg text-[#235D3A] bg-[#235D3A]/10 border border-[#235D3A]/20 flex items-center justify-center gap-1.5 w-full">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        Resolved
                                    </span>
                                ` : ''}

                                <hr class="border-gray-200 my-0.5">

                                ${showArchivedIncidents ? `
                                <form action="<?= base_url('admin/incident/restore/') ?>${incident.id}" method="POST">
                                    <?= csrf_field() ?>
                                    <button type="submit" onclick="return confirm('Restore this incident report to the active dashboard?');" class="w-full text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors">
                                        ⟲ Unarchive
                                    </button>
                                </form>
                                ` : `
                                <form action="<?= base_url('admin/incident/delete/') ?>${incident.id}" method="POST">
                                    <?= csrf_field() ?>
                                    <button type="submit" onclick="return confirm('Archive this incident report?');" class="w-full text-xs font-semibold text-gray-500 hover:text-gray-600 bg-gray-50 hover:bg-gray-100 px-3 py-1.5 rounded-lg transition-colors">
                                        Archive
                                    </button>
                                </form>
                                `}
                                </div>
                            </td>
                        </tr>`;
                        });
                    }

                    refreshTable('incidentsTableBody', html);

                    toggleDeleteBtn('.incident-checkbox', '.delete-btn-incidents');
                    applyIncidentsFilter();

                },
                error: function (xhr) {
                    if (requestVersion !== incidentsRequestVersion || archived !== showArchivedIncidents) return;
                    if (xhr.status === 401) {
                        console.warn('Session expired. Please refresh and log in again.');
                    } else {
                        console.error("Incidents API Error:", xhr.responseText);
                    }
                }
            });
        }

        // Alert Logs Polling
        const ALERTS_API_URL = "<?= base_url('admin/api/alerts') ?>";
        let previousAlertsDataString = "";

        /**
         * Collapse table body to `maxRows` visible rows.
         * Appends a sticky toggle row. colSpan = number of <th> columns.
         */
        function collapseRows(tbodyId, maxRows, colSpan, sectionId) {
            const tbody = document.getElementById(tbodyId);
            if (!tbody) return;
            // Remove any previous toggle rows
            tbody.querySelectorAll('.show-all-row').forEach(r => r.remove());
            tbody.querySelectorAll('tr.tbl-row-hidden').forEach(r => r.classList.remove('tbl-row-hidden'));

            const rows = Array.from(tbody.querySelectorAll('tr:not(.show-all-row)')).filter(row => !row.hidden);
            if (rows.length <= maxRows) return; // nothing to collapse

            let expanded = tbody.dataset.expanded === 'true';

            rows.forEach((r, i) => {
                if (i >= maxRows) r.classList.toggle('tbl-row-hidden', !expanded);
            });

            const toggleRow = document.createElement('tr');
            toggleRow.className = 'show-all-row bg-gray-50 hover:bg-gray-100 transition-colors';
            toggleRow.innerHTML = `<td colspan="${colSpan}" class="px-6 py-3 text-center">
                <button class="text-xs font-bold text-[#235D3A] flex items-center gap-1.5 mx-auto" id="toggle-${tbodyId}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    ${expanded ? 'Collapse' : 'Show all ' + rows.length + ' records'}
                </button>
            </td>`;
            tbody.appendChild(toggleRow);

            toggleRow.querySelector('button').addEventListener('click', function () {
                expanded = !expanded;
                tbody.dataset.expanded = String(expanded);
                rows.forEach((r, i) => {
                    if (i >= maxRows) r.classList.toggle('tbl-row-hidden', !expanded);
                });
                this.innerHTML = expanded
                    ? `<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg> Collapse`
                    : `<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg> Show all ${rows.length} records`;
                if (!expanded && sectionId) {
                    document.getElementById(sectionId)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }

        function fetchAlertLogs() {
            if (!document.getElementById('alertsTableBody')) return;
            $.ajax({
                url: ALERTS_API_URL,
                dataType: 'json',
                success: function (data) {
                    let currentDataString = JSON.stringify(data);
                    if (currentDataString === previousAlertsDataString) return;
                    previousAlertsDataString = currentDataString;

                    let html = '';
                    window.alertsDataMap = {};

                    if (data.length === 0) {
                        html = `<tr><td colspan="4" class="px-6 py-8 text-center text-gray-600">No automated alerts have been sent yet.</td></tr>`;
                    } else {
                        data.forEach((alert, index) => {
                            window.alertsDataMap[index] = alert;
                            let badgeClass = alert.type.includes('SMS') ? 'bg-blue-100 text-blue-700 border-blue-200' : 'bg-purple-100 text-purple-700 border-purple-200';
                            let icon = alert.type.includes('SMS') ? '📱' : '✉️';

                            const dateObj = parsePHDate(alert.timestamp);
                            const dayName = dateObj.toLocaleDateString('en-PH', { weekday: 'short' });
                            const dateStr = dayName + ', ' + dateObj.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

                            html += `<tr class="transition-colors duration-200 bg-white hover:bg-gray-50">
                                <td data-label="Timestamp" class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-medium">${dateStr}</td>
                                <td data-label="Method" class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 inline-flex text-xs leading-5 font-bold rounded-md border ${badgeClass}">
                                        ${icon} ${escapeHtml(alert.type)}
                                    </span>
                                </td>
                                <td data-label="Recipient" class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-800">${escapeHtml(alert.recipient)}</td>
                                <td data-label="Payload / Message" class="px-6 py-4 cursor-pointer group" onclick="openAlertModal(${index})">
                                    <p class="text-sm text-gray-600 line-clamp-2 italic">"${escapeHtml(alert.message)}"</p>
                                    <span class="text-[10px] text-[#2A5DB0] font-bold mt-1 inline-block opacity-0 group-hover:opacity-100 transition-opacity">VIEW PAYLOAD ↗</span>
                                </td>
                            </tr>`;
                        });
                    }
                    document.getElementById('alertsTableBody').innerHTML = html;
                },
                error: function (xhr) {
                    console.error("Alerts API Error:", xhr.responseText);
                }
            });
        }

        function openDetailModal(type, sender, timeStr, fullText, badgeText, badgeClass) {
            document.getElementById('modalTitle').innerText = type;
            document.getElementById('modalSender').innerText = sender;
            document.getElementById('modalTime').innerText = timeStr;
            document.getElementById('modalContent').innerText = fullText;

            if (document.getElementById('modalEvidenceContainer')) {
                document.getElementById('modalEvidenceContainer').classList.add('hidden');
                const videoPreview = document.getElementById('evidenceVideoPreview');
                if (videoPreview) videoPreview.pause();
            }

            const badge = document.getElementById('modalBadge');
            badge.innerText = badgeText;
            badge.className = `inline-flex px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest mb-2 border ${badgeClass}`;

            const modal = document.getElementById('detailModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            // Subtle pop animation
            const modalBox = modal.querySelector('div.bg-white');
            modalBox.animate([{ opacity: 0, transform: 'scale(0.95)' }, { opacity: 1, transform: 'scale(1)' }], { duration: 200, fill: 'forwards', easing: 'ease-out' });
        }

        function closeDetailModal() {
            document.getElementById('detailModal').classList.add('hidden');
            document.getElementById('detailModal').classList.remove('flex');
        }

        function openVentModal(id) {
            const vent = window.ventsDataMap[id];
            if (!vent) return;
            const dateObj = parsePHDate(vent.created_at);
            const timeStr = dateObj.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
            const dateStr = dateObj.toLocaleDateString('en-PH', { weekday: 'long', month: 'short', day: 'numeric' });

            let senderInfo = vent.name || 'Anonymous';
            if (vent.section) senderInfo += `\nSection: ${vent.section}`;
            if (vent.adviser_name) senderInfo += `\nAdviser: ${vent.adviser_name}`;

            const badgeClass = vent.risk_level === 'CRITICAL' ? 'bg-[#E04B3F] text-white border-transparent' : (vent.risk_level === 'HIGH' ? 'bg-[#F59E0B] text-white border-transparent' : 'bg-[#235D3A]/10 text-[#235D3A] border-[#235D3A]/20');
            openDetailModal('Safe Space Vent Entry', senderInfo, `${dateStr} ${timeStr}`, vent.raw_text, `Threat Level: ${vent.risk_level}`, badgeClass);
        }

        function openIncidentModal(id) {
            const inc = window.incidentsDataMap[id];
            if (!inc) return;
            const reporter = inc.name || 'Anonymous';
            const perp = inc.perpetrator_name || 'Unknown';
            const dateObj = parsePHDate(inc.created_at);
            const dateStr = dateObj.toLocaleDateString('en-PH', { weekday: 'long', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            const isRes = inc.status === 'Resolved' || inc.status === 'resolved' || inc.status === 'verified';
            const badgeClass = isRes ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700 border-blue-200';

            openDetailModal('Bullying Incident Report', `Reporter: ${reporter}\nAccused: ${perp}`, dateStr, inc.description, isRes ? 'Resolved' : 'Action Required', badgeClass);

            if (inc.evidence_file) {
                const evidenceContainer = document.getElementById('modalEvidenceContainer');
                const evidenceLink = document.getElementById('modalEvidenceLink');
                const imgPreview = document.getElementById('evidenceImagePreview');
                const videoPreview = document.getElementById('evidenceVideoPreview');
                const evidenceName = inc.evidence_file.split('/').pop();
                const fileUrl = '<?= base_url('admin/evidence') ?>/' + encodeURIComponent(evidenceName);

                evidenceLink.href = fileUrl;
                evidenceContainer.classList.remove('hidden');

                imgPreview.classList.add('hidden');
                videoPreview.classList.add('hidden');
                videoPreview.pause();
                videoPreview.removeAttribute('src');
                imgPreview.removeAttribute('src');

                const ext = inc.evidence_file.split('.').pop().toLowerCase();
                if (['mp4', 'mov', 'webm'].includes(ext)) {
                    videoPreview.src = fileUrl;
                    videoPreview.classList.remove('hidden');
                } else {
                    imgPreview.src = fileUrl;
                    imgPreview.classList.remove('hidden');
                }
            }
        }

        function openAlertModal(index) {
            const alert = window.alertsDataMap[index];
            if (!alert) return;
            const dateObj = parsePHDate(alert.timestamp);
            const dateStr = dateObj.toLocaleDateString('en-PH', { weekday: 'long', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            const isSms = alert.type.includes('SMS');
            const badgeClass = isSms ? 'bg-blue-100 text-blue-700 border-blue-200' : 'bg-purple-100 text-purple-700 border-purple-200';
            const icon = isSms ? '📱' : '✉️';

            openDetailModal('Automated Alert Log', alert.recipient, dateStr, alert.message, `${icon} ${alert.type}`, badgeClass);
        }

        function toggleDeleteBtn(checkboxClass, btnSelector) {
            if ($(checkboxClass + ':checked').length > 0) {
                $(btnSelector).removeClass('hidden');
            } else {
                $(btnSelector).addClass('hidden');
            }
        }

        // Initialize First Fetch
        $(document).ready(function () {
            // Checkbox logic
            $(document).on('change', '#selectAllVents', function () {
                $('.vent-checkbox').prop('checked', $(this).prop('checked'));
                toggleDeleteBtn('.vent-checkbox', '.delete-btn-vents');
            });
            $(document).on('change', '.vent-checkbox', function () {
                toggleDeleteBtn('.vent-checkbox', '.delete-btn-vents');
                if ($('.vent-checkbox:checked').length !== $('.vent-checkbox').length) {
                    $('#selectAllVents').prop('checked', false);
                }
            });

            $(document).on('change', '#selectAllIncidents', function () {
                $('.incident-checkbox').prop('checked', $(this).prop('checked'));
                toggleDeleteBtn('.incident-checkbox', '.delete-btn-incidents');
            });
            $(document).on('change', '.incident-checkbox', function () {
                toggleDeleteBtn('.incident-checkbox', '.delete-btn-incidents');
                if ($('.incident-checkbox:checked').length !== $('.incident-checkbox').length) {
                    $('#selectAllIncidents').prop('checked', false);
                }
            });

            // Search Filters
            window.applyVentsFilter = function () {
                let searchValue = ($('#searchVents').val() || '').toLowerCase();
                let statusValue = $('#filterStatusVents').val();
                let dateValue = $('#filterDateVents').val();

                $('#ventsTableBody tr[data-status]').each(function () {
                    let matchesSearch = $(this).text().toLowerCase().indexOf(searchValue) > -1;
                    let matchesStatus = statusValue === 'all' || statusValue === 'archived' || $(this).attr('data-status') === statusValue;
                    let matchesDate = !dateValue || $(this).attr('data-date') === dateValue;

                    this.hidden = !(matchesSearch && matchesStatus && matchesDate);
                    this.style.removeProperty('display');
                });
                collapseRows('ventsTableBody', 5, 7, 'section-vents');
            };

            window.applyIncidentsFilter = function () {
                let searchValue = ($('#searchIncidents').val() || '').toLowerCase();
                let statusValue = $('#filterStatusIncidents').val();
                let dateValue = $('#filterDateIncidents').val();

                $('#incidentsTableBody tr[data-status]').each(function () {
                    let matchesSearch = $(this).text().toLowerCase().indexOf(searchValue) > -1;
                    let matchesStatus = statusValue === 'all' || statusValue === 'archived' || $(this).attr('data-status') === statusValue;
                    let matchesDate = !dateValue || $(this).attr('data-date') === dateValue;

                    this.hidden = !(matchesSearch && matchesStatus && matchesDate);
                    this.style.removeProperty('display');
                });
                collapseRows('incidentsTableBody', 5, 8, 'section-incidents');
            };

            // Restore filter state from localStorage if exists
            if (localStorage.getItem('ventsFilterStatus')) $('#filterStatusVents').val(localStorage.getItem('ventsFilterStatus'));
            if (localStorage.getItem('ventsFilterDate')) $('#filterDateVents').val(localStorage.getItem('ventsFilterDate'));
            if (localStorage.getItem('incidentsFilterStatus')) $('#filterStatusIncidents').val(localStorage.getItem('incidentsFilterStatus'));
            if (localStorage.getItem('incidentsFilterDate')) $('#filterDateIncidents').val(localStorage.getItem('incidentsFilterDate'));

            $('#searchVents').on('input', applyVentsFilter);
            $('#filterStatusVents').on('change', function () {
                let val = $(this).val();
                localStorage.setItem('ventsFilterStatus', val);

                if (val === 'archived') {
                    if (!showArchivedVents) toggleVentsView(true);
                    else applyVentsFilter();
                } else {
                    if (showArchivedVents) toggleVentsView(false);
                    else applyVentsFilter();
                }
            });
            $('#filterDateVents').on('change', function () {
                localStorage.setItem('ventsFilterDate', $(this).val());
                applyVentsFilter();
            });

            $('#searchIncidents').on('input', applyIncidentsFilter);
            $('#filterStatusIncidents').on('change', function () {
                let val = $(this).val();
                localStorage.setItem('incidentsFilterStatus', val);

                if (val === 'archived') {
                    if (!showArchivedIncidents) toggleIncidentsView(true);
                    else applyIncidentsFilter();
                } else {
                    if (showArchivedIncidents) toggleIncidentsView(false);
                    else applyIncidentsFilter();
                }
            });
            $('#filterDateIncidents').on('change', function () {
                localStorage.setItem('incidentsFilterDate', $(this).val());
                applyIncidentsFilter();
            });

            // Fetch immediately upon load for 0-lag experience
            // If localStorage has 'archived' filter, switch view before first fetch
            if (localStorage.getItem('ventsFilterStatus') === 'archived') {
                showArchivedVents = true;
            }
            if (localStorage.getItem('incidentsFilterStatus') === 'archived') {
                showArchivedIncidents = true;
            }
            fetchLiveVents();
            fetchLiveIncidents();
            fetchAlertLogs();

            // Refresh the current page automatically; no manual pause state.
            setInterval(fetchLiveVents, 30000);
            setInterval(fetchLiveIncidents, 30000);
            setInterval(fetchAlertLogs, 30000);


        });

        function submitBulkDeleteVents() {
            if (!confirm('Archive selected vents?')) return;
            const checkboxes = document.querySelectorAll('.vent-checkbox:checked');
            if (checkboxes.length === 0) return;
            const container = document.getElementById('bulkDeleteVentsInputs');
            container.innerHTML = '';
            checkboxes.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'vent_ids[]';
                input.value = cb.value;
                container.appendChild(input);
            });
            document.getElementById('bulkDeleteVentsForm').submit();
        }

        function submitBulkDeleteIncidents() {
            if (!confirm('Archive selected incident reports?')) return;
            const checkboxes = document.querySelectorAll('.incident-checkbox:checked');
            if (checkboxes.length === 0) return;
            const container = document.getElementById('bulkDeleteIncidentsInputs');
            container.innerHTML = '';
            checkboxes.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'incident_ids[]';
                input.value = cb.value;
                container.appendChild(input);
            });
            document.getElementById('bulkDeleteIncidentsForm').submit();
        }

        /* ── Meeting Request Bell & Sessions Section ────────────────── */
        (function () {
            const POLL_INTERVAL = 15000; // 15 s
            let dropdownOpen = false;
            let lastCount = <?= (int) ($pending_meetings_count ?? 0) ?>;

            window.closeMeetingDropdown = function () {
                dropdownOpen = false;
                const dropdown = document.getElementById('meetingDropdown');
                if (dropdown) dropdown.classList.add('hidden');
                document.getElementById('meetingBellBtn').setAttribute('aria-expanded', 'false');
            };

            function positionMeetingDropdown() {
                const dropdown = document.getElementById('meetingDropdown');
                dropdown.style.top = window.innerWidth < 768
                    ? (document.getElementById('mainNavBar').getBoundingClientRect().bottom + 8) + 'px' : '';
            }
            window.addEventListener('resize', positionMeetingDropdown);
            window.addEventListener('scroll', () => { if (dropdownOpen) positionMeetingDropdown(); }, { passive: true });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') window.closeMeetingDropdown();
            });

            // Toggle dropdown visibility
            window.toggleMeetingDropdown = function () {
                dropdownOpen = !dropdownOpen;
                const dropdown = document.getElementById('meetingDropdown');
                if (dropdown) dropdown.classList.toggle('hidden', !dropdownOpen);
                document.getElementById('meetingBellBtn').setAttribute('aria-expanded', String(dropdownOpen));
                positionMeetingDropdown();
                if (dropdownOpen) window.closeStaffNavigation?.();
                if (dropdownOpen) fetchMeetings();
            };

            // Close when clicking outside
            document.addEventListener('click', function (e) {
                const wrapper = document.getElementById('meetingNotifWrapper');
                const dropdown = document.getElementById('meetingDropdown');
                if (wrapper && !wrapper.contains(e.target) && dropdownOpen) {
                    window.closeMeetingDropdown();
                }
            });

            function timeAgo(dateStr) {
                if (!dateStr) return '';
                const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
                if (diff < 60) return diff + 's ago';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            }

            function renderDropdownList(requests) {
                const list = document.getElementById('meetingRequestsList');
                const countLabel = document.getElementById('dropdownCount');
                if (countLabel) countLabel.textContent = requests.length ? requests.length + ' pending' : 'You’re all caught up';
                if (!list) return;
                if (!requests.length) {
                    list.innerHTML = '<div class="meeting-empty" id="noRequestsMsg"><span class="meeting-empty-icon" aria-hidden="true">✓</span><p>No pending requests</p></div>';
                    return;
                }
                list.innerHTML = requests.map(function (r) {
                    const name = r.student_name || 'Unknown Student';
                    const section = r.section ? ' · ' + r.section : '';
                    const sid = r.student_number ? ' (' + r.student_number + ')' : '';
                    const ago = timeAgo(r.requested_at);
                    const link = '<?= base_url('admin/case-summary/') ?>' + r.student_id;
                    return `<a href="${link}" class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50 transition-colors group">
                        <span class="mt-0.5 w-8 h-8 rounded-full bg-gray-100 border border-gray-200 flex items-center justify-center flex-shrink-0 text-primary font-bold text-xs">
                            ${escapeHtml(name.charAt(0))}
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate group-hover:text-primary transition-colors">${escapeHtml(name)}${escapeHtml(sid)}</p>
                            <p class="text-[11px] text-gray-600">${escapeHtml(section.replace(' · ', ''))}${section ? '' : '&nbsp;'} <span class="ml-1 text-gray-500">${escapeHtml(ago)}</span></p>
                        </div>
                        <span class="text-[10px] text-primary font-semibold self-center transition-opacity flex-shrink-0">View →</span>
                    </a>`;
                }).join('');
            }

            function renderSectionCards(requests) {
                const sectionList = document.getElementById('dashboardSessionList');
                const emptySection = document.getElementById('dashboardSessionEmpty');
                const sectionBadge = document.getElementById('sessionSectionCountBadge');

                if (sectionBadge) {
                    sectionBadge.textContent = requests.length + ' Pending';
                }

                if (!sectionList || !emptySection) return;

                if (!requests.length) {
                    emptySection.classList.remove('hidden');
                    sectionList.classList.add('hidden');
                    sectionList.innerHTML = '';
                    return;
                }

                emptySection.classList.add('hidden');
                sectionList.classList.remove('hidden');
                sectionList.innerHTML = requests.map(function (r) {
                    const name = r.student_name || 'Unknown Student';
                    const section = r.section ? 'Section ' + r.section : 'No Section';
                    const sid = r.student_number ? ' · ID ' + r.student_number : '';
                    const ago = timeAgo(r.requested_at);
                    const link = '<?= base_url('admin/case-summary/') ?>' + r.student_id;

                    return `<div class="bg-white rounded-xl border border-emerald-200/90 p-4 sm:p-5 shadow-sm hover:shadow-md transition-all flex flex-col justify-between">
                        <div>
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-full bg-emerald-100 border border-emerald-200 flex items-center justify-center flex-shrink-0 text-emerald-800 font-bold text-sm">
                                    ${escapeHtml(name.charAt(0))}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h4 class="text-sm font-bold text-gray-900 truncate">${escapeHtml(name)}</h4>
                                    <p class="text-xs text-gray-500 truncate mt-0.5">${escapeHtml(section)}${escapeHtml(sid)}</p>
                                    <div class="mt-2 flex items-center gap-1.5 flex-wrap">
                                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                            ⏱ Requested ${escapeHtml(ago)}
                                        </span>
                                        <span class="inline-flex items-center text-[11px] font-medium text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full border border-blue-200">
                                            Virtual 1-on-1
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between">
                            <span class="text-xs text-gray-400">Counseling Request</span>
                            <a href="${link}" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-[#235D3A] hover:bg-[#19452B] text-white text-xs font-semibold shadow-sm transition-colors">
                                View Case & Connect →
                            </a>
                        </div>
                    </div>`;
                }).join('');
            }

            function updateBadge(count) {
                const badge = document.getElementById('meetingBadge');
                const statSessions = document.getElementById('stat-sessions');
                const navBadge = document.getElementById('navSessionsBadge');

                if (badge) {
                    badge.textContent = count;
                    if (count > 0) {
                        badge.classList.remove('hidden');
                        if (count > lastCount) {
                            const btn = document.getElementById('meetingBellBtn');
                            if (btn) {
                                btn.classList.add('ring-2', 'ring-green-400/60');
                                setTimeout(() => btn.classList.remove('ring-2', 'ring-green-400/60'), 2000);
                            }
                        }
                    } else {
                        badge.classList.add('hidden');
                    }
                }

                if (statSessions) {
                    statSessions.textContent = count;
                }

                if (navBadge) {
                    navBadge.textContent = count;
                    if (count > 0) {
                        navBadge.classList.remove('hidden');
                    } else {
                        navBadge.classList.add('hidden');
                    }
                }

                lastCount = count;
            }

            function fetchMeetings() {
                fetch('<?= base_url('admin/api/pending-meetings') ?>', { credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.status !== 'success') return;
                        updateBadge(data.count);
                        renderDropdownList(data.requests || []);
                        renderSectionCards(data.requests || []);
                        const label = document.getElementById('lastCheckedLabel');
                        if (label) label.textContent = 'Updated ' + timeAgo(new Date().toISOString());
                    })
                    .catch(function () { /* silent */ });
            }

            // Run immediately, then on interval
            fetchMeetings();
            setInterval(fetchMeetings, POLL_INTERVAL);
        })();
    </script>

    <!-- Hidden Bulk Forms -->
    <form action="<?= base_url('admin/vent/delete-multiple') ?>" method="POST" id="bulkDeleteVentsForm" class="hidden">
        <?= csrf_field() ?>
        <div id="bulkDeleteVentsInputs"></div>
    </form>
    <form action="<?= base_url('admin/incident/delete-multiple') ?>" method="POST" id="bulkDeleteIncidentsForm"
        class="hidden">
        <?= csrf_field() ?>
        <div id="bulkDeleteIncidentsInputs"></div>
    </form>
    <?= view('reports/modals/student_profile') ?>
    <?= view('partials/session_idle_modal') ?>

    <!-- ── Floating Scroll-to-Top Button ── -->
    <button id="scrollTopBtn" title="Back to top" aria-label="Scroll back to top" style="
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
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="18 15 12 9 6 15" />
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

        // Escape key closes detail modal
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            const dm = document.getElementById('detailModal');
            if (dm && !dm.classList.contains('hidden')) closeDetailModal();
        });
    </script>


</body>

</html>
