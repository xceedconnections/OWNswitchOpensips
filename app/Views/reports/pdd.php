<?php
/** @var array $res @var string $from @var string $to @var bool $staff @var array $query @var string $sort @var string $dir */
$staff = !empty($staff);
$query = $query ?? ['from' => $from, 'to' => $to];
$sort = (string) ($sort ?? $query['sort'] ?? '');
$dir = strtolower((string) ($dir ?? $query['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$basePath = '/reports/pdd';
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">PDD Report</h1>
        <p class="os-page-sub">Failed and answered calls, ASR, ACD, duration, and post-dial delay on answered calls</p>
    </div>
</div>
<div class="os-report-page">
<form method="get" action="<?= e(url($basePath)) ?>" class="os-report-filters mb-3">
    <?php if ($sort !== ''): ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <?php endif; ?>
    <div class="os-report-filters-head">
        <div class="os-form-section-kicker">Period</div>
        <p class="os-form-section-lead mb-0">Sort trunks by answered volume or average PDD from the column headers.</p>
    </div>
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit">Run</button></div>
    </div>
</form>
<div class="os-report-hero mb-3">
    <div class="os-report-hero-stat"><span class="lbl">Failed</span><span class="val"><?= e(number_format((int) ($res['failed'] ?? 0))) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Answered</span><span class="val"><?= e(number_format((int) $res['answered'])) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">ASR</span><span class="val os-report-hero-metric"><?= asr_pill($res['asr'] ?? 0) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">ACD</span><span class="val os-report-hero-metric"><?= acd_pill($res['acd'] ?? 0) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl"><?= $staff ? 'Duration/Min' : 'Duration' ?></span><span class="val"><?= e($staff ? (string) ($res['duration_min'] ?? '0.00') : fmt_duration($res['talk_seconds'] ?? 0)) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Avg PDD</span><span class="val"><?= e($res['avg_pdd_ms']) ?><span style="font-size:.85rem;opacity:.85"> ms</span></span></div>
    <div class="os-report-hero-stat"><span class="lbl">P50</span><span class="val"><?= e($res['p50_ms']) ?><span style="font-size:.85rem;opacity:.85"> ms</span></span></div>
    <div class="os-report-hero-stat"><span class="lbl">P95</span><span class="val"><?= e($res['p95_ms']) ?><span style="font-size:.85rem;opacity:.85"> ms</span></span></div>
</div>
<div class="os-table-wrap os-report-card">
<?php if (!$res['rows']): ?>
    <div class="os-empty"><div class="os-empty-title">No answered calls</div><div>No PDD samples in this range.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm mb-0 os-table">
        <thead><tr>
            <th><?= sort_link($basePath, $query, 'label', 'Trunk / carrier', $sort, $dir) ?></th>
            <th class="os-num"><?= sort_link($basePath, $query, 'answered', 'Answered', $sort, $dir) ?></th>
            <th class="os-num"><?= sort_link($basePath, $query, 'avg_pdd_ms', 'Avg PDD (ms)', $sort, $dir) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($res['rows'] as $row): ?>
            <tr>
                <td><?= e((string) $row['label']) ?></td>
                <td class="os-num"><span class="os-count-pill os-count-pill--ok"><?= e(number_format((int) $row['answered'])) ?></span></td>
                <td class="os-num os-mono"><?= e((string) $row['avg_pdd_ms']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</div>
</div>
