<?php
$workspaceLinks = [
    'vent' => ['Safe Space', 'student/vent', 'M8 10h8M8 14h5M21 12a9 9 0 01-9 9H3l2-4a9 9 0 1116-5z'],
    'report' => ['Report Bullying', 'report-bullying', 'M5 21V4m0 0c5-4 9 4 15 0v10c-6 4-10-4-15 0'],
    'reports' => ['My reports', 'report/bullying?view=reports', 'M9 5H5v16h14V5h-4M9 3h6v4H9zM8 12h8M8 16h5'],
];
?>
<nav class="student-form-nav student-workspace-nav" aria-label="Student page navigation">
    <?php foreach ($workspaceLinks as $key => [$label, $url, $path]): ?>
    <a href="<?= base_url($url) ?>" class="nav-control" <?= ($page ?? 'vent') === $key ? 'aria-current="page"' : '' ?>>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= esc($path, 'attr') ?>"/></svg>
        <span><?= esc($label) ?></span>
    </a>
    <?php endforeach; ?>
</nav>
