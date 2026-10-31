<?php
$reportStatuses = [
    'pending_review' => ['Submitted', 'Your report is awaiting counselor review.'],
    'verified' => ['Under review', 'The counselor has verified your report. Follow-up may still be ongoing.'],
    'resolved' => ['Resolved', 'The counselor has marked this report as resolved.'],
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
                    <div><strong>Report #<?= esc($report['id']) ?></strong><span class="student-report-status"><?= esc($label) ?></span></div>
                    <p><?= esc($description) ?></p>
                    <span class="student-report-date">Submitted: <?= esc($report['created_at']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
