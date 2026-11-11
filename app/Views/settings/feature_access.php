<?php
/**
 * @var array<string, list<array{key:string,label:string,audience:string,path:?string,enabled:bool}>> $groups
 * @var string $vendorName
 */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Admin Access</h1>
        <p class="os-page-sub">Vendor controls · signed in as <span class="os-mono"><?= e($vendorName) ?></span>. You always keep full access. New menus must be listed here so you can enable them for admins.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings')) ?>"><i class="bi bi-arrow-left me-1"></i>Settings</a>
</div>

<form method="post" action="<?= e(url('/settings/admin-access')) ?>" class="os-report-page">
    <?= csrf_field() ?>
    <div class="os-report-filters mb-3">
        <div class="os-report-filters-head">
            <div class="os-form-section-kicker">Visibility matrix</div>
            <p class="os-form-section-lead mb-0">Uncheck an option to hide it from normal admins or from the customer portal. Direct URLs are blocked too. Admins can further restrict their sub-users under Settings → User Access.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary btn-sm" data-once><i class="bi bi-check2 me-1"></i>Save visibility</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-feat-all="1">Enable all</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-feat-all="0">Disable all</button>
        </div>
    </div>

    <?php foreach ($groups as $group => $items): ?>
        <div class="os-table-wrap os-report-card mb-3">
            <div class="px-3 py-2 border-bottom" style="background:linear-gradient(180deg,#f0fafb,#e8f4f6)">
                <strong><?= e($group) ?></strong>
                <span class="small text-muted ms-2"><?= e((string) count($items)) ?> options</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 os-table">
                    <thead>
                    <tr>
                        <th style="width:3rem">On</th>
                        <th>Option</th>
                        <th>Audience</th>
                        <th>Path</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input" name="features[<?= e($it['key']) ?>]" value="1"
                                    <?= !empty($it['enabled']) ? ' checked' : '' ?> data-feat-toggle>
                            </td>
                            <td><?= e($it['label']) ?></td>
                            <td><span class="badge text-bg-<?= $it['audience'] === 'admin' ? 'primary' : 'success' ?>"><?= e($it['audience']) ?></span></td>
                            <td class="os-mono small text-muted"><?= e((string) ($it['path'] ?? '— dashboard —')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="os-entity-form-foot border rounded-3 px-3 py-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary" data-once><i class="bi bi-check2 me-1"></i>Save visibility</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/')) ?>">Cancel</a>
    </div>
</form>
<script>
document.querySelectorAll('[data-feat-all]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var on = btn.getAttribute('data-feat-all') === '1';
    document.querySelectorAll('[data-feat-toggle]').forEach(function (cb) { cb.checked = on; });
  });
});
</script>
