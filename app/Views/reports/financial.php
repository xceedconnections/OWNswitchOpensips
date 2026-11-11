<?php
/** @var array $res @var string $from @var string $to @var string $group @var bool $canCarrier @var array $result @var array $query @var string $sort @var string $dir */
$groups = ['day' => 'Day', 'customer' => 'Customer', 'currency' => 'Currency'] + ($canCarrier ? ['carrier' => 'Carrier'] : []);
$showCost = $res['show_cost'];
$moneyLabel = $showCost ? 'Selling' : 'Charged';
$durLabel = $showCost ? 'Duration/Min' : 'Duration';
$fmtDur = static function (array $row) use ($showCost): string {
    if ($showCost) {
        return (string) ($row['duration_min'] ?? '0.00');
    }
    return fmt_duration($row['talk_seconds'] ?? 0);
};
$result = $result ?? ['total' => count($res['rows'] ?? []), 'page' => 1, 'pages' => 1, 'perPage' => 10];
$query = $query ?? ['from' => $from, 'to' => $to, 'group' => $group];
$sort = (string) ($sort ?? $query['sort'] ?? '');
$dir = strtolower((string) ($dir ?? $query['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$basePath = '/reports/financial';
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Financial Report</h1>
        <p class="os-page-sub">Failed, answered, ASR, ACD and duration. <?= e($moneyLabel) ?> is the customer charge<?= $showCost ? '; buying and profit are admin only' : '' ?> · <?= e(tz_label()) ?></p>
    </div>
</div>
<div class="os-report-page">
<form method="get" action="<?= e(url($basePath)) ?>" class="os-report-filters mb-3">
    <?php if ($sort !== ''): ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <?php endif; ?>
    <div class="os-report-filters-head">
        <div class="os-form-section-kicker">Filters</div>
        <p class="os-form-section-lead mb-0">Group and sort columns ascending or descending from the table headers.</p>
    </div>
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="group">Group by</label>
            <select class="form-select form-select-sm" id="group" name="group"><?php foreach ($groups as $k => $l): ?><option value="<?= e($k) ?>"<?= $group === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit">Run</button></div>
    </div>
</form>
<div class="os-report-hero mb-3">
    <div class="os-report-hero-stat"><span class="lbl">Failed</span><span class="val"><?= e(number_format((int) ($res['totals']['failed'] ?? 0))) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Answered</span><span class="val"><?= e(number_format((int) ($res['totals']['answered'] ?? 0))) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">ASR</span><span class="val os-report-hero-metric"><?= asr_pill($res['totals']['asr'] ?? 0) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">ACD</span><span class="val os-report-hero-metric"><?= acd_pill($res['totals']['acd'] ?? 0) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl"><?= e($durLabel) ?></span><span class="val"><?= e($fmtDur($res['totals'] ?? [])) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl"><?= e($moneyLabel) ?></span><span class="val" style="font-size:1.15rem"><?= e((string) ($res['totals']['revenue'] ?? '0')) ?></span></div>
    <?php if ($showCost): ?>
        <div class="os-report-hero-stat"><span class="lbl">Buying</span><span class="val" style="font-size:1.15rem"><?= e((string) ($res['totals']['cost'] ?? '0')) ?></span></div>
        <div class="os-report-hero-stat"><span class="lbl">Profit</span><span class="val" style="font-size:1.15rem"><?= e((string) ($res['totals']['margin'] ?? '0')) ?></span></div>
    <?php endif; ?>
</div>
<div class="os-table-wrap os-report-card">
<?php if (!$res['rows']): ?>
    <div class="os-empty"><div class="os-empty-title">No data</div><div>No CDRs for this period.</div></div>
<?php else: ?>
    <p class="small text-muted px-3 pt-3 mb-2">Calls include failed and answered. ASR = answered ÷ calls. ACD is average talk seconds.</p>
    <?php if (str_contains($res['totals']['currencies'], ',')): ?><div class="alert alert-warning m-2 mb-0 py-1 small">Multiple currencies are present; the totals row adds raw numbers and is not meaningful. Group by currency.</div><?php endif; ?>
    <?php if ($showCost && bccomp((string) $res['totals']['margin'], '0', 6) < 0): ?>
        <div class="alert alert-warning m-2 mb-0 py-1 small">Negative margin: provider (buy) cost is higher than customer (sell) revenue for these settled calls. Check sell tariffs vs provider rates.</div>
    <?php endif; ?>
    <div class="table-responsive"><table class="table table-sm mb-0 os-table">
        <thead><tr>
            <th><?= sort_link($basePath, $query, 'label', $groups[$group] ?? 'Group', $sort, $dir) ?></th>
            <th><?= sort_link($basePath, $query, 'currency', 'Cur', $sort, $dir) ?></th>
            <th class="os-num"><?= sort_link($basePath, $query, 'calls', 'Calls', $sort, $dir) ?></th>
            <th class="os-num"><?= sort_link($basePath, $query, 'failed', 'Failed', $sort, $dir) ?></th>
            <th class="os-num"><?= sort_link($basePath, $query, 'answered', 'Answered', $sort, $dir) ?></th>
            <th class="os-num"><?= sort_link($basePath, $query, 'asr', 'ASR %', $sort, $dir) ?></th>
            <th class="os-num"><?= sort_link($basePath, $query, 'acd', 'ACD', $sort, $dir) ?></th>
            <th class="os-num"><?= sort_link($basePath, $query, $showCost ? 'duration_min' : 'talk_seconds', $durLabel, $sort, $dir) ?></th>
            <th class="os-num"><?= sort_link($basePath, $query, 'revenue', $moneyLabel, $sort, $dir) ?></th>
            <?php if ($showCost): ?>
                <th class="os-num"><?= sort_link($basePath, $query, 'cost', 'Buying', $sort, $dir) ?></th>
                <th class="os-num"><?= sort_link($basePath, $query, 'margin', 'Profit', $sort, $dir) ?></th>
                <th class="os-num"><?= sort_link($basePath, $query, 'margin_pct', 'Profit %', $sort, $dir) ?></th>
            <?php endif; ?>
        </tr></thead>
        <tbody><?php foreach ($res['rows'] as $r): ?>
            <tr>
                <td class="os-mono"><?= e($r['label']) ?></td><td><?= e($r['currency']) ?></td>
                <td class="os-num"><?= e($r['calls']) ?></td>
                <td class="os-num"><span class="os-count-pill os-count-pill--failed"><?= e($r['failed']) ?></span></td>
                <td class="os-num"><span class="os-count-pill os-count-pill--ok"><?= e($r['answered']) ?></span></td>
                <td class="os-num"><?= asr_pill($r['asr']) ?></td>
                <td class="os-num"><?= acd_pill($r['acd']) ?></td>
                <td class="os-num os-mono"><?= e($fmtDur($r)) ?></td>
                <td class="os-num os-mono"><?= e($r['revenue']) ?></td>
                <?php if ($showCost): ?>
                    <td class="os-num os-mono"><?= e($r['cost']) ?></td>
                    <td class="os-num os-mono<?= bccomp((string) $r['margin'], '0', 6) < 0 ? ' text-danger' : '' ?>"><?= e($r['margin']) ?></td>
                    <td class="os-num<?= bccomp((string) $r['margin'], '0', 6) < 0 ? ' text-danger' : '' ?>"><?= e($r['margin_pct']) ?></td>
                <?php endif; ?></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr class="table-light fw-semibold"><td>Total</td><td></td>
            <td class="os-num"><?= e((int) $res['totals']['calls']) ?></td><td class="os-num"><?= e((int) $res['totals']['failed']) ?></td><td class="os-num"><?= e((int) $res['totals']['answered']) ?></td>
            <td class="os-num"><?= asr_pill($res['totals']['asr']) ?></td><td class="os-num"><?= acd_pill($res['totals']['acd']) ?></td><td class="os-num os-mono"><?= e($fmtDur($res['totals'])) ?></td>
            <td class="os-num os-mono"><?= e($res['totals']['revenue']) ?></td>
            <?php if ($showCost): ?>
                <td class="os-num os-mono"><?= e($res['totals']['cost']) ?></td>
                <td class="os-num os-mono<?= bccomp((string) $res['totals']['margin'], '0', 6) < 0 ? ' text-danger' : '' ?>"><?= e($res['totals']['margin']) ?></td>
                <td class="os-num<?= bccomp((string) $res['totals']['margin'], '0', 6) < 0 ? ' text-danger' : '' ?>"><?= e($res['totals']['margin_pct']) ?></td>
            <?php endif; ?></tr></tfoot>
    </table></div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
</div>
