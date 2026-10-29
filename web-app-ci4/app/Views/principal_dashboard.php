<?php
$f = $report['filters']; $m = $report['metrics']; $groups = $report['groups'];
$periodLabels = ['today' => 'Today', 'yesterday' => 'Yesterday', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'week' => 'This week', 'month' => 'This month', 'custom' => 'Custom date range'];
$riskCounts = array_fill_keys(['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'], 0);
foreach ($groups['risk'] as $row) if (isset($riskCounts[$row['label']])) $riskCounts[$row['label']] = (int) $row['total'];
$riskTotal = array_sum($riskCounts);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>StudentShield · Principal overview</title>
<script src="https://cdn.tailwindcss.com"></script>
<?= view('partials/school_brand') ?>
<link rel="stylesheet" href="<?= base_url('css/staff-layout.css?v=' . filemtime(FCPATH . 'css/staff-layout.css')) ?>"><link rel="stylesheet" href="<?= base_url('css/role-workspaces.css?v=' . filemtime(FCPATH . 'css/role-workspaces.css')) ?>">
</head><body class="school-staff-page staff-layout role-workspace">
<?= view('partials/staff_navigation', ['staffPage' => 'principal', 'staffPageLabel' => 'School overview']) ?>
<link rel="stylesheet" href="<?= base_url('css/principal-overview.css?v=' . filemtime(FCPATH . 'css/principal-overview.css')) ?>">
<main id="staffMain" class="staff-main role-main principal-workspace">
<header class="workspace-heading"><div class="workspace-title"><span class="workspace-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 20h16M6 16V9m6 7V4m6 12v-5"/></svg></span><div><h1>School overview</h1><p>School-level trends for administrative planning.</p></div></div><div class="report-period"><span>Reporting period</span><strong><?= esc($f['start']) ?> – <?= esc($f['end']) ?></strong></div></header>
<div class="report-toolbar">
<span class="report-preset"><?= esc($periodLabels[$f['period']] ?? 'Selected period') ?></span>
<details class="analytics-filter-panel" id="analyticsFilterPanel"><summary>Refine report</summary>
<form method="get" class="role-panel analytics-filters" aria-label="Analytics filters">
<div class="report-filter-heading"><strong>Refine report</strong><button type="button" id="closeReportFilters" class="secondary" aria-label="Close report filters" hidden>×</button></div>
<label class="filter-full">Period<select name="period" id="analyticsPeriod"><?php foreach ($periodLabels as $value => $label): ?><option value="<?= $value ?>" <?= $f['period'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
<label>Start date<input type="date" name="start" value="<?= esc($f['start'], 'attr') ?>" max="<?= esc($f['today'], 'attr') ?>" required></label>
<label>End date<input type="date" name="end" value="<?= esc($f['end'], 'attr') ?>" max="<?= esc($f['today'], 'attr') ?>" required></label>
<label>Grade level<select name="grade"><option value="">All grades</option><?php foreach ($options['grades'] as $row): ?><option value="<?= esc($row['label'], 'attr') ?>" <?= $f['grade'] === $row['label'] ? 'selected' : '' ?>><?= esc($row['label']) ?></option><?php endforeach; ?></select></label>
<label>Section<select name="section"><option value="">All sections</option><?php foreach ($options['sections'] as $row): ?><option value="<?= esc($row['label'], 'attr') ?>" <?= $f['section'] === $row['label'] ? 'selected' : '' ?>><?= esc($row['label']) ?></option><?php endforeach; ?></select></label>
<label>Event type<select name="category"><?php foreach (['all' => 'All event types', 'Safe Space' => 'Safe Space', 'Bullying' => 'Bullying reports'] as $value => $label): ?><option value="<?= esc($value, 'attr') ?>" <?= $f['category'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
<label>Risk classification<select name="risk"><?php foreach (['all' => 'All classifications', 'LOW' => 'Low', 'MEDIUM' => 'Medium', 'HIGH' => 'High', 'CRITICAL' => 'Critical'] as $value => $label): ?><option value="<?= $value ?>" <?= $f['risk'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<div class="analytics-actions"><button type="submit">Apply filters</button><a class="role-button secondary" href="<?= base_url('principal/dashboard') ?>">Reset filters</a></div>
</form></details>
<p class="report-scope"><?= esc(($f['grade'] ?: 'All grades') . ' · ' . ($f['section'] ?: 'All sections') . ' · ' . ($f['category'] === 'all' ? 'All event types' : $f['category']) . ($f['risk'] !== 'all' ? ' · ' . ucfirst(strtolower($f['risk'])) . ' risk' : '')) ?></p>
<a class="role-button secondary report-export" href="<?= base_url('principal/reports/export?' . http_build_query(array_diff_key($f, ['today' => true]))) ?>"><svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>Export summary</a>
</div>

<div class="report-workspace">
<section class="metric-grid" aria-label="Filtered key metrics">
<?php foreach (['registered' => 'Students registered in period', 'active' => 'Active student accounts', 'events' => 'Events in period', 'attention' => 'Need counselor attention'] as $key => $label): ?><div class="metric"><span><?= esc($label) ?></span><strong><?= number_format($m[$key]) ?></strong></div><?php endforeach; ?>
</section>
<div class="report-context"><div class="report-current-counts"><?php foreach (['today_count' => 'Today', 'week_count' => 'This week', 'month_count' => 'This month'] as $key => $label): ?><span><?= $label ?> <strong><?= number_format($m[$key]) ?></strong></span><?php endforeach; ?></div><p><strong><?= $report['change_percent'] === null ? 'No baseline' : esc(($report['change_percent'] > 0 ? '+' : '') . $report['change_percent'] . '%') ?></strong> events vs previous <?= count($groups['trend']) ?> days · <?= (int) $report['previous_events'] ?> previous events</p></div>
<div class="analytics-charts"><section class="role-panel" aria-labelledby="trendTitle"><h2 id="trendTitle">Events over time</h2>
<?= view('partials/daily_trend_chart', ['trendRows' => $groups['trend']]) ?>
<details><summary>View daily counts</summary><div class="aggregate-scroll"><table class="aggregate-table"><thead><tr><th>Date</th><th>Events</th></tr></thead><tbody><?php foreach ($groups['trend'] as $row): ?><tr><td><?= esc($row['label']) ?></td><td><?= $row['total'] ?></td></tr><?php endforeach; ?></tbody></table></div></details>
</section>
<section class="role-panel report-risk"><h2>Safe Space risk</h2><p class="report-risk-total"><?= number_format($riskTotal) ?> classified <?= $riskTotal === 1 ? 'entry' : 'entries' ?></p>
<?php if (!$riskTotal): ?><p>No classified Safe Space entries in this period.</p><?php else: ?>
<div class="risk-composition" aria-hidden="true"><?php foreach ($riskCounts as $risk => $count): ?><span class="risk-<?= $risk ?>" style="width:<?= $count * 100 / $riskTotal ?>%"></span><?php endforeach; ?></div>
<?php endif; ?>
<dl class="risk-counts"><?php foreach ($riskCounts as $risk => $count): ?><div><dt><span class="risk-key <?= strtolower($risk) ?>" aria-hidden="true"></span><?= ucfirst(strtolower($risk)) ?></dt><dd><?= number_format($count) ?></dd></div><?php endforeach; ?></dl>
<?= view('partials/risk_level_guide') ?>
</section></div></div>
<h2 class="report-breakdown-title">Where events are recorded</h2>
<div class="analytics-grid">
<?php $chartGroups = ['category' => 'Events by category', 'grade' => 'Events by grade level', 'section' => 'Events by section']; ?>
<?php foreach ($chartGroups as $key => $label): ?>
<section class="role-panel"><h2><?= esc($label) ?></h2>
<?php if (!$groups[$key]): ?><p>No matching events in this period.</p><?php else: $max = max(1, ...array_column($groups[$key], 'total')); ?>
<?php foreach ($groups[$key] as $row): ?><div class="bar-row"><span><?= esc($row['label'] ?: 'Not classified') ?></span><div class="bar-track" aria-hidden="true"><div class="bar-fill" style="width:<?= round($row['total'] * 100 / $max, 2) ?>%"></div></div><strong><?= $row['total'] ?></strong></div><?php endforeach; endif; ?>
</section><?php endforeach; ?></div>
<div class="analytics-help"><details class="analytics-counting-note"><summary>How these numbers are counted</summary><p>All summaries use the selected filters. Registered and active counts refer to accounts registered during this period; event and risk filters further limit those counts to accounts with matching events. Today/week/month counts are intersected with your selected period. Active means approved and enabled, not currently online.</p></details><details class="analytics-counting-note"><summary>Event and attention definitions</summary><p>Attention means an unreviewed High or Critical Safe Space entry. Bullying reports are counted once, grouped by the linked victim’s grade/section, or the reporter’s when no victim account is linked. Archived records are excluded.</p></details></div>
</main>
<script src="<?= base_url('js/principal-overview.js?v=' . filemtime(FCPATH . 'js/principal-overview.js')) ?>" defer></script>
<script src="<?= base_url('js/daily-trend.js?v=' . filemtime(FCPATH . 'js/daily-trend.js')) ?>" defer></script>
<?= view('partials/session_idle_modal') ?>
</body></html>
