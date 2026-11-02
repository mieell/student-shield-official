<header class="vent-header">
    <div class="vent-header-inner">
        <a class="vent-brand" href="<?= base_url('student/dashboard') ?>" aria-label="Go to StudentShield action selection">
            <img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM logo" width="48" height="48">
            <span><strong>StudentShield</strong><span>Student portal</span></span>
        </a>
        <?= view('partials/student_workspace_navigation', ['page' => $page ?? 'vent']) ?>
        <form class="vent-sign-out" action="<?= base_url('logout') ?>" method="POST">
            <?= csrf_field() ?>
            <button type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H5v14h4m5-11 4 4-4 4M8 12h10"/></svg>Sign Out</button>
        </form>
    </div>
</header>
