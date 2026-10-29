<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudentShield - Audit Logs</title>
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
                        white: '#FBFDFB',
                        black: '#131A15',
                        primary: StudentShieldBrand.primary,
                        gold: { DEFAULT: '#D9AA2E', light: '#EAC255' }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #FAFDF5; }
        h1, h2, h3, .font-heading { font-family: 'Nunito', sans-serif; }
        .glass-header {
            background: linear-gradient(160deg, #0D7B34 0%, #096328 60%, #075826 100%);
            border-bottom: 3px solid #EAB308;
            box-shadow: 0 4px 20px -2px rgba(7, 88, 38, 0.35);
        }
        .dashboard-card {
            background: #ffffff;
            border: 1px solid #e5ebe5;
            border-radius: 14px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }
        .section-header {
            background: #ffffff;
            color: #0B5E2E;
            padding: 16px 24px;
            border-bottom: 1px solid #e5ebe5;
            border-left: 4px solid #0B5E2E;
            border-radius: 14px 14px 0 0;
        }
        .thead-green { background: #096328; color: white; }
        .thead-green th { color: rgba(255,255,255,0.92); font-weight: 600; letter-spacing: 0.05em; font-size: 0.75rem; }
    </style>
    <link rel="stylesheet" href="<?= base_url('css/staff-layout.css?v=' . filemtime(FCPATH . 'css/staff-layout.css')) ?>">
</head>
<body class="school-staff-page staff-layout min-h-screen">

    <?= view('partials/staff_navigation', ['staffPage' => 'audit-logs', 'staffPageLabel' => 'Audit Logs']) ?>

    <main id="staffMain" class="staff-main container mx-auto px-6 py-8">
        <div class="staff-page-heading flex items-center justify-between mb-8">
            <div>
                <h2 class="text-2xl font-heading font-bold text-gray-900">System Audit Logs</h2>
                <p class="text-gray-600 text-sm mt-1">Account and security activity. IP addresses reflect the connection observed by the server or a configured trusted proxy.</p>
            </div>
        </div>

        <div class="dashboard-card overflow-hidden">
            <div class="section-header flex justify-between items-center sm:flex-row flex-col gap-4">
                <h2 class="text-lg font-bold flex items-center gap-3">
                    <svg class="h-5 w-5 text-[#0B5E2E]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>Action History</span>
                    <span id="visibleCount" class="text-xs font-semibold px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200"><?= count($logs) ?> entries</span>
                </h2>
            </div>
            <div class="staff-filter-bar px-6 py-3 flex justify-between items-center bg-white border-b border-gray-100 flex-wrap gap-2">
                <div class="flex items-center gap-2 flex-1 min-w-[200px] max-w-sm relative">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input type="text" id="searchLogs" aria-label="Search logs" placeholder="Search actor, action or IP..." class="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:border-[#0B5E2E] focus:ring-2 focus:ring-[#0B5E2E]/20 transition-all">
                </div>
                <div class="staff-filter-controls flex items-center gap-2 flex-wrap">
                    <select id="filterCategoryLogs" aria-label="Filter audit category" class="text-sm px-3 py-2 border border-gray-200 rounded-lg outline-none focus:border-[#0B5E2E] focus:ring-2 focus:ring-[#0B5E2E]/20 bg-white cursor-pointer transition-all">
                        <option value="all">All Categories</option>
                        <option value="INCIDENT">Incident</option>
                        <option value="VENT">Vent</option>
                        <option value="SYSTEM">System (Login/Logout)</option>
                        <option value="STUDENT">Student</option>
                        <option value="USER">User</option>
                    </select>
                    <input type="date" id="filterDateLogs" aria-label="Filter audit date" class="text-sm px-3 py-2 border border-gray-200 rounded-lg outline-none focus:border-[#0B5E2E] focus:ring-2 focus:ring-[#0B5E2E]/20 text-gray-700 bg-white cursor-pointer transition-all">
                    <button type="button" id="clearFiltersLogs" class="text-xs font-semibold text-gray-600 hover:text-gray-900 bg-gray-50 hover:bg-gray-100 active:bg-gray-200 px-3.5 py-2 min-h-[38px] rounded-lg border border-gray-200 transition-colors cursor-pointer">✕ Clear</button>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" id="logsTable">
                    <thead>
                        <tr class="thead-green text-xs uppercase tracking-wider">
                            <th class="px-6 py-4 border-b border-gray-200">Time</th>
                            <th class="px-6 py-4 border-b border-gray-200">Actor</th>
                            <th class="px-6 py-4 border-b border-gray-200">Summary</th>
                            <th class="px-6 py-4 border-b border-gray-200">Category</th>
                            <th class="px-6 py-4 border-b border-gray-200">IP address</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-gray-50/50 transition-colors" data-category="<?= esc(strtoupper($log['entity_type']), 'attr') ?>" data-date="<?= date('Y-m-d', strtotime($log['created_at'])) ?>">
                            <td data-label="Time" class="px-6 py-4 text-xs text-gray-500 whitespace-nowrap">
                                <?= date('M d, Y h:i A', strtotime($log['created_at'])) ?>
                            </td>
                            <td data-label="Actor" class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-8 w-8 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-xs shrink-0">
                                        <?= strtoupper(substr($log['actor_name'] ?? 'U', 0, 1)) ?>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900 text-sm"><?= esc($log['actor_name'] ?? 'System / Unknown') ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?php 
                                                if (isset($log['actor_role'])) {
                                                    echo esc(\App\Libraries\RoleAccess::NAMES[(int) $log['actor_role']] ?? 'Unknown');
                                                } else {
                                                    echo 'Unknown Role';
                                                }
                                            ?>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Summary" class="px-6 py-4">
                                <?php 
                                    $entityId = (int) $log['entity_id'];
                                    $actionStr = ucfirst(strtolower(str_replace('_', ' ', $log['action'])));
                                    $summary = $actionStr . ($entityId ? ' (reference #' . $entityId . ')' : '');
                                    echo "<p class='text-sm text-gray-800'>" . esc($summary) . "</p>";
                                ?>
                            </td>
                            <td data-label="Category" class="px-6 py-4">
                                <?php 
                                    $entity = strtoupper($log['entity_type']);
                                    $bgClass = 'bg-gray-100 text-gray-700';
                                    
                                    if ($entity == 'INCIDENT') {
                                        $bgClass = 'bg-orange-100 text-orange-800';
                                    } elseif ($entity == 'VENT') {
                                        $bgClass = 'bg-blue-100 text-blue-800';
                                    } elseif ($entity == 'STUDENT' || $entity == 'USER') {
                                        $bgClass = 'bg-purple-100 text-purple-800';
                                    } elseif ($entity == 'SYSTEM') {
                                        $bgClass = 'bg-indigo-100 text-indigo-800';
                                    }
                                ?>
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold tracking-wider <?= $bgClass ?>">
                                    <?= esc($entity) ?>
                                </span>
                            </td>
                            <td data-label="IP address" class="px-6 py-4 text-sm text-gray-700"><code><?= esc(($log['ip_address'] ?? '') ?: 'Not recorded') ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="noLogsFound" style="display:none;"><td colspan="5" class="px-6 py-12 text-center text-gray-500 font-medium">No matching audit logs found.</td></tr>
                        <?php if (empty($logs)): ?>
                        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-500">No audit logs found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (isset($pager) && $pager->getPageCount() > 1): ?>
            <style>
                .pagination { display: flex; align-items: center; list-style: none; padding: 0; margin: 0; gap: 0.375rem; flex-wrap: wrap; }
                .pagination > li > a, .pagination > li > span { min-width: 44px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; padding: 0.5rem 0.875rem; border: 1px solid #CFD9D4; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500; color: #3D4943; text-decoration: none; background: white; transition: all 0.15s ease; }
                .pagination > li.active > a, .pagination > li.active > span { background-color: #096328; color: white; border-color: #096328; font-weight: 700; box-shadow: 0 2px 6px rgba(9,99,40,0.25); }
                .pagination > li:not(.active) > a:hover { background-color: #F4F7F5; border-color: #096328; color: #096328; }
            </style>
            <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between flex-wrap gap-4">
                <span class="text-sm text-gray-500">
                    Showing <?= ($pager->getCurrentPage() - 1) * 50 + 1 ?> to <?= min($pager->getCurrentPage() * 50, $pager->getTotal()) ?> of <?= $pager->getTotal() ?> entries
                </span>
                <div class="flex items-center overflow-x-auto max-w-full py-1">
                    <?= $pager->links() ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function applyAuditFilters() {
            const search = document.getElementById('searchLogs').value.toLowerCase().trim();
            const category = document.getElementById('filterCategoryLogs').value;
            const dateVal = document.getElementById('filterDateLogs').value;
            const rows = document.querySelectorAll('#logsTable tbody tr:not(#noLogsFound)');
            let visibleCount = 0;

            rows.forEach(row => {
                const matchSearch = !search || row.innerText.toLowerCase().includes(search);
                const matchCategory = category === 'all' || row.getAttribute('data-category') === category;
                const matchDate = !dateVal || row.getAttribute('data-date') === dateVal;

                if (matchSearch && matchCategory && matchDate) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const noLogs = document.getElementById('noLogsFound');
            if (noLogs) {
                noLogs.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
            }

            // Update visible count badge
            const badge = document.getElementById('visibleCount');
            if (badge) badge.textContent = visibleCount + ' entry' + (visibleCount !== 1 ? 's' : '');
        }

        document.getElementById('searchLogs').addEventListener('input', applyAuditFilters);
        document.getElementById('filterCategoryLogs').addEventListener('change', applyAuditFilters);
        document.getElementById('filterDateLogs').addEventListener('change', applyAuditFilters);
        document.getElementById('clearFiltersLogs').addEventListener('click', function() {
            document.getElementById('searchLogs').value = '';
            document.getElementById('filterCategoryLogs').value = 'all';
            document.getElementById('filterDateLogs').value = '';
            applyAuditFilters();
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
