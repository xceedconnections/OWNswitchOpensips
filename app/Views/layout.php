<?php
/** @var string $content @var string $title @var array $flashes @var array $menu */
$user = current_user();
$roleLabels = [
    'super_admin' => 'Super Admin', 'admin' => 'Admin', 'reseller' => 'Reseller',
    'customer_owner' => 'Customer', 'customer_tech' => 'Customer Tech', 'reporter' => 'Reporter',
];
$currentPath = \Ownswitch\App::request()?->path ?? '/';
$itemActive = static function (string $path) use ($currentPath): bool {
    return $path === '/' ? $currentPath === '/' : str_starts_with($currentPath, $path);
};
$sectionActive = static function (array $section) use ($itemActive): bool {
    foreach ($section['items'] as $item) {
        if ($itemActive($item['path'])) {
            return true;
        }
    }
    return false;
};
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $b = strtoupper(substr($parts[1] ?? '', 0, 1));
    return $a . $b;
};
$logoUrl = null;
$footerBrand = 'Ownswitch';
try {
    $settingsSvc = new \Ownswitch\Services\Settings\SettingsService(\Ownswitch\App::db());
    $logoUrl = switch_logo_url();
    $sn = $settingsSvc->get('switch_name', '');
    if (is_string($sn) && trim($sn) !== '') {
        $footerBrand = trim($sn);
    }
} catch (\Throwable) {
    $logoUrl = null;
}
$headerBalance = null;
if ($user && in_array($user['role'] ?? '', ['customer_owner', 'customer_tech'], true) && !empty($user['customer_id'])) {
    try {
        $led = new \Ownswitch\Services\Billing\LedgerService(\Ownswitch\App::db());
        $headerBalance = $led->balance((int) $user['customer_id']);
    } catch (\Throwable) {
        $headerBalance = null;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title) ?> · Ownswitch</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php if ($user): ?>
    <script>
    try { if (localStorage.getItem('osSidebarPinned') === '0') document.documentElement.classList.add('os-sidebar-hidden'); } catch (e) {}
    </script>
    <?php endif; ?>
</head>
<body class="os-body<?= $user ? ' os-app' : ' os-auth-body' ?>">
<?php if ($user):
    $sideIcons = [
        'Clients' => 'bi-people',
        'DIDs' => 'bi-telephone',
        'Billing' => 'bi-credit-card',
        'Rates' => 'bi-tags',
        'Reports' => 'bi-bar-chart',
        'Routes' => 'bi-signpost-split',
        'Stats' => 'bi-graph-up',
        'Settings' => 'bi-gear',
    ];
?>
<aside class="os-sidebar" id="osSidebar">
    <div class="os-sidebar-brand">
        <a class="os-logo" href="<?= e(url('/')) ?>">
            <?php if ($logoUrl): ?>
                <img src="<?= e($logoUrl) ?>" alt="<?= e($footerBrand) ?>" class="os-logo-img">
            <?php else: ?>
                <span class="os-logo-mark">Ow</span>
                <span class="os-logo-text">Ownswitch</span>
            <?php endif; ?>
        </a>
        <button type="button" class="os-pin-btn" id="osSidebarPin" aria-pressed="true" title="Unpin menu" aria-label="Unpin menu">
            <i class="bi bi-pin-angle-fill" aria-hidden="true"></i>
        </button>
    </div>
    <nav class="os-side-nav" aria-label="Main">
        <a class="os-side-link<?= $itemActive('/') ? ' is-active' : '' ?>" href="<?= e(url('/')) ?>">
            <i class="bi bi-speedometer2" aria-hidden="true"></i>
            <span>Dashboard</span>
        </a>
        <?php foreach ($menu as $section):
            $active = $sectionActive($section);
            $icon = $sideIcons[$section['label']] ?? 'bi-folder';
            ?>
            <div class="os-side-group<?= $active ? ' is-open' : '' ?>">
                <button type="button" class="os-side-group-btn<?= $active ? ' is-active' : '' ?>" aria-expanded="<?= $active ? 'true' : 'false' ?>">
                    <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
                    <span><?= e($section['label']) ?></span>
                    <i class="bi bi-chevron-down os-side-chevron" aria-hidden="true"></i>
                </button>
                <div class="os-side-sub">
                    <?php foreach ($section['items'] as $item): ?>
                        <a class="os-side-sublink<?= $itemActive($item['path']) ? ' is-active' : '' ?>" href="<?= e(url($item['path'])) ?>">
                            <?= e($item['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>
<div class="os-sidebar-backdrop" id="osSidebarBackdrop" hidden></div>
<div class="os-shell">
<header class="os-header">
    <div class="os-header-inner">
        <button class="btn os-nav-burger" type="button" id="osSidebarOpen" aria-controls="osSidebar" aria-expanded="false" aria-label="Show menu">
            <i class="bi bi-list fs-4"></i>
        </button>
        <a class="os-logo os-top-logo" href="<?= e(url('/')) ?>">
            <?php if ($logoUrl): ?>
                <img src="<?= e($logoUrl) ?>" alt="<?= e($footerBrand) ?>" class="os-logo-img">
            <?php else: ?>
                <span class="os-logo-mark">Ow</span>
                <span class="os-logo-text">Ownswitch</span>
            <?php endif; ?>
        </a>

        <div class="os-header-actions">
            <?php if ($user && in_array($user['role'] ?? '', ['customer_owner', 'customer_tech'], true) && !empty($user['customer_id'])): ?>
                <div class="os-cc-pill" id="osCcPill" title="Concurrent calls: allowed / in use" data-os-cc data-url="<?= e(url('/api/live/concurrent')) ?>">
                    <span class="os-cc-k">CC</span>
                    <span class="os-cc-v" data-os-cc-value>— / —</span>
                </div>
            <?php endif; ?>
            <?php if ($headerBalance !== null): ?>
                <div class="os-balance-chip" title="Available balance">
                    <span class="os-dot os-dot-ok"></span>
                    <span class="os-balance-label">Balance</span>
                    <strong class="os-mono"><?= e(fmt_money($headerBalance, 2)) ?></strong>
                </div>
            <?php endif; ?>
            <div class="dropdown">
                <button class="os-avatar-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account">
                    <span class="os-avatar"><?= e($initials((string) $user['name'])) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end os-dd-menu shadow-sm border-0">
                    <li class="px-3 py-2">
                        <div class="fw-semibold"><?= e((string) $user['name']) ?></div>
                        <div class="small text-muted"><?= e((string) $user['email']) ?></div>
                        <div class="small text-muted"><?= e($roleLabels[$user['role']] ?? (string) $user['role']) ?></div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= e(url('/account/profile')) ?>"><i class="bi bi-person-gear me-2"></i>My profile</a></li>
                    <li><a class="dropdown-item" href="<?= e(url('/account/password')) ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
                    <li>
                        <form method="post" action="<?= e(url('/logout')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</header>

<main class="os-main-top">
    <?php if (!empty($killSwitch)): ?>
        <div class="alert alert-danger mb-3" role="alert">
            <strong>Emergency kill switch is ON.</strong> New outbound calls should be rejected. Turn it off in Settings when safe.
            <?php
            $cu = function_exists('current_user') ? current_user() : null;
            if (is_array($cu) && in_array((string) ($cu['role'] ?? ''), ['super_admin', 'admin'], true)): ?>
                <a class="alert-link ms-1" href="<?= e(url('/settings')) ?>">Open Settings</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info') ?> alert-dismissible fade show" role="alert">
            <?= e($f['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="os-footer">
    <div class="os-footer-inner">
        <span class="os-footer-brand"><?= e($footerBrand) ?></span>
        <span class="os-footer-sep" aria-hidden="true">·</span>
        <span class="os-footer-meta">ON-Switch - Powered By <a href="https://xceedconnections.com/onswitch-hosted-switch-cloud.php" target="_blank" rel="noopener noreferrer">Xceed Connections</a></span>
    </div>
</footer>
</div>
<?php else: ?>
<main class="os-auth-page">
    <div class="os-auth-wrap">
        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info') ?>" role="alert"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
        <p class="os-auth-foot mb-0">ON-Switch - Powered By <a href="https://xceedconnections.com/onswitch-hosted-switch-cloud.php" target="_blank" rel="noopener noreferrer">Xceed Connections</a></p>
    </div>
</main>
<?php endif; ?>
<?php if ($user): ?>
<div id="os-player" class="os-audio-dock" hidden>
    <div class="os-audio-dock-inner">
        <i class="bi bi-play-circle" aria-hidden="true"></i>
        <span class="os-audio-dock-label" id="os-player-label">Recording</span>
        <audio id="os-player-audio" controls preload="none"></audio>
        <a id="os-player-dl" class="btn btn-outline-secondary btn-sm" href="#"><i class="bi bi-download me-1"></i>Download</a>
    </div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach (($pageScripts ?? []) as $src): ?>
<script src="<?= e(asset((string) $src)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
