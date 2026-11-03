<?php
$reportStatuses = [
    'pending_review' => ['Submitted', 'Your report was received and is awaiting counselor review.'],
    'verified' => ['Under review', 'Your counselor is reviewing the report. Further follow-up may be needed.'],
    'resolved' => ['Closed by counselor', 'The counselor has marked this report as closed. If the concern continues, please contact the Guidance Office.'],
    'rejected_fake' => ['Review completed', 'The counselor did not verify this report. Contact the Guidance Office for clarification.'],
];
?>
<section id="my-reports" class="student-report-tracker" aria-label="Submitted report statuses">
    <div class="student-report-tracker-body">
        <p>Your latest 50 submissions. Refresh this page to check for updates.</p>
        <a href="<?= base_url('report/bullying') ?>?view=reports">Refresh report status</a>
        <?php if (!$studentReports): ?>
            <p>You have not submitted any reports yet.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($studentReports as $report): ?>
                <?php [$label, $description] = $reportStatuses[$report['status']] ?? ['Awaiting update', 'Contact the Guidance Office for an update.']; ?>
                <li>
                    <div><strong>Report #<?= esc($report['id']) ?></strong><span class="student-report-status student-report-status--<?= esc($report['status'] ?? 'unknown', 'attr') ?>"><?= esc($label) ?></span></div>
                    <p><?= esc($description) ?></p>
                    <?php
                    $progressStep = ['pending_review' => 0, 'verified' => 1, 'resolved' => 2, 'rejected_fake' => 1][$report['status']] ?? null;
                    $progressLabels = $report['status'] === 'rejected_fake'
                        ? ['Submitted', 'Review completed'] : ['Submitted', 'Under review', 'Closed'];
                    ?>
                    <?php if ($progressStep !== null): ?>
                    <ol class="report-progress" aria-label="Review progress for report <?= esc($report['id'], 'attr') ?>">
                        <?php foreach ($progressLabels as $step => $stepLabel): ?>
                        <li class="<?= $step <= $progressStep ? 'report-progress-reached' : '' ?>" <?= $step === $progressStep ? 'aria-current="step"' : '' ?>>
                            <span class="report-progress-dot" aria-hidden="true"><?= $step < $progressStep ? '✓' : $step + 1 ?></span>
                            <span><?= esc($stepLabel) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                    <?php endif; ?>
                    <?php $submittedAt = strtotime((string) ($report['created_at'] ?? '')); ?>
                    <span class="student-report-date" title="<?= esc((string) ($report['created_at'] ?? ''), 'attr') ?>">Submitted: <?= $submittedAt !== false ? esc(date('M j, Y \a\t g:i A', $submittedAt)) : esc((string) ($report['created_at'] ?? '')) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
