<?php
/** @var array $status */
$s = $status;
$helper = !empty($s['helper']);
$active = !empty($s['opensips_active']);
$state = (string) ($s['opensips_state'] ?? 'unknown');
$uptime = (string) ($s['host_uptime'] ?? '');
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Restart</h1>
        <p class="os-page-sub">Restart OpenSIPS or reboot this server from the portal</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings')) ?>"><i class="bi bi-gear me-1"></i>Settings</a>
</div>

<?php if (!$helper): ?>
    <div class="alert alert-warning">
        The restart helper is not installed on this server yet. As root run:
        <pre class="mb-0 mt-2 small">sudo bash /opt/ownswitch-src/deploy/install-restart-helper.sh</pre>
        Or open <a href="<?= e(url('/settings/version')) ?>">Update</a> and click Update now after the latest main is pulled, then reload this page.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="os-panel p-3 h-100">
            <h2 class="h6 mb-1">OpenSIPS</h2>
            <p class="small text-muted mb-3">Restarts the SIP proxy only. Active calls and registrations may drop for a few seconds.</p>
            <p class="mb-3">
                <?php if ($active): ?>
                    <span class="badge text-bg-success">Active</span>
                <?php else: ?>
                    <span class="badge text-bg-secondary"><?= e($state !== '' ? $state : 'unknown') ?></span>
                <?php endif; ?>
                <span class="small text-muted ms-1">service state: <?= e($state !== '' ? $state : 'unknown') ?></span>
            </p>
            <form method="post" action="<?= e(url('/settings/restart/opensips')) ?>" data-confirm="Restart OpenSIPS now? Active calls and SIP registrations may drop briefly.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary" data-once<?= !$helper ? ' disabled' : '' ?>>
                    <i class="bi bi-arrow-repeat me-1"></i>Restart OpenSIPS
                </button>
            </form>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="os-panel p-3 h-100">
            <h2 class="h6 mb-1">Server</h2>
            <p class="small text-muted mb-3">Reboots the whole host (OS, portal, OpenSIPS, and all services). Use only when needed.</p>
            <?php if ($uptime !== ''): ?>
                <p class="mb-3 small text-muted">Host <?= e($uptime) ?></p>
            <?php else: ?>
                <p class="mb-3 small text-muted">Host uptime is unavailable until the helper is installed.</p>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/settings/restart/server')) ?>" data-confirm="Reboot this entire server now? The portal will go offline until the host finishes restarting.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-danger" data-once<?= !$helper ? ' disabled' : '' ?>>
                    <i class="bi bi-power me-1"></i>Restart server
                </button>
            </form>
        </div>
    </div>
</div>
