<?php
/** @var array $sipUsers @var string $selected @var ?array $snap @var array $stats @var list<array> $labCalls @var ?array $burst @var int $count @var int $stagger @var int $cpsAttempts */
$ccMax = $snap ? (($snap['unlimited_channels'] ?? false) ? '∞' : (string) ($snap['max_channels'] ?? '—')) : '—';
$cpsMax = $snap ? (($snap['unlimited_cps'] ?? false) ? '∞' : (string) ($snap['max_cps'] ?? '—')) : '—';
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Dial Lab</h1>
        <p class="os-page-sub">Dummy Connected calls with no trunk · test concurrent (CC) + CPS + live MM:SS before real traffic</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calls-online')) ?>"><i class="bi bi-telephone-forward me-1"></i>Calls Online</a>
</div>

<div class="alert alert-info py-2">
    Lab calls are <strong>portal-only</strong> (no carrier, no Vicidial). They show on Calls Online with a LAB badge, tick MM:SS in real time, and count toward the SIP user’s concurrent limit — same gate OpenSIPS uses on live INVITEs.
</div>

<div class="os-dash-grid os-dash-kpis mb-3" data-os-dial-stats data-url="<?= e(url('/api/live/dial-stats' . ($selected !== '' ? '?sip=' . rawurlencode($selected) : ''))) ?>" data-interval="2000">
    <div class="os-kpi"><div class="lbl">Concurrent</div><div class="val" data-ds="concurrent"><?= e((string) ($stats['concurrent'] ?? 0)) ?></div><div class="sub">live + lab</div></div>
    <div class="os-kpi"><div class="lbl">Connected</div><div class="val" data-ds="connected"><?= e((string) ($stats['connected'] ?? 0)) ?></div><div class="sub">answered / lab</div></div>
    <div class="os-kpi"><div class="lbl">Live CPS</div><div class="val" data-ds="cps"><?= e((string) ($stats['cps'] ?? 0)) ?></div><div class="sub">this second<?= $selected !== '' ? ' · ' . e($selected) : '' ?></div></div>
    <div class="os-kpi"><div class="lbl">SIP limit</div><div class="val os-mono" style="font-size:1.1rem"><?= e(($snap['using'] ?? 0) . ' / ' . $ccMax) ?></div><div class="sub">CC using / max · CPS max <?= e($cpsMax) ?></div></div>
</div>

<form method="get" action="<?= e(url('/dial-lab')) ?>" class="os-toolbar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small mb-0" for="sip">SIP user</label>
            <select class="form-select form-select-sm" id="sip" name="sip" onchange="this.form.submit()">
                <?php foreach ($sipUsers as $u => $label): ?>
                    <option value="<?= e($u) ?>"<?= $selected === $u ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Refresh</button></div>
    </div>
</form>

<?php if ($selected === ''): ?>
    <div class="os-empty"><div class="os-empty-title">No SIP users</div><div>Create an active SIP user first, then return here.</div></div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="os-panel h-100"><div class="p-3">
            <h2 class="h6 mb-2">1 · Start dummy concurrent calls</h2>
            <form method="post" action="<?= e(url('/dial-lab/start')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="sip" value="<?= e($selected) ?>">
                <div class="mb-2">
                    <label class="form-label small mb-0" for="count">How many</label>
                    <input class="form-control form-control-sm" type="number" id="count" name="count" min="1" max="50" value="<?= e((string) max(1, min(50, $count))) ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-0" for="stagger">Stagger start ages (sec)</label>
                    <input class="form-control form-control-sm" type="number" id="stagger" name="stagger" min="0" max="30" value="<?= e((string) max(0, min(30, $stagger))) ?>">
                    <div class="form-text">e.g. 5 → durations 0s, 5s, 10s… so color bands differ</div>
                </div>
                <button class="btn btn-primary btn-sm" type="submit">Start lab calls</button>
            </form>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="os-panel h-100"><div class="p-3">
            <h2 class="h6 mb-2">2 · Test concurrent limit</h2>
            <p class="small text-muted mb-2">Runs the same <code>CallLimitService</code> OpenSIPS uses. Start lab calls until using &gt; max CC, then this should DENY.</p>
            <form method="post" action="<?= e(url('/dial-lab/check')) ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="sip" value="<?= e($selected) ?>">
                <button class="btn btn-outline-primary btn-sm" type="submit">Check limit now</button>
            </form>
            <form method="post" action="<?= e(url('/dial-lab/stop')) ?>" class="d-inline ms-1" data-os-hangup data-confirm="End all lab calls for this SIP user?">
                <?= csrf_field() ?>
                <input type="hidden" name="sip" value="<?= e($selected) ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit">End all lab</button>
            </form>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="os-panel h-100"><div class="p-3">
            <h2 class="h6 mb-2">3 · CPS burst</h2>
            <form method="post" action="<?= e(url('/dial-lab/cps-burst')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="sip" value="<?= e($selected) ?>">
                <div class="mb-2">
                    <label class="form-label small mb-0" for="cps_n">Attempts in one burst</label>
                    <input class="form-control form-control-sm" type="number" id="cps_n" name="cps_n" min="1" max="200" value="<?= e((string) max(1, min(200, $cpsAttempts))) ?>">
                </div>
                <button class="btn btn-warning btn-sm" type="submit">Fire CPS burst</button>
            </form>
            <?php if ($burst): ?>
                <div class="mt-2 small">
                    <strong><?= e((string) $burst['allowed']) ?></strong> allowed /
                    <strong><?= e((string) $burst['denied']) ?></strong> denied
                    <?php if (!empty($burst['first_deny'])): ?>
                        <div class="text-muted">First deny: <?= e((string) $burst['first_deny']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div></div>
    </div>
</div>

<div class="os-panel">
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
        <strong>Lab calls for <?= e($selected) ?></strong>
        <span class="text-muted small">Durations update every 2s · also visible on Calls Online</span>
    </div>
    <?php if ($labCalls === []): ?>
        <div class="os-empty py-4"><div class="os-empty-title">No lab calls</div><div>Start a few above, then watch MM:SS grow.</div></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-sm mb-0 os-table os-rt-table">
            <thead><tr><th></th><th>SIP</th><th>From</th><th>To</th><th>Status</th><th class="os-num">MM:SS</th><th>CallerID</th><th></th></tr></thead>
            <tbody data-lab-tbody>
            <?php foreach ($labCalls as $c): ?>
                <tr class="<?= e((string) ($c['color'] ?? '')) ?>" data-lab-started="<?= e((string) (time() - (int) ($c['duration'] ?? 0))) ?>">
                    <td><span class="badge text-bg-secondary">LAB</span></td>
                    <td class="os-mono"><?= e((string) ($c['sip_user'] ?? '')) ?></td>
                    <td class="os-mono"><?= e((string) ($c['from_user'] ?? '')) ?></td>
                    <td class="os-mono"><?= e((string) ($c['to_user'] ?? '')) ?></td>
                    <td><strong>Connected</strong></td>
                    <td class="os-num os-mono" data-lab-dur><?= e(fmt_duration((int) ($c['duration'] ?? 0))) ?></td>
                    <td class="os-mono"><?= e((string) ($c['caller_id'] ?? '—')) ?></td>
                    <td class="text-end">
                        <form method="post" action="<?= e(url('/dial-lab/stop')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="sip" value="<?= e($selected) ?>">
                            <input type="hidden" name="callid" value="<?= e((string) ($c['callid'] ?? '')) ?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit" title="End"><i class="bi bi-telephone-x"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
  function fmt(sec) {
    sec = Math.max(0, Math.floor(sec));
    var m = Math.floor(sec / 60), s = sec % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }
  function tickDur() {
    var now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('[data-lab-started]').forEach(function (tr) {
      var started = parseInt(tr.getAttribute('data-lab-started') || '0', 10);
      var cell = tr.querySelector('[data-lab-dur]');
      if (cell && started > 0) { cell.textContent = fmt(now - started); }
      var dur = now - started;
      tr.classList.remove('os-rt-pink-soft', 'os-rt-pink', 'os-rt-green');
      if (dur >= 600) tr.classList.add('os-rt-green');
      else if (dur >= 60) tr.classList.add('os-rt-pink');
      else tr.classList.add('os-rt-pink-soft');
    });
  }
  tickDur();
  window.setInterval(tickDur, 1000);
})();
</script>
