<?php
/**
 * @var string $title @var string $slug @var string $from @var string $to
 * @var array $res @var list<array{label:string,path:string}> $reportLinks
 * @var array $result @var array $query @var string $sort @var string $dir
 */
$showCost = !empty($res['show_cost']);
$moneyLabel = $showCost ? 'Sell' : 'Charged';
$durLabel = $showCost ? 'Duration/Min' : 'Duration';
$fmtDur = static function (array $row) use ($showCost): string {
    if ($showCost) {
        return (string) ($row['duration_min'] ?? '0.00');
    }
    return fmt_duration($row['session_time'] ?? 0);
};
$cols = $res['columns'] ?? [];
$rows = $res['rows'] ?? [];
$tot = $res['totals'] ?? [];
$blurb = (string) ($res['meta']['blurb'] ?? '');
$result = $result ?? ['total' => count($rows), 'page' => 1, 'pages' => 1, 'perPage' => 10];
$query = $query ?? ['from' => $from, 'to' => $to];
$sort = (string) ($sort ?? $query['sort'] ?? '');
$dir = strtolower((string) ($dir ?? $query['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$basePath = '/reports/' . $slug;
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($title) ?></h1>
        <p class="os-page-sub"><?= e($blurb !== '' ? $blurb : 'Call summary from CDRs') ?> · <?= e(tz_label()) ?></p>
    </div>
</div>

<div class="os-report-page">
<?php if ($reportLinks !== []): ?>
<nav class="os-report-tabs mb-3" aria-label="Report types">
    <div class="os-report-tabs-inner">
        <?php foreach ($reportLinks as $link): ?>
            <a class="os-report-tab<?= $link['path'] === $basePath ? ' is-active' : '' ?>"
               href="<?= e(url($link['path'] . '?from=' . urlencode($from) . '&to=' . urlencode($to))) ?>"><?= e($link['label']) ?></a>
        <?php endforeach; ?>
    </div>
</nav>
<?php endif; ?>

<form method="get" action="<?= e(url($basePath)) ?>" class="os-report-filters mb-3">
    <?php if ($sort !== ''): ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <?php endif; ?>
    <div class="os-report-filters-head">
        <div class="os-form-section-kicker">Period</div>
        <p class="os-form-section-lead mb-0">Click any column header to sort ascending or descending.</p>
    </div>
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0" for="from">From</label>
            <input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0" for="to">To</label>
            <input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-play-fill me-1"></i>Run</button></div>
    </div>
</form>

<div class="os-report-hero mb-3">
    <div class="os-report-hero-stat"><span class="lbl">Calls</span><span class="val"><?= e(number_format((int) ($tot['calls'] ?? 0))) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Failed</span><span class="val"><?= e(number_format((int) ($tot['failed'] ?? 0))) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Answered</span><span class="val"><?= e(number_format((int) ($tot['answered'] ?? 0))) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">ASR</span><span class="val os-report-hero-metric"><?= asr_pill($tot['asr'] ?? 0) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">ACD</span><span class="val os-report-hero-metric"><?= acd_pill($tot['acd'] ?? 0) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl"><?= e($durLabel) ?></span><span class="val"><?= e($fmtDur($tot)) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl"><?= e($moneyLabel) ?></span><span class="val" style="font-size:1.1rem"><?= e((string) ($tot['sell'] ?? '0')) ?></span></div>
    <?php if ($showCost): ?>
        <div class="os-report-hero-stat"><span class="lbl">Buy</span><span class="val" style="font-size:1.1rem"><?= e((string) ($tot['buy'] ?? '0')) ?></span></div>
        <div class="os-report-hero-stat"><span class="lbl">Profit</span><span class="val" style="font-size:1.1rem"><?= e((string) ($tot['profit'] ?? '0')) ?></span></div>
    <?php endif; ?>
</div>
<div class="os-table-wrap os-report-card">
<?php if (($tot['calls'] ?? 0) === 0): ?>
    <div class="os-empty">
        <div class="os-empty-title">No calls in this period</div>
        <div>Nothing to report for <?= e($from) ?> – <?= e($to) ?>.</div>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 os-table">
            <thead>
            <tr>
                <?php foreach ($cols as $c):
                    $key = match ($c) {
                        'Day', 'Month', 'Period' => 'bucket',
                        'User', 'Trunk', 'Destination' => 'dim_label',
                        default => '',
                    };
                    ?>
                    <th><?= $key !== '' ? sort_link($basePath, $query, $key, $c, $sort, $dir) : e($c) ?></th>
                <?php endforeach; ?>
                <th class="os-num"><?= sort_link($basePath, $query, 'calls', 'Calls', $sort, $dir) ?></th>
                <th class="os-num"><?= sort_link($basePath, $query, 'failed', 'Failed', $sort, $dir) ?></th>
                <th class="os-num"><?= sort_link($basePath, $query, 'answered', 'Answered', $sort, $dir) ?></th>
                <th class="os-num"><?= sort_link($basePath, $query, 'asr', 'ASR %', $sort, $dir) ?></th>
                <th class="os-num"><?= sort_link($basePath, $query, 'acd', 'ACD', $sort, $dir) ?></th>
                <th class="os-num"><?= sort_link($basePath, $query, $showCost ? 'duration_min' : 'session_time', $durLabel, $sort, $dir) ?></th>
                <th class="os-num"><?= sort_link($basePath, $query, 'sell', $moneyLabel, $sort, $dir) ?></th>
                <?php if ($showCost): ?>
                    <th class="os-num"><?= sort_link($basePath, $query, 'buy', 'Buy', $sort, $dir) ?></th>
                    <th class="os-num"><?= sort_link($basePath, $query, 'profit', 'Profit', $sort, $dir) ?></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $asr = (float) ($r['asr'] ?? 0);
                $rowTone = $asr >= 60 ? 'os-report-row--ok' : ($asr < 30 ? 'os-report-row--bad' : '');
                ?>
                <tr class="<?= e($rowTone) ?>">
                    <?php if (in_array('Day', $cols, true) || in_array('Month', $cols, true) || in_array('Period', $cols, true)): ?>
                        <td class="os-mono"><?= e($r['bucket']) ?></td>
                    <?php endif; ?>
                    <?php if (in_array('User', $cols, true) || in_array('Trunk', $cols, true) || in_array('Destination', $cols, true)): ?>
                        <td><?= e($r['dim_label']) ?></td>
                    <?php endif; ?>
                    <td class="os-num"><?= e($r['calls']) ?></td>
                    <td class="os-num"><span class="os-count-pill os-count-pill--failed"><?= e($r['failed']) ?></span></td>
                    <td class="os-num"><span class="os-count-pill os-count-pill--ok"><?= e($r['answered']) ?></span></td>
                    <td class="os-num"><?= asr_pill($r['asr']) ?></td>
                    <td class="os-num"><?= acd_pill($r['acd']) ?></td>
                    <td class="os-num os-mono"><?= e($fmtDur($r)) ?></td>
                    <td class="os-num os-mono"><?= e($r['sell']) ?></td>
                    <?php if ($showCost): ?>
                        <td class="os-num os-mono"><?= e($r['buy']) ?></td>
                        <td class="os-num os-mono"><?= e($r['profit']) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr class="table-light fw-semibold">
                <td<?= count($cols) > 1 ? ' colspan="' . count($cols) . '"' : '' ?>>Total</td>
                <td class="os-num"><?= e($tot['calls']) ?></td>
                <td class="os-num"><?= e($tot['failed']) ?></td>
                <td class="os-num"><?= e($tot['answered']) ?></td>
                <td class="os-num"><?= asr_pill($tot['asr']) ?></td>
                <td class="os-num"><?= acd_pill($tot['acd']) ?></td>
                <td class="os-num os-mono"><?= e($fmtDur($tot)) ?></td>
                <td class="os-num os-mono"><?= e($tot['sell']) ?></td>
                <?php if ($showCost): ?>
                    <td class="os-num os-mono"><?= e($tot['buy']) ?></td>
                    <td class="os-num os-mono"><?= e($tot['profit']) ?></td>
                <?php endif; ?>
            </tr>
            </tfoot>
        </table>
    </div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
</div>
