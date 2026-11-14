<?php
$rows = array_values($trendRows ?? []);
$maximum = max([0, ...array_column($rows, 'total')]);
$step = max(1, (int) ceil($maximum / 4));
$ceiling = $step * 4;
$last = max(1, count($rows) - 1);
$nonzero = count(array_filter($rows, static fn ($row) => $row['total'] > 0));
$peak = 0;
$points = [];
foreach ($rows as $i => $row) {
    if ($row['total'] > ($rows[$peak]['total'] ?? 0)) $peak = $i;
    $points[] = round(count($rows) === 1 ? 500 : $i * 1000 / $last, 2) . ',' . round(200 - $row['total'] * 200 / $ceiling, 2);
}
$ticks = [];
// Keep dates evenly spaced; individual event dates remain available on the points.
for ($i = 0; $i <= 4; $i++) $ticks[] = (int) round($i * (count($rows) - 1) / 4);
$ticks = array_unique($ticks);
sort($ticks);
?>
<?php if (!$rows): ?>
<p>No daily counts are available for this period.</p>
<?php else: ?>
<div class="daily-trend" data-daily-trend>
    <p class="daily-trend-hint">Select a dot or choose a day to see its event count.</p>
    <div class="daily-trend-scroll" tabindex="0" role="region" aria-label="Daily events chart; scroll horizontally for more dates">
        <div class="daily-trend-canvas" style="--chart-width:<?= max(300, min(1800, count($rows) * 26)) ?>px">
            <div class="daily-trend-scale" aria-hidden="true"><span>Events</span><?php for ($i = 4; $i >= 0; $i--): ?><span style="top:<?= (4 - $i) * 25 ?>% "><?= $step * $i ?></span><?php endfor; ?></div>
            <div class="daily-trend-plot">
                <svg viewBox="0 0 1000 200" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <?php for ($i = 0; $i <= 4; $i++): ?><line x1="0" y1="<?= $i * 50 ?>" x2="1000" y2="<?= $i * 50 ?>" stroke="#dce7df" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/><?php endfor; ?>
                    <polygon points="0,200 <?= esc(implode(' ', $points), 'attr') ?> 1000,200" fill="#e6f3e9"/>
                    <polyline points="<?= esc(implode(' ', $points), 'attr') ?>" fill="none" stroke="#0f8a3a" stroke-width="3" vector-effect="non-scaling-stroke"/>
                </svg>
                <?php $manilaZone = \App\Libraries\ManilaTime::zone(); foreach ($rows as $i => $row): if ($row['total'] > 0 || $i === 0 || $i === count($rows) - 1):
                    $date = (new \DateTimeImmutable($row['label'], $manilaZone))->format('M j, Y');
                    $label = $date . ': ' . (int) $row['total'] . ((int) $row['total'] === 1 ? ' event' : ' events'); ?>
                    <button type="button" class="daily-trend-point" data-day-index="<?= $i ?>" data-label="<?= esc($label, 'attr') ?>" aria-label="<?= esc($label, 'attr') ?>" aria-pressed="<?= $i === $peak ? 'true' : 'false' ?>" style="left:<?= count($rows) === 1 ? 50 : round($i * 100 / $last, 3) ?>%;top:<?= round(100 - $row['total'] * 100 / $ceiling, 3) ?>%">
                        <?php if ($row['total'] > 0 && $nonzero <= 16): ?><span class="daily-trend-value" aria-hidden="true"><?= (int) $row['total'] ?></span><?php endif; ?>
                    </button>
                <?php endif; endforeach; ?>
                <div class="daily-trend-dates" aria-hidden="true"><?php foreach ($ticks as $i): ?><span style="left:<?= count($rows) === 1 ? 50 : round($i * 100 / $last, 3) ?>%" class="<?= $i === 0 ? 'first' : ($i === count($rows) - 1 ? 'last' : '') ?>"><?= (new \DateTimeImmutable($rows[$i]['label'], $manilaZone))->format('M j') ?></span><?php endforeach; ?></div>
            </div>
        </div>
    </div>
    <div class="daily-trend-footer">
        <output class="daily-trend-readout" aria-live="polite"><?= esc((new \DateTimeImmutable($rows[$peak]['label'], $manilaZone))->format('M j, Y') . ': ' . (int) $rows[$peak]['total'] . ((int) $rows[$peak]['total'] === 1 ? ' event' : ' events')) ?></output>
        <label>View day<select data-trend-day><?php foreach ($rows as $i => $row): ?><option value="<?= $i ?>" <?= $i === $peak ? 'selected' : '' ?>><?= esc((new \DateTimeImmutable($row['label'], $manilaZone))->format('M j, Y') . ' — ' . (int) $row['total']) ?></option><?php endforeach; ?></select></label>
    </div>
</div>
<?php endif; ?>
