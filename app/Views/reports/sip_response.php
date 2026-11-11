<?php
/** @var list<array<string,mixed>> $rows @var int $total @var string $window @var string $from @var string $to @var string $label @var array $query @var string $sort @var string $dir */
$windows = [
    '1h' => 'Last 1 hour',
    '3h' => 'Last 3 hours',
    '6h' => 'Last 6 hours',
    '12h' => 'Last 12 hours',
    '24h' => 'Last 24 hours',
    'today' => 'Today',
    'custom' => 'Custom dates',
];
$query = $query ?? ['window' => $window, 'from' => $from, 'to' => $to];
$sort = (string) ($sort ?? $query['sort'] ?? 'calls');
$dir = strtolower((string) ($dir ?? $query['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$basePath = '/reports/sip-response';
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">SIP Response Breakdown</h1>
        <p class="os-page-sub"><?= e($label) ?> · <?= e(number_format($total)) ?> calls</p>
    </div>
</div>
<div class="os-report-page">
<form method="get" action="<?= e(url($basePath)) ?>" class="os-report-filters mb-3">
    <input type="hidden" name="sort" value="<?= e($sort) ?>">
    <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <div class="os-report-filters-head">
        <div class="os-form-section-kicker">Time frame</div>
        <p class="os-form-section-lead mb-0">Sort by SIP code, volume, or share from the column headers.</p>
    </div>
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
            <label class="form-label small mb-0" for="window">Time frame</label>
            <select class="form-select form-select-sm" id="window" name="window">
                <?php foreach ($windows as $k => $text): ?>
                    <option value="<?= e($k) ?>"<?= $window === $k ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0" for="from">From</label>
            <input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0" for="to">To</label>
            <input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit">Run</button></div>
    </div>
</form>
<div class="os-table-wrap os-report-card">
<?php if ($rows === []): ?>
    <div class="os-empty"><div class="os-empty-title">No calls in this time frame</div></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm mb-0 os-table">
            <thead><tr>
                <th><?= sort_link($basePath, $query, 'code', 'SIP code', $sort, $dir) ?></th>
                <th><?= sort_link($basePath, $query, 'label', 'Response', $sort, $dir) ?></th>
                <th class="os-num"><?= sort_link($basePath, $query, 'calls', 'Calls', $sort, $dir) ?></th>
                <th class="os-num"><?= sort_link($basePath, $query, 'share', 'Share', $sort, $dir) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $code = (int) (is_numeric($row['code']) ? $row['code'] : 0);
                $tone = $code === 200 ? 'os-report-row--ok' : (($code >= 400 || $code === 0) ? 'os-report-row--bad' : '');
                ?>
                <tr class="<?= e($tone) ?>">
                    <td class="os-mono"><?= e((string) $row['code']) ?></td>
                    <td><?= e((string) $row['label']) ?></td>
                    <td class="os-num"><?= e(number_format((int) $row['calls'])) ?></td>
                    <td class="os-num"><?= e((string) $row['share']) ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
</div>
