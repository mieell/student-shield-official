<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>Case Summary Report - StudentShield</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?= view('partials/school_brand', ['studentPalette' => true]) ?>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 
                        sans: ['Inter', 'sans-serif'], 
                        heading: ['Nunito', 'sans-serif']
                    },
                    colors: {
                        white: '#FBFDFB', 
                        black: '#131A15', 
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
                            900: '#212825',
                        },
                        red: {
                            50: '#FDF7F7',
                            100: '#FAEDED',
                            200: '#F4D6D6',
                            500: '#D95A5A',
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
        body {
            background-color: #f8faf6;
            background-image: radial-gradient(circle at 25px 25px, rgba(22, 101, 52, 0.02) 2px, transparent 0);
            background-size: 50px 50px;
        }
        .dashboard-card {
            background: #ffffff;
            border: 1px solid #e5ebe5;
            border-radius: 14px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        }
        .dashboard-card:hover {
            box-shadow: 0 8px 30px rgba(0,0,0,0.06);
            border-color: #cddacd;
        }
        @media print {
            body {
                background: white !important;
                background-image: none !important;
                color: black !important;
                font-size: 11px !important;
            }
            .no-print {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            .print-break {
                page-break-before: always;
            }
            /* Force Grids in Print to prevent vertical stacking */
            .grid {
                display: grid !important;
            }
            .md\:grid-cols-2 {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            .md\:grid-cols-4 {
                grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            }

            .dashboard-card {
                box-shadow: none !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 8px !important;
                margin-bottom: 15px !important;
                page-break-inside: avoid;
            }
            .print-container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            /* Premium Ink-saving light colors for Student Banner under print */
            .bg-gradient-to-r {
                background: #f8fafc !important;
                background-image: none !important;
                color: #0f172a !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 12px !important;
                padding: 16px !important;
            }
            .bg-gradient-to-r * {
                color: #0f172a !important;
            }
            .bg-gradient-to-r span, .bg-gradient-to-r strong, .bg-gradient-to-r h2 {
                color: #0f172a !important;
            }
            .bg-white\/10 {
                background: #f8fafc !important;
                border: 1px solid #cbd5e1 !important;
                color: #0f172a !important;
                box-shadow: none !important;
            }
            .bg-white\/10 p {
                color: #0f172a !important;
            }
            /* Prevent weird half-page cuts */
            h2, h3, h4, tr, .dashboard-card {
                page-break-inside: avoid;
            }
            /* Hide skeleton loader in print */
            #skeleton-loader {
                display: none !important;
            }
            /* Hide CodeIgniter Debug Toolbar completely in print */
            #toolbarContainer,
            #debug-bar,
            #debug-icon,
            #debug-bar-container,
            .debug-bar,
            .debug-bar-container,
            [id^="debug-"] {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                overflow: hidden !important;
            }
        }
    </style>
<?= view('partials/ui_polish') ?>
</head>
<body class="school-staff-page font-sans antialiased text-gray-900 leading-normal">

    <!-- Header Navigation (Non-print) -->
    <header class="glass-header no-print bg-primary border-b-4 border-[#EAB308] text-white py-3 px-4 sm:px-6 md:px-8 flex flex-wrap justify-between items-center gap-2 sm:gap-4 shadow-md">
        <div class="flex items-center gap-2 sm:gap-4 min-w-0 flex-1">
            <a href="/admin/dashboard" class="text-white hover:text-[#EAB308] transition-colors flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <div class="flex items-center gap-3">
                <img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM Logo" class="h-9 w-9 object-contain">
                <div>
                    <h1 class="font-heading font-extrabold text-xs sm:text-sm tracking-wider uppercase leading-tight">St. Vincent Blessed School of Manila</h1>
                    <p class="text-[9px] text-[#EAB308] font-bold uppercase tracking-widest mt-0.5">StudentShield Portal</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3 shrink-0">
            <button onclick="window.print()" class="bg-[#EAB308] hover:bg-[#D9AA2E] text-slate-900 font-bold px-3 sm:px-4 py-2 min-h-[40px] rounded-xl text-xs transition-all flex items-center gap-1.5 sm:gap-2 shadow cursor-pointer whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                <span class="hidden sm:inline">Print / Export PDF</span><span class="sm:hidden">Print</span>
            </button>
            <form action="<?= base_url('logout') ?>" method="POST" class="inline">
                <?= csrf_field() ?>
                <button type="submit" class="flex items-center gap-1.5 px-3 py-2 min-h-[40px] bg-red-500/20 hover:bg-red-500/30 text-red-200 hover:text-white rounded-xl border border-red-500/30 text-xs font-semibold transition-all cursor-pointer shadow whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Sign Out
                </button>
            </form>
        </div>
    </header>

    <!-- Print-only official header -->
    <div class="hidden print:flex items-center justify-between border-b-2 border-emerald-800 pb-3 mb-6">
        <div class="flex items-center gap-4">
            <img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM Logo" class="h-14 w-14 object-contain">
            <div class="text-left">
                <h1 class="text-base font-bold font-heading uppercase text-emerald-950 tracking-wider">St. Vincent Blessed School of Manila</h1>
                <p class="text-[10px] text-emerald-800 font-bold font-heading tracking-wider mt-0.5">STUDENTSHIELD CASE MANAGEMENT PORTAL</p>
                <p class="text-[8px] text-gray-500 font-mono mt-0.5">CONFIDENTIAL STUDENT WELLNESS & COUNSELING DOSSIER</p>
            </div>
        </div>
        <div class="text-right">
            <span class="text-[8px] font-bold text-red-600 border border-red-200 bg-red-50 px-2 py-0.5 rounded font-mono uppercase tracking-widest">CONFIDENTIAL</span>
            <p class="text-[8px] text-gray-400 font-mono mt-1">Printed: <span class="print-date"></span></p>
        </div>
    </div>

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 py-8 md:px-8 print-container">

        <!-- Skeleton Loader -->
        <div id="skeleton-loader" class="space-y-8">
            <div class="h-24 bg-white rounded-2xl border border-gray-200 animate-pulse flex items-center p-6 gap-4">
                <div class="w-12 h-12 bg-gray-200 rounded-full"></div>
                <div class="space-y-2 flex-1">
                    <div class="h-5 bg-gray-200 rounded w-1/4"></div>
                    <div class="h-4 bg-gray-200 rounded w-1/3"></div>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="h-24 bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
                <div class="h-24 bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
                <div class="h-24 bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
                <div class="h-24 bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="h-80 bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
                <div class="h-80 bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
            </div>
        </div>

        <!-- Real Content Container (hidden initially) -->
        <div id="report-content" class="hidden space-y-8">

            <!-- Student Profile Summary Banner -->
            <div class="school-case-banner bg-gradient-to-r from-primary to-primary-dark text-white p-6 md:p-8 rounded-2xl shadow-lg flex flex-col md:flex-row justify-between items-start md:items-center gap-6 border-b-4 border-[#EAB308]">
                <div class="flex gap-4 items-center">
                    <div class="h-16 w-16 bg-white/10 rounded-full flex items-center justify-center border-2 border-white/20 text-2xl font-bold font-heading shadow-inner">
                        <span id="stInitials">ST</span>
                    </div>
                    <div>
                        <h2 class="text-2xl md:text-3xl font-heading font-bold flex items-center gap-3">
                            <span id="stName">Name</span>
                            <span id="riskBadge" class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">LOW RISK</span>
                        </h2>
                        <div class="flex flex-wrap gap-x-6 gap-y-2 mt-2 text-white/80 text-sm font-medium">
                            <span class="flex items-center gap-1.5">ID: <strong id="stId" class="text-white"></strong></span>
                            <span class="flex items-center gap-1.5">Grade & Section: <strong id="stSection" class="text-white"></strong></span>
                            <span class="flex items-center gap-1.5">Adviser: <strong id="stAdviser" class="text-white"></strong></span>
                        </div>
                    </div>
                </div>

                <!-- Case Status Tracker Banner -->
                <div class="bg-white/10 backdrop-blur border border-white/15 p-4 rounded-xl flex items-center gap-4 self-stretch md:self-auto">
                    <div class="flex flex-col gap-2">
                        <a href="<?= base_url('admin/incidents') ?>" class="no-print bg-white text-emerald-900 font-bold px-3 py-2 rounded-lg text-xs">Review incident reports</a>
                        <!-- Student Pending Request Badge -->
                        <div id="pendingRequestBadge" class="hidden no-print bg-yellow-400/20 border border-yellow-400/50 text-yellow-300 text-[11px] font-bold px-3 py-1.5 rounded-lg flex items-center gap-1.5 animate-pulse">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                            Student requested a session
                        </div>
                        <button id="btnStartVideoMeeting" onclick="startVideoMeeting()" class="no-print bg-[#EAB308] hover:bg-[#D9AA2E] text-slate-900 font-bold px-3 py-1.5 rounded-lg text-xs transition shadow-sm flex items-center justify-center gap-1">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            Set up Google Meet
                        </button>
                        <button id="btnJoinGoogleMeeting" onclick="joinGoogleMeeting()" class="hidden no-print bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-3 py-1.5 rounded-lg text-xs transition shadow-sm flex items-center justify-center gap-1">
                            Join Google Meet
                        </button>
                        <button id="btnEndVideoMeeting" onclick="endVideoMeeting()" class="hidden no-print bg-red-600 hover:bg-red-700 text-white font-bold px-3 py-1.5 rounded-lg text-xs transition shadow-sm flex items-center justify-center gap-1">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            Close session access
                        </button>
                    </div>
                </div>
            </div>

            <!-- Parent Contacts mini-info block -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-white p-4 rounded-xl border border-gray-200/60 shadow-sm flex items-center gap-3">
                    <div class="p-2.5 bg-emerald-50 rounded-lg text-emerald-700">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Parent/Guardian Email</p>
                        <p id="stParentEmail" class="text-sm font-semibold text-gray-700">Loading...</p>
                    </div>
                </div>
                <div class="bg-white p-4 rounded-xl border border-gray-200/60 shadow-sm flex items-center gap-3">
                    <div class="p-2.5 bg-emerald-50 rounded-lg text-emerald-700">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Parent/Guardian Phone</p>
                        <p id="stParentPhone" class="text-sm font-semibold text-gray-700">Loading...</p>
                    </div>
                </div>
            </div>

            <!-- Stats Overview Row -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="dashboard-card p-5 relative overflow-hidden">
                    <p class="text-xs text-gray-400 font-bold uppercase tracking-wider">Total Safe Space Vents</p>
                    <p id="statTotalVents" class="text-3xl font-heading font-extrabold text-emerald-950 mt-1">0</p>
                    <span class="absolute right-4 bottom-4 text-emerald-100 opacity-80">
                        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                    </span>
                </div>
                <div class="dashboard-card p-5 relative overflow-hidden">
                    <p class="text-xs text-gray-400 font-bold uppercase tracking-wider">Bullying Reports</p>
                    <p id="statBullyReports" class="text-3xl font-heading font-extrabold text-emerald-950 mt-1">0</p>
                    <span class="absolute right-4 bottom-4 text-emerald-100 opacity-80">
                        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                    </span>
                </div>
                <div class="dashboard-card p-5 relative overflow-hidden">
                    <p class="text-xs text-gray-400 font-bold uppercase tracking-wider">Alert Notifications Sent</p>
                    <p id="statNotifications" class="text-3xl font-heading font-extrabold text-emerald-950 mt-1">0</p>
                    <span class="absolute right-4 bottom-4 text-emerald-100 opacity-80">
                        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                    </span>
                </div>
                <div class="dashboard-card p-5 relative overflow-hidden">
                    <p class="text-xs text-gray-400 font-bold uppercase tracking-wider">Highest Risk Level</p>
                    <p id="statHighestRisk" class="text-3xl font-heading font-extrabold text-red-500 mt-1">LOW</p>
                    <span id="statHighestRiskDate" class="text-[10px] text-gray-400 block mt-1">No recorded risk</span>
                </div>
            </div>

            <!-- Charts: Wellness Trend and Emotion Distribution -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 print-break">
                <!-- Trend Chart -->
                <div class="dashboard-card p-6 bg-white relative overflow-hidden">
                    <h3 class="text-lg font-bold font-heading text-emerald-950 mb-1 flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                        </svg>
                        Student Wellness Trend
                    </h3>
                    <p class="text-xs text-gray-400 font-medium mb-4">Stepped risk mapping over time</p>
                    <div id="mainRiskChart" style="width: 100%; min-height: 280px; height: 280px;"></div>
                </div>

                <!-- Emotion Distribution -->
                <div class="dashboard-card p-6 bg-white relative overflow-hidden">
                    <h3 class="text-lg font-bold font-heading text-emerald-950 mb-1 flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Emotion Analysis Distribution
                    </h3>
                    <p class="text-xs text-gray-400 font-medium mb-4">Breakdown of emotions flagged from student entries</p>
                    <div id="emotionsChart" style="width: 100%; min-height: 280px; height: 280px;"></div>
                </div>
            </div>

            <!-- Bullying Incidents Table -->
            <div class="dashboard-card p-6 bg-white print-break">
                <h3 class="text-lg font-bold font-heading text-emerald-950 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    Bullying & Harassment Incident Record
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-400 uppercase tracking-wider text-[10px] font-bold">
                                <th class="pb-3">Case ID</th>
                                <th class="pb-3">Role</th>
                                <th class="pb-3">Incident Details</th>
                                <th class="pb-3">Verification Status</th>
                                <th class="pb-3">Date Reported</th>
                            </tr>
                        </thead>
                        <tbody id="incidentsTableBody" class="divide-y divide-gray-100 text-gray-700">
                            <!-- JS populated -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Combined Alerts & Notification Logs -->
            <div class="dashboard-card p-6 bg-white">
                <h3 class="text-lg font-bold font-heading text-emerald-950 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    Crisis Notification Logs (SMS & Email alerts)
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-400 uppercase tracking-wider text-[10px] font-bold">
                                <th class="pb-3">Alert Type</th>
                                <th class="pb-3">Channel</th>
                                <th class="pb-3">Recipient</th>
                                <th class="pb-3">Delivery Status</th>
                                <th class="pb-3">Timestamp</th>
                            </tr>
                        </thead>
                        <tbody id="notificationsTableBody" class="divide-y divide-gray-100 text-gray-700">
                            <!-- JS populated -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Case Timeline & Logs -->
            <div class="dashboard-card p-6 bg-white print-break">
                <h3 class="text-lg font-bold font-heading text-emerald-950 mb-6 flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Chronological Case Event Feed
                </h3>
                <div id="chronTimeline" class="space-y-6 relative border-l-2 border-emerald-50 ml-4 pl-6">
                    <!-- Timeline items go here -->
                </div>
            </div>

            <!-- Counselor Notes Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 print-break">
                <!-- Notes Feed -->
                <div class="dashboard-card p-6 bg-white lg:col-span-2">
                    <h3 class="text-lg font-bold font-heading text-emerald-950 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Clinical Counselor Notes
                    </h3>
                    <div id="notesContainer" class="space-y-4 max-h-[480px] overflow-y-auto pr-2">
                        <!-- JS notes list -->
                    </div>
                </div>

                <!-- Add Note Form (hidden in print) -->
                <div class="dashboard-card p-6 bg-white no-print self-start">
                    <h3 class="text-lg font-bold font-heading text-emerald-950 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Counselor notes
                    </h3>
                    <form id="noteForm" onsubmit="submitNote(event)" class="space-y-4">
                        <div>
                            <label class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Note Type</label>
                            <select id="noteType" class="mt-1 w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-emerald-700/30 focus:border-emerald-700 outline-none">
                                <option value="general">General note</option>
                                <option value="observation">Observation</option>
                                <option value="intervention">Action taken</option>
                                <option value="recommendation">Recommendation</option>
                                <option value="follow_up">Follow-up</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Note Content</label>
                            <textarea id="noteContent" rows="5" placeholder="Enter clinical assessment, guidance note, or meeting summary..." required class="mt-1 w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm focus:ring-1 focus:ring-emerald-700 outline-none resize-none"></textarea>
                        </div>
                        <button type="submit" class="w-full bg-primary hover:bg-primary-darker text-white font-bold py-2.5 rounded-xl transition shadow-md">
                            Save note
                        </button>
                    </form>
                </div>
            </div>

            <!-- Case status changes history table -->
            <div class="dashboard-card p-6 bg-white">
                <h3 class="text-lg font-bold font-heading text-emerald-950 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                    </svg>
                    Previous case status history (read-only)
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-400 uppercase tracking-wider text-[10px] font-bold">
                                <th class="pb-3">Assigned Status</th>
                                <th class="pb-3">Counselor Remarks</th>
                                <th class="pb-3">Updated By</th>
                                <th class="pb-3">Date Changed</th>
                            </tr>
                        </thead>
                        <tbody id="statusHistoryTableBody" class="divide-y divide-gray-100 text-gray-700">
                            <!-- JS populated -->
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>

    <!-- Print Only Confidential Footer -->
    <div class="hidden print:block fixed bottom-0 left-0 right-0 text-center text-[10px] text-gray-400 font-mono border-t border-gray-200 pt-2">
        StudentShield Wellness Report • Prepared on <span class="print-date"></span> • STRICTLY CONFIDENTIAL
    </div>

    <!-- Google Meet publication dialog -->
    <div id="googleMeetModal" class="no-print fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl w-full max-w-xl shadow-2xl max-h-[90vh] overflow-y-auto" role="dialog" aria-modal="true" aria-label="Publish Google Meet">
            <div class="bg-gray-900 text-white p-4 flex justify-between items-center">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <svg class="w-5 h-5 text-[#EAB308]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    Open a Google Meet session
                </h3>
                <button type="button" onclick="hideGoogleMeetDialog()" class="text-gray-400 hover:text-white transition" aria-label="Close">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <form id="googleMeetForm" class="p-5 space-y-4" onsubmit="publishGoogleMeet(event)">
                <p class="text-sm text-gray-600">Create a <a href="https://meet.google.com/" target="_blank" rel="noopener noreferrer" class="font-semibold text-emerald-700 underline">new Google Meet</a> using your school Workspace account. Invite only the intended student, select Restricted access, disable requests to join from uninvited people, and enable Host must join. If these controls are unavailable, do not publish the meeting.</p>
                <label class="block text-sm font-semibold text-gray-800" for="googleMeetLink">Google Meet link</label>
                <input id="googleMeetLink" name="meet_link" required pattern="https://meet\.google\.com/[a-z]{3}-[a-z]{4}-[a-z]{3}" placeholder="https://meet.google.com/abc-defg-hij" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input id="googleMeetConfirmed" name="host_confirmed" type="checkbox" value="1" required class="mt-1">
                    <span>I am the meeting host and have verified these settings in Google Meet. This is a new link for this student's session.</span>
                </label>
                <p class="text-xs text-gray-600">StudentShield access lasts two hours from publication. Closing access here does not end the Google call; use End meeting for all in Google Meet too.</p>
                <p id="googleMeetError" class="hidden text-sm text-red-700" role="alert"></p>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="hideGoogleMeetDialog()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">Cancel</button>
                    <button id="publishGoogleMeetButton" type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-darker">Publish for this student</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripting & AJAX Orchestration -->
    <script>
        const studentId = <?= $studentId ?>;
        const apiBaseUrl = "/admin/api/case-summary";
        const formBaseUrl = "/admin/case-summary";
        let wellnessChart = null;
        let emotionChart = null;

        // Set date in print footer
        document.querySelectorAll('.print-date').forEach(el => {
            el.innerText = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        });

        // Check if student has a pending session request
        let currentHostJoinUrl = null;
        function checkPendingRequest() {
            fetch(`/admin/api/case-summary/${studentId}/meeting/pending`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') throw new Error(data.message || 'Meeting status unavailable');
                currentHostJoinUrl = data.owned ? data.join_url : null;
                const badge = document.getElementById('pendingRequestBadge');
                const joinButton = document.getElementById('btnJoinGoogleMeeting');
                const startButton = document.getElementById('btnStartVideoMeeting');
                const endButton = document.getElementById('btnEndVideoMeeting');
                if (data.pending && badge) {
                    badge.classList.remove('hidden');
                    badge.classList.add('flex');
                } else if (badge) {
                    badge.classList.add('hidden');
                    badge.classList.remove('flex');
                }
                if (data.has_meeting && data.owned) {
                    startButton?.classList.add('hidden');
                    joinButton?.classList.remove('hidden');
                    joinButton?.classList.add('flex');
                    endButton?.classList.remove('hidden');
                    endButton?.classList.add('flex');
                } else {
                    startButton.disabled = !!data.has_meeting;
                    startButton.title = data.has_meeting ? 'Another host has an active session for this student.' : '';
                    startButton?.classList.remove('hidden');
                    joinButton?.classList.add('hidden');
                    joinButton?.classList.remove('flex');
                    endButton?.classList.add('hidden');
                    endButton?.classList.remove('flex');
                }
            })
            .catch(() => {});
        }
        checkPendingRequest();
        setInterval(checkPendingRequest, 15000);

        // Load Page Data
        function loadCaseData() {
            fetch(`${apiBaseUrl}/${studentId}`)
                .then(res => {
                    if (!res.ok) throw new Error("HTTP error " + res.status);
                    return res.json();
                })
                .then(data => {
                    document.getElementById('skeleton-loader').classList.add('hidden');
                    document.getElementById('report-content').classList.remove('hidden');

                    const u = data.student;
                    
                    // Profile info
                    document.getElementById('stName').innerText = u.name;
                    document.getElementById('stId').innerText = u.student_id || 'N/A';
                    document.getElementById('stSection').innerText = `${u.grade_level || 'Grade N/A'} - ${u.section || 'Unassigned'}`;
                    document.getElementById('stAdviser').innerText = u.adviser_name || 'None Assigned';
                    document.getElementById('stParentEmail').innerText = u.parent_email || 'N/A';
                    document.getElementById('stParentPhone').innerText = u.parent_phone || 'N/A';

                    // Initials
                    const names = u.name.split(' ');
                    let initials = names[0].charAt(0);
                    if (names.length > 1) initials += names[names.length - 1].charAt(0);
                    document.getElementById('stInitials').innerText = initials.toUpperCase();

                    // Risk Badge
                    const risk = data.currentRisk || 'LOW';
                    const riskBadge = document.getElementById('riskBadge');
                    riskBadge.innerText = `${risk} RISK`;
                    riskBadge.className = `px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider ${
                        risk === 'CRITICAL' ? 'bg-red-500 text-white animate-pulse' :
                        risk === 'HIGH' ? 'bg-orange-500 text-white' :
                        risk === 'MEDIUM' ? 'bg-yellow-500 text-slate-900' : 'bg-emerald-600 text-white'
                    }`;

                    // Summary Stats
                    document.getElementById('statTotalVents').innerText = data.totalVents;
                    document.getElementById('statBullyReports').innerText = data.bullyStats.total;
                    document.getElementById('statNotifications').innerText = data.totalNotifications;
                    document.getElementById('statHighestRisk').innerText = data.highestRisk;
                    document.getElementById('statHighestRisk').className = `text-3xl font-heading font-extrabold mt-1 ${
                        data.highestRisk === 'CRITICAL' ? 'text-red-600' :
                        data.highestRisk === 'HIGH' ? 'text-orange-500' :
                        data.highestRisk === 'MEDIUM' ? 'text-yellow-600' : 'text-emerald-700'
                    }`;
                    if (data.highestRiskDate) {
                        const dateFormatted = new Date(data.highestRiskDate).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
                        document.getElementById('statHighestRiskDate').innerText = `Flagged on ${dateFormatted}`;
                    } else {
                        document.getElementById('statHighestRiskDate').innerText = `No recorded risk`;
                    }

                    // Render Charts
                    renderWellnessTrend(data.riskTimeline);
                    renderEmotions(data.emotionCounts);

                    // Render Tables & Timeline
                    renderIncidentsTable(data.bullyReports);
                    renderNotificationsTable(data.smsLogs, data.emailLogs);
                    renderTimeline(data.timeline);
                    renderNotes(data.notes);
                    renderStatusHistoryTable(data.statusHistory);
                })
                .catch(err => {
                    console.error("Failed to load case data:", err);
                    alert("Failed to load case data. Please try again.");
                });
        }

        // Stepped Area Wellness Chart
        function renderWellnessTrend(timelineData) {
            if (wellnessChart) wellnessChart.destroy();

            const chartEl = document.getElementById('mainRiskChart');
            if (!timelineData || timelineData.length === 0) {
                chartEl.innerHTML = '<div style="height:280px;" class="w-full flex items-center justify-center text-gray-400 text-sm font-medium">No Safe Space entries yet.</div>';
                return;
            }

            chartEl.innerHTML = '';

            const options = {
                series: [{ name: 'Wellness Status', data: timelineData }],
                chart: {
                    type: 'area',
                    height: 280,
                    fontFamily: 'Inter, sans-serif',
                    toolbar: { show: false },
                    zoom: { enabled: false }
                },
                colors: ['#096328'],
                fill: {
                    type: 'gradient',
                    gradient: { shadeIntensity: 1, opacityFrom: 0.25, opacityTo: 0.02, stops: [0, 100] }
                },
                markers: {
                    size: 6,
                    colors: ['#fff'],
                    strokeColors: '#096328',
                    strokeWidth: 3,
                    hover: { size: 8 }
                },
                dataLabels: { enabled: false },
                stroke: { curve: 'stepline', width: 3 },
                xaxis: {
                    type: 'datetime',
                    labels: {
                        style: { colors: '#9ca3af', fontSize: '11px' },
                        datetimeUTC: false
                    }
                },
                yaxis: {
                    min: 15,
                    max: 110,
                    tickAmount: 4,
                    labels: {
                        style: { colors: '#6b7280', fontSize: '11px', fontWeight: 600 },
                        formatter: function(val) {
                            if (Math.abs(val - 100) < 5) return 'CRITICAL';
                            if (Math.abs(val - 75) < 5) return 'HIGH';
                            if (Math.abs(val - 50) < 5) return 'MEDIUM';
                            if (Math.abs(val - 25) < 5) return 'LOW';
                            return '';
                        }
                    }
                },
                grid: { borderColor: '#f0f0f0' },
                tooltip: {
                    x: { format: 'MMM dd, yyyy' },
                    y: {
                        formatter: function(val) {
                            if (val >= 100) return 'Critical Risk';
                            if (val >= 75) return 'High Risk';
                            if (val >= 50) return 'Medium Risk';
                            return 'Low Risk';
                        }
                    }
                }
            };

            wellnessChart = new ApexCharts(chartEl, options);
            wellnessChart.render();
        }

        // Emotions Bar Chart
        function renderEmotions(emotionCounts) {
            if (emotionChart) emotionChart.destroy();

            const chartEl = document.getElementById('emotionsChart');
            const categories = Object.keys(emotionCounts);
            const seriesData = Object.values(emotionCounts);

            if (categories.length === 0) {
                chartEl.innerHTML = '<div style="height:280px;" class="w-full flex items-center justify-center text-gray-400 text-sm font-medium">No emotion analysis metrics recorded.</div>';
                return;
            }

            chartEl.innerHTML = '';

            const options = {
                series: [{ name: 'Count', data: seriesData }],
                chart: {
                    type: 'bar',
                    height: 280,
                    fontFamily: 'Inter, sans-serif',
                    toolbar: { show: false }
                },
                colors: ['#D9AA2E'],
                plotOptions: {
                    bar: {
                        borderRadius: 6,
                        horizontal: true,
                        barHeight: '60%'
                    }
                },
                dataLabels: { enabled: false },
                xaxis: {
                    categories: categories,
                    labels: { style: { colors: '#9ca3af' } }
                },
                yaxis: {
                    labels: { style: { colors: '#4b5563', fontWeight: 500 } }
                },
                grid: { borderColor: '#f0f0f0' }
            };

            emotionChart = new ApexCharts(chartEl, options);
            emotionChart.render();
        }

        // Render Bullying Incidents Table
        function renderIncidentsTable(reports) {
            const tbody = document.getElementById('incidentsTableBody');
            tbody.innerHTML = '';

            if (!reports || reports.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-gray-400 text-xs">No incident involvement recorded.</td></tr>';
                return;
            }

            reports.forEach(r => {
                let role = 'Involved';
                let roleBadge = 'bg-gray-100 text-gray-800';
                if (r.reporter_student_id == studentId) {
                    role = 'Reporter';
                    roleBadge = 'bg-blue-50 text-blue-700 border border-blue-200';
                } else if (r.victim_student_id == studentId) {
                    role = 'Victim';
                    roleBadge = 'bg-red-50 text-red-700 border border-red-200';
                } else if (r.bully_student_id == studentId) {
                    role = 'Accused Bully';
                    roleBadge = 'bg-orange-50 text-orange-700 border border-orange-200';
                }

                const dateStr = new Date(r.created_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
                
                let statusBadge = '';
                let reviewerInfo = r.reviewed_by_name ? `<span class="text-[9px] text-gray-500 font-normal leading-tight mt-0.5">by ${r.reviewed_by_name}</span>` : '';

                if (r.status === 'verified') {
                    statusBadge = `<span class="px-2.5 py-1 rounded-md bg-green-100 text-green-800 font-bold border border-green-200 flex flex-col w-max"><div class="flex items-center"><svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>Verified True</div>${reviewerInfo}</span>`;
                } else if (r.status === 'rejected_fake') {
                    statusBadge = `<span class="px-2.5 py-1 rounded-md bg-red-100 text-red-800 font-bold border border-red-200 flex flex-col w-max"><div class="flex items-center"><svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>False Report</div>${reviewerInfo}</span>`;
                } else {
                    statusBadge = `<span class="px-2.5 py-1 rounded-md bg-yellow-100 text-yellow-800 font-bold border border-yellow-200 flex items-center w-max"><svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>Pending Review</span>`;
                }

                tbody.innerHTML += `
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="py-3.5 font-semibold text-emerald-950">#${r.id}</td>
                        <td class="py-3.5"><span class="px-2.5 py-0.5 rounded-full text-xs font-medium ${roleBadge}">${role}</span></td>
                        <td class="py-3.5 max-w-xs truncate">${r.incident_details}</td>
                        <td class="py-3.5 text-xs">${statusBadge}</td>
                        <td class="py-3.5 text-gray-400">${dateStr}</td>
                    </tr>
                `;
            });
        }

        // Render Notifications Table
        function renderNotificationsTable(smsLogs, emailLogs) {
            const tbody = document.getElementById('notificationsTableBody');
            tbody.innerHTML = '';

            const allNotifs = [];
            smsLogs.forEach(s => allNotifs.push({ ...s, type: 'SMS', recipient: s.parent_number, timestamp: s.timestamp }));
            emailLogs.forEach(e => allNotifs.push({ ...e, type: 'Email', recipient: e.recipient_email, timestamp: e.timestamp }));

            allNotifs.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));

            if (allNotifs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-gray-400 text-xs">No alerts generated or sent.</td></tr>';
                return;
            }

            allNotifs.slice(0, 15).forEach(n => {
                const dateStr = new Date(n.timestamp).toLocaleDateString('en-US', {month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'});
                const statusColor = n.sent_status === 'sent' || n.sent_status === 'delivered' || n.sent_status === 'success' ? 'text-emerald-600' : 'text-amber-500';
                tbody.innerHTML += `
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="py-3.5 font-semibold text-emerald-950">${n.alert_type || 'Critical Warning'}</td>
                        <td class="py-3.5 font-medium">${n.type}</td>
                        <td class="py-3.5 text-gray-500 font-mono text-xs">${n.recipient}</td>
                        <td class="py-3.5"><span class="flex items-center gap-1.5 ${statusColor} font-bold text-xs uppercase">• ${n.sent_status}</span></td>
                        <td class="py-3.5 text-gray-400">${dateStr}</td>
                    </tr>
                `;
            });
        }

        // Render Combined Chronological Event Feed
        function renderTimeline(timeline) {
            const feed = document.getElementById('chronTimeline');
            feed.innerHTML = '';

            if (!timeline || timeline.length === 0) {
                feed.innerHTML = '<div class="text-center py-6 text-gray-400 text-sm">No documented events.</div>';
                return;
            }

            timeline.slice(0, 25).forEach(item => {
                const dateStr = item.date ? new Date(item.date.replace(' ', 'T')).toLocaleDateString('en-US', {month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'}) : 'N/A';
                
                let iconBg = 'bg-gray-200';
                let iconSvg = '';

                if (item.type === 'vent') {
                    const r = item.risk_level;
                    iconBg = r === 'CRITICAL' ? 'bg-red-500 text-white' : r === 'HIGH' ? 'bg-orange-500 text-white' : 'bg-emerald-600 text-white';
                    iconSvg = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 10.742l1.99 9.961a1 1 0 001.99-.046l1.353-6.767a1 1 0 01.78-.78l6.767-1.352a1 1 0 00.046-1.99l-9.962-1.99a1 1 0 00-1.121 1.122z" /></svg>`;
                } else if (item.type === 'incident') {
                    iconBg = 'bg-amber-500 text-white';
                    iconSvg = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>`;
                } else if (item.type === 'sms' || item.type === 'email') {
                    iconBg = 'bg-blue-600 text-white';
                    iconSvg = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>`;
                } else if (item.type === 'note') {
                    iconBg = 'bg-emerald-800 text-white';
                    iconSvg = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>`;
                } else if (item.type === 'status_change') {
                    iconBg = 'bg-gold-dark text-white';
                    iconSvg = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>`;
                }

                feed.innerHTML += `
                    <div class="relative pl-2 pb-2">
                        <span class="absolute -left-[35px] top-1.5 w-8 h-8 rounded-full ${iconBg} flex items-center justify-center ring-4 ring-white">
                            ${iconSvg}
                        </span>
                        <div>
                            <div class="flex flex-wrap justify-between items-baseline gap-2">
                                <h4 class="font-bold text-sm text-emerald-950 font-heading">${item.title}</h4>
                                <span class="text-xs text-gray-400 font-medium">${dateStr}</span>
                            </div>
                            <p class="text-xs text-gray-600 mt-1 leading-relaxed">${item.description}</p>
                            ${item.counselor ? `<span class="text-[9px] font-bold text-emerald-700 uppercase tracking-wide block mt-1">Logged by: ${item.counselor}</span>` : ''}
                        </div>
                    </div>
                `;
            });
        }

        // Render Counselor Notes
        function renderNotes(notes) {
            const container = document.getElementById('notesContainer');
            container.innerHTML = '';

            if (!notes || notes.length === 0) {
                container.innerHTML = '<div class="text-center py-8 text-gray-400 text-xs">No counselor documentation logged.</div>';
                return;
            }

            notes.forEach(n => {
                const dateStr = n.created_at ? new Date(n.created_at.replace(' ', 'T')).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'}) : 'N/A';
                const typeLabels = {
                    'general': 'General note',
                    'observation': 'Observation',
                    'intervention': 'Action/Intervention',
                    'recommendation': 'Recommendation',
                    'follow_up': 'Follow-Up Note'
                };
                
                container.innerHTML += `
                    <div class="p-4 rounded-xl border border-gray-100 bg-gray-50/50 hover:bg-gray-50 transition relative group">
                        <div class="flex justify-between items-start">
                            <div>
                                <span class="text-[9px] uppercase font-bold tracking-wider text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                    ${typeLabels[n.note_type] || 'Note'}
                                </span>
                                <h4 class="font-bold text-xs text-gray-400 mt-1.5">
                                    Logged by ${n.counselor_name || 'System Counselor'} on ${dateStr}
                                </h4>
                            </div>
                            <button onclick="deleteNote(${n.id})" class="no-print opacity-0 group-hover:opacity-100 p-1 text-red-500 hover:bg-red-50 rounded transition-opacity" title="Delete Note">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </div>
                        <p class="text-sm text-gray-700 mt-3 whitespace-pre-line leading-relaxed">${n.content}</p>
                    </div>
                `;
            });
        }

        // Render Previous case status history (read-only)
        function renderStatusHistoryTable(history) {
            const tbody = document.getElementById('statusHistoryTableBody');
            tbody.innerHTML = '';

            if (!history || history.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="py-4 text-center text-gray-400 text-xs">No status change events logged.</td></tr>';
                return;
            }

            const cleanStatus = {
                'active_monitoring': 'Active Monitoring',
                'intervention_ongoing': 'Intervention Ongoing',
                'parent_consultation_required': 'Parent Consultation Required',
                'escalated': 'Escalated',
                'under_investigation': 'Under Investigation',
                'resolved': 'Resolved Case',
                'closed': 'Closed Case'
            };

            const statusBadges = {
                'active_monitoring': 'bg-emerald-50 text-emerald-800 border-emerald-200',
                'intervention_ongoing': 'bg-blue-50 text-blue-800 border-blue-200',
                'parent_consultation_required': 'bg-yellow-50 text-yellow-800 border-yellow-200',
                'escalated': 'bg-red-50 text-red-800 border-red-200',
                'under_investigation': 'bg-purple-50 text-purple-800 border-purple-200',
                'resolved': 'bg-calm-100 text-calm-600 border-calm-200',
                'closed': 'bg-gray-100 text-gray-700 border-gray-300'
            };

            history.forEach(h => {
                const dateStr = h.created_at ? new Date(h.created_at.replace(' ', 'T')).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'}) : 'N/A';
                tbody.innerHTML += `
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="py-3.5"><span class="px-2.5 py-0.5 rounded text-xs font-bold uppercase tracking-wider border ${statusBadges[h.status]}">${cleanStatus[h.status] || h.status}</span></td>
                        <td class="py-3.5 text-xs text-gray-600 max-w-sm whitespace-pre-line">${h.remarks || 'No remarks provided'}</td>
                        <td class="py-3.5 font-medium text-xs text-emerald-950">${h.changed_by_name || 'System'}</td>
                        <td class="py-3.5 text-gray-400 text-xs">${dateStr}</td>
                    </tr>
                `;
            });
        }

        // Note Form Submission
        function submitNote(e) {
            e.preventDefault();
            const noteType = document.getElementById('noteType').value;
            const content = document.getElementById('noteContent').value;

            const formData = new FormData();
            formData.append('note_type', noteType);
            formData.append('content', content);

            fetch(`${formBaseUrl}/${studentId}/note`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: formData
            })
            .then(res => {
                if (!res.ok) throw new Error("HTTP error " + res.status);
                return res.json();
            })
            .then(data => {
                document.getElementById('noteContent').value = '';
                loadCaseData();
            })
            .catch(err => {
                console.error("Note submit error:", err);
                alert("Failed to submit note. Please try again.");
            });
        }

        // Delete Counselor Note
        function deleteNote(id) {
            if (!confirm("Are you sure you want to delete this counseling note? This action will archive it from active view.")) return;

            fetch("/admin/case-summary/note/delete/" + id, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }
            })
            .then(res => {
                if (!res.ok) throw new Error("HTTP error " + res.status);
                return res.json();
            })
            .then(data => {
                loadCaseData();
            })
            .catch(err => {
                console.error("Delete note error:", err);
                alert("Failed to delete note.");
            });
        }

        function startVideoMeeting() {
            document.getElementById('googleMeetError').classList.add('hidden');
            document.getElementById('googleMeetModal').classList.remove('hidden');
            document.getElementById('googleMeetModal').classList.add('flex');
            document.getElementById('googleMeetLink').focus();
        }

        function hideGoogleMeetDialog() {
            document.getElementById('googleMeetModal').classList.add('hidden');
            document.getElementById('googleMeetModal').classList.remove('flex');
        }

        function joinGoogleMeeting() {
            if (currentHostJoinUrl) window.open(currentHostJoinUrl, '_blank', 'noopener,noreferrer');
            else alert('This session is unavailable. Refresh the page and try again.');
        }

        async function endVideoMeeting() {
            if (!confirm('Close StudentShield access? You must also end the call for everyone in Google Meet.')) return;
            const button = document.getElementById('btnEndVideoMeeting');
            button.disabled = true;
            try {
                const response = await fetch(`/admin/case-summary/${studentId}/meeting/cancel`, {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''}
                });
                const data = await response.json();
                if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Unable to close the session.');
                currentHostJoinUrl = null;
                document.getElementById('btnJoinGoogleMeeting').classList.add('hidden');
                checkPendingRequest();
                alert(data.message);
            } catch (e) { alert(e.message); }
            finally { button.disabled = false; }
        }

        function publishGoogleMeet(event) {
            event.preventDefault();
            const button = document.getElementById('publishGoogleMeetButton');
            const error = document.getElementById('googleMeetError');
            button.disabled = true;
            button.textContent = 'Publishing...';
            error.classList.add('hidden');
            const body = new URLSearchParams(new FormData(document.getElementById('googleMeetForm')));
            fetch(`/admin/case-summary/${studentId}/meeting/start`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body
            }).then(res => res.json()).then(data => {
                if (data.status !== 'success') throw new Error(data.message || 'Could not publish this meeting.');
                currentHostJoinUrl = data.join_url;
                hideGoogleMeetDialog();
                document.getElementById('pendingRequestBadge').classList.add('hidden');
                document.getElementById('btnStartVideoMeeting').classList.add('hidden');
                document.getElementById('btnJoinGoogleMeeting').classList.remove('hidden');
                document.getElementById('btnJoinGoogleMeeting').classList.add('flex');
                document.getElementById('btnEndVideoMeeting').classList.remove('hidden');
                document.getElementById('btnEndVideoMeeting').classList.add('flex');
                document.getElementById('googleMeetForm').reset();
            }).catch(err => {
                error.textContent = err.message;
                error.classList.remove('hidden');
            }).finally(() => {
                button.disabled = false;
                button.textContent = 'Publish for this student';
            });
        }

        // Bootstrap load
        window.addEventListener('DOMContentLoaded', loadCaseData);

        // Escape key closes any open modal on this page
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            const meetModal  = document.getElementById('googleMeetModal');
            if (meetModal && !meetModal.classList.contains('hidden')) hideGoogleMeetDialog();
        });
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
