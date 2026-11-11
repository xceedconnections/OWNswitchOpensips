<?php
/** @var array $result @var array $f @var array $customers @var array $carriers @var list<string> $dispositions @var bool $showCost @var array $query @var bool $canDelete */
$canDelete = !empty($canDelete);
$staff = !empty($showCost);
$sort = is_string($query['sort'] ?? null) ? (string) $query['sort'] : 'setup_at';
$dir = strtolower((string) ($query['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$exportBase = array_filter($f, static fn ($v) => is_string($v) || is_int($v));
$exportBase['sort'] = $sort;
$exportBase['dir'] = $dir;
$csvQs = http_build_query($exportBase);
$xlsQs = http_build_query($exportBase + ['format' => 'excel']);
$sortQuery = $f + ['sort' => $sort, 'dir' => $dir];
$toLines = static function (mixed $raw): string {
    $s = trim((string) ($raw ?? ''));
    if ($s === '') {
        return '';
    }
    $parts = preg_split('/[\s,;|]+/', $s) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn ($x) => $x !== ''));
    return implode("\n", $parts);
};
$srcLines = $toLines($f['src'] ?? '');
$dstLines = $toLines($f['dst'] ?? '');
$answeredN = 0;
$failedN = 0;
foreach ($result['rows'] as $tmpRow) {
    $d = (string) ($tmpRow['disposition'] ?? '');
    if ($d === 'ANSWERED') {
        $answeredN++;
    } elseif (in_array($d, ['FAILED', 'BUSY', 'NO_ANSWER', 'CANCELLED'], true)) {
        $failedN++;
    }
}
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Call history <span class="os-count"><?= e($result['total']) ?></span></h1>
        <p class="os-page-sub">Search multiple CallerIDs or destinations · flexible number formats · times in <?= e(tz_label()) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs/export' . ($csvQs !== '' ? '?' . $csvQs : ''))) ?>"><i class="bi bi-download me-1"></i>CSV</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs/export?' . $xlsQs)) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
</div>

<div class="os-report-page">
<div class="os-report-hero mb-3">
    <div class="os-report-hero-stat"><span class="lbl">Results</span><span class="val"><?= e(number_format((int) $result['total'])) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Period</span><span class="val" style="font-size:1rem"><?= e(($f['from'] ?? '') . ' → ' . ($f['to'] ?? '')) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Page answered</span><span class="val"><?= e((string) $answeredN) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Page failed</span><span class="val"><?= e((string) $failedN) ?></span></div>
</div>

<form method="get" action="<?= e(url('/cdrs')) ?>" class="os-report-filters mb-3">
    <input type="hidden" name="sort" value="<?= e($sort) ?>">
    <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <div class="os-report-filters-head">
        <div class="os-form-section-kicker">Filters</div>
        <p class="os-form-section-lead mb-0">Paste one number per line. Formats like <span class="os-mono">+1…</span>, <span class="os-mono">0…</span>, or partial prefixes all match.</p>
    </div>
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($f['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($f['to']) ?>"></div>
        <?php if ($customers): ?>
            <div class="col-6 col-md-3"><label class="form-label small mb-0" for="customer_id">Customer</label>
                <select class="form-select form-select-sm" id="customer_id" name="customer_id"><option value="">All</option>
                    <?php foreach ($customers as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) ($f['customer_id'] ?? '') === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
                </select></div>
        <?php endif; ?>
        <?php if ($carriers): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0" for="carrier_id">Carrier</label>
                <select class="form-select form-select-sm" id="carrier_id" name="carrier_id"><option value="">All</option>
                    <?php foreach ($carriers as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) ($f['carrier_id'] ?? '') === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
                </select></div>
        <?php endif; ?>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="disposition">Disposition</label>
            <select class="form-select form-select-sm" id="disposition" name="disposition"><option value="">All</option>
                <?php foreach ($dispositions as $d): ?><option value="<?= e($d) ?>"<?= ($f['disposition'] ?? '') === $d ? ' selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="min_duration">Min duration (s)</label><input class="form-control form-control-sm" id="min_duration" name="min_duration" value="<?= e($f['min_duration'] ?? '') ?>" inputmode="numeric" maxlength="7"></div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Search</button></div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-12 col-lg-6">
            <div class="os-report-number-box">
                <label class="form-label mb-1" for="src">CallerID(s)</label>
                <textarea class="form-control" id="src" name="src" rows="8" maxlength="8000" placeholder="2138007447&#10;+12138007447&#10;1213800"><?= e($srcLines) ?></textarea>
                <p class="os-report-number-hint mb-0">One CallerID per line</p>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="os-report-number-box">
                <label class="form-label mb-1" for="dst">Dialed number(s)</label>
                <textarea class="form-control" id="dst" name="dst" rows="8" maxlength="8000" placeholder="02138007447&#10;12138007447&#10;1213800"><?= e($dstLines) ?></textarea>
                <p class="os-report-number-hint mb-0">One dialed number per line</p>
            </div>
        </div>
    </div>
</form>

<div class="os-table-wrap os-report-card">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty"><div class="os-empty-title">No calls match</div><div>No CDRs in this period for the selected filters.</div></div>
<?php else: ?>
    <form method="post" action="<?= e(url('/cdrs/delete')) ?>" id="cdr-bulk-form" data-confirm="Delete the selected CDRs? This cannot be undone.">
        <?= csrf_field() ?>
        <?php foreach ($f as $fk => $fv): if (is_string($fv) || is_int($fv)): ?>
            <input type="hidden" name="<?= e((string) $fk) ?>" value="<?= e((string) $fv) ?>">
        <?php endif; endforeach; ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <input type="hidden" name="dir" value="<?= e($dir) ?>">
        <?php if ($canDelete): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center px-3 py-2 border-bottom os-cdr-toolbar">
            <button type="submit" name="mode" value="selected" class="btn btn-outline-danger btn-sm" data-once>Delete selected</button>
            <button type="submit" name="mode" value="filtered" class="btn btn-outline-danger btn-sm" data-confirm="Delete ALL CDRs matching the current filters?" data-once>Delete filtered</button>
            <button type="submit" name="mode" value="all" class="btn btn-danger btn-sm" data-confirm="Delete ALL CDRs in the system? This cannot be undone." data-once>Delete all</button>
            <span class="small text-muted ms-auto">Reports update after delete</span>
        </div>
        <?php endif; ?>
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 os-table os-cdr-table">
        <thead><tr>
            <?php if ($canDelete): ?><th style="width:2rem"><input type="checkbox" id="cdr-check-all" aria-label="Select all"></th><?php endif; ?>
            <th><?= sort_link('/cdrs', $sortQuery, 'id', 'ID', $sort, $dir) ?></th>
            <th></th>
            <th><?= sort_link('/cdrs', $sortQuery, 'setup_at', 'Time (' . user_timezone() . ')', $sort, $dir) ?></th>
            <?php if ($customers): ?><th>Customer</th><?php endif; ?>
            <th>SIP</th><?php if ($staff): ?><th>Dir</th><?php endif; ?>
            <th><?= sort_link('/cdrs', $sortQuery, 'src', 'CallerID', $sort, $dir) ?></th>
            <th><?= sort_link('/cdrs', $sortQuery, 'dst_normalized', 'Destination', $sort, $dir) ?></th>
            <th>Description</th>
            <th><?= sort_link('/cdrs', $sortQuery, 'disposition', 'Result', $sort, $dir) ?></th>
            <?php if ($staff): ?><th>SIP code</th><?php endif; ?>
            <?php if ($staff): ?>
                <th class="os-num"><?= sort_link('/cdrs', $sortQuery, 'duration_actual', 'Actual', $sort, $dir) ?></th>
                <th class="os-num">Billed</th>
            <?php else: ?>
                <th class="os-num"><?= sort_link('/cdrs', $sortQuery, 'duration_actual', 'Duration', $sort, $dir) ?></th>
            <?php endif; ?>
            <th>Hangup</th><?php if ($staff): ?><th>IP</th><?php endif; ?><th>I/S</th>
            <th class="os-num"><?= sort_link('/cdrs', $sortQuery, 'customer_charge', 'Charge', $sort, $dir) ?></th>
            <?php if ($showCost): ?>
                <th>Provider / Trunk</th>
                <th class="os-num"><?= sort_link('/cdrs', $sortQuery, 'carrier_cost', 'Cost', $sort, $dir) ?></th>
                <th class="os-num">Margin</th>
            <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $c):
            $disp = (string) ($c['disposition'] ?? '');
            $dispCls = match ($disp) {
                'ANSWERED' => 'success',
                'BUSY' => 'warning',
                'NO_ANSWER' => 'info',
                'CANCELLED' => 'secondary',
                default => 'danger',
            };
            $rowCls = $disp === 'ANSWERED' ? 'os-cdr-row--answered' : (in_array($disp, ['FAILED', 'BUSY', 'NO_ANSWER', 'CANCELLED'], true) ? 'os-cdr-row--failed' : '');
            ?>
            <tr class="<?= e($rowCls) ?>">
                <?php if ($canDelete): ?>
                <td><input type="checkbox" form="cdr-bulk-form" name="ids[]" value="<?= e((string) $c['id']) ?>" class="cdr-row-check"></td>
                <?php endif; ?>
                <td><a href="<?= e(url('/cdrs/' . (int) $c['id'])) ?>" class="os-mono"><?= e($c['id']) ?></a><?= !empty($c['is_demo']) ? ' <span class="badge text-bg-warning">DEMO</span>' : '' ?></td>
                <td class="text-nowrap">
                    <?php if (!empty($c['recording_id'])):
                        $playUrl = url('/recordings/' . (int) $c['recording_id'] . '/play');
                        $dlUrl = url('/recordings/' . (int) $c['recording_id'] . '/play?download=1');
                        $playLabel = trim((string) ($c['src'] ?? '')) . ' → ' . trim((string) ($c['dst_normalized'] ?? ''));
                        ?>
                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline text-decoration-none me-1" title="Play recording"
                            data-os-play data-src="<?= e($playUrl) ?>" data-download="<?= e($dlUrl) ?>" data-label="<?= e($playLabel) ?>">
                            <i class="bi bi-play-circle"></i>
                        </button>
                        <a href="<?= e($dlUrl) ?>" class="text-decoration-none me-1" title="Download recording"><i class="bi bi-download"></i></a>
                    <?php endif; ?>
                    <?php if (!empty($c['has_sip_trace'])): ?>
                        <a href="<?= e(url('/cdrs/' . (int) $c['id'])) ?>#sip-trace" class="text-decoration-none" title="SIP trace">
                            <i class="bi bi-diagram-3"></i>
                        </a>
                    <?php elseif (empty($c['recording_id'])): ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="os-mono"><?= e(fmt_local((string) $c['setup_at'])) ?></td>
                <?php if ($customers): ?><td><?= e($c['customer_name'] ?? '—') ?></td><?php endif; ?>
                <td class="os-mono"><?= e($c['sip_username'] ?? '—') ?></td>
                <?php if ($staff): ?>
                <td class="small"><?php
                    $dirCol = (string) ($c['direction'] ?? '');
                    echo e($dirCol === 'inbound-did' || $dirCol === 'inbound' ? 'In' : ($dirCol === 'outbound' ? 'Out' : ($dirCol !== '' ? $dirCol : '—')));
                ?></td>
                <?php endif; ?>
                <td class="os-mono"><?= e($c['src'] ?? '—') ?></td>
                <td class="os-mono"><?= e($c['dst_normalized']) ?></td>
                <td><?php if (!empty($c['area_description'])): ?><span class="os-area-tag"><?= e((string) $c['area_description']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                <td><span class="badge text-bg-<?= e($dispCls) ?>"><?php
                    $why = trim((string) ($c['failure_reason'] ?? ''));
                    if (\Ownswitch\Support\CapBlockNote::isCap($why)) {
                        echo 'CAP';
                    } elseif ($why === 'CLI restricted') {
                        echo 'CLI restricted';
                    } else {
                        echo e($disp !== '' ? $disp : '—');
                    }
                ?></span></td>
                <?php if ($staff): ?>
                <td class="small" title="<?= e($c['sip_code_label'] ?? '') ?>"><?= e($c['sip_code_short'] ?? ($c['sip_response'] ?? '—')) ?></td>
                <td class="os-num"><?= e($c['duration_actual']) ?></td>
                <td class="os-num"><?= e($c['duration_billable_customer']) ?></td>
                <?php else: ?>
                <td class="os-num os-mono"><?= e(fmt_duration($c['duration_billable_customer'] ?? 0)) ?></td>
                <?php endif; ?>
                <td class="small"><?= e($c['hangup_initiator'] ?? '—') ?></td>
                <?php if ($staff): ?><td class="os-mono small"><?= e($c['source_ip'] ?? '—') ?></td><?php endif; ?>
                <td class="os-mono"><?= e($c['customer_initial'] ?? '—') ?>/<?= e($c['customer_subsequent'] ?? '—') ?></td>
                <td class="os-num os-mono"><?= e($c['customer_charge']) ?></td>
                <?php if ($showCost): ?>
                    <td><?php
                        $prov = trim((string) ($c['carrier_name'] ?? ''));
                        $trk = trim((string) ($c['trunk_name'] ?? ''));
                        echo e($prov !== '' && $trk !== '' ? $prov . ' / ' . $trk : ($trk !== '' ? $trk : ($prov !== '' ? $prov : '—')));
                    ?></td>
                    <td class="os-num os-mono"><?= e($c['carrier_cost']) ?></td>
                    <td class="os-num os-mono"><?= e($c['gross_margin']) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </form>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
</div>
