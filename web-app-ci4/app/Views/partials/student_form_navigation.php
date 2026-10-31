<?php $isIncidentPage = in_array($page ?? 'vent', ['report', 'reports'], true); ?>
<style>
    .student-form-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 24px; }
    .student-form-nav .back-link { margin-right: auto; }
    .student-form-nav form { margin: 0; }
    .student-form-nav .nav-control { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 10px 14px; border: 1px solid #CFD9D4; border-radius: 12px; background: #FBFDFB; color: #3D4943; font: 600 13px/1.4 'Inter', sans-serif; white-space: nowrap; cursor: pointer; text-decoration: none; box-sizing: border-box; transition: background-color .15s, border-color .15s; }
    .student-form-nav .nav-control:hover { background: #E6EBE8; border-color: #82958B; }
    .student-form-nav .nav-control:focus-visible { outline: 2px solid var(--school-green, #017F36); outline-offset: 3px; }
    .student-form-nav svg { width: 16px; height: 16px; flex-shrink: 0; }
    .student-form-nav .report-link { color: #A33535; background: #FDF7F7; border-color: #F4D6D6; }
    .student-form-nav .vent-link { color: var(--school-green, #017F36); background: #f0fdf4; }
    @media (max-width: 479px) {
        .student-form-nav { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
        .student-form-nav .back-link { grid-column: 1 / -1; justify-self: start; }
        .student-form-nav form, .student-form-nav form button { width: 100%; }
        .student-form-nav .nav-control { padding-inline: 10px; }
    }
</style>
<nav class="student-form-nav" aria-label="Student page navigation">
    <a href="<?= base_url('student/dashboard') ?>" class="nav-control back-link" aria-label="Back to previous StudentShield page" data-student-back>
        <svg aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m12 19-7-7 7-7M5 12h14" /></svg>
        Back
    </a>
    <a href="<?= base_url($isIncidentPage ? 'student/vent' : 'report-bullying') ?>" class="nav-control <?= $isIncidentPage ? 'vent-link' : 'report-link' ?>">
        <?php if ($showReportIcon ?? false): ?><svg aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $isIncidentPage ? 'M8 10h8M8 14h5M21 12a9 9 0 0 1-9 9H3l2-4a9 9 0 1 1 16-5Z' : 'M5 21V4m0 0c5-4 9 4 15 0v10c-6 4-10-4-15 0' ?>"/></svg><?php endif; ?>
        <?= $isIncidentPage ? 'Safe Space' : 'Report Bullying' ?>
    </a>
    <?php if ($showMyReports ?? false): ?>
    <?php if ($isIncidentPage): ?>
    <a href="<?= base_url('report-bullying') ?>" class="nav-control report-link" <?= ($page ?? '') === 'report' ? 'aria-current="page"' : '' ?>>Report Bullying</a>
    <?php endif; ?>
    <a href="<?= base_url('report/bullying') ?>?view=reports" class="nav-control reports-history-link" <?= ($page ?? '') === 'reports' ? 'aria-current="page"' : '' ?>>My reports</a>
    <?php endif; ?>
    <?php if ($showSignout ?? true): ?>
    <form action="<?= base_url('logout') ?>" method="POST" class="student-desktop-signout">
        <?= csrf_field() ?>
        <button type="submit" class="nav-control">
            <svg aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0-4-4m4 4H7m6 4v1a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v1" /></svg>
            Sign Out
        </button>
    </form>
    <?php endif; ?>
</nav>
<script>
    document.querySelector('[data-student-back]').addEventListener('click', function (event) {
        if (!document.referrer || window.history.length < 2) return;

        const previousPage = new URL(document.referrer);
        const currentPage = window.location.href.split('#')[0];
        const studentPages = [
            '<?= base_url('student/dashboard') ?>',
            '<?= base_url('student/vent') ?>',
            '<?= base_url('report-bullying') ?>',
            '<?= base_url('report/bullying') ?>'
        ].map(path => new URL(path, window.location.origin).href);

        if (previousPage.origin === window.location.origin &&
            previousPage.href.split('#')[0] !== currentPage &&
            studentPages.includes(previousPage.href.split('#')[0])) {
            event.preventDefault();
            window.history.back();
        }
    });
</script>
