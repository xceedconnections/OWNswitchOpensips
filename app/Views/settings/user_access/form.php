<?php
/**
 * @var ?array $existing
 * @var array $errors
 * @var array $data
 * @var array<string, list<array{key:string,label:string,path:?string,enabled:bool}>> $featureGroups
 * @var bool|null $canWrite
 * @var bool|null $showFeatureGrants
 * @var bool|null $canDelete
 * @var bool|null $asVendor
 */
$isEdit = $existing !== null;
$canWrite = $canWrite ?? true;
$viewOnly = !$canWrite;
$showFeatureGrants = $showFeatureGrants ?? true;
$canDelete = $canDelete ?? $isEdit;
$action = $isEdit
    ? url('/settings/user-access/' . (int) $existing['id'])
    : url('/settings/user-access');
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($title) ?></h1>
        <p class="os-page-sub"><?= $showFeatureGrants
            ? 'Sub-admin portal login with a restricted menu set'
            : 'Change this admin’s name, email, status, or password' ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings/user-access')) ?>">Back</a>
</div>
<?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger"><?= e((string) $errors['_form']) ?></div>
<?php endif; ?>
<form method="post" action="<?= e($action) ?>" class="os-panel col-12 p-3 p-md-4">
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold" for="name">Name</label>
            <input class="form-control" id="name" name="name" required maxlength="120" value="<?= e((string) ($data['name'] ?? '')) ?>"<?= $viewOnly ? ' disabled' : '' ?>>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold" for="email">Email (login)</label>
            <input class="form-control" id="email" name="email" type="email" required maxlength="190" value="<?= e((string) ($data['email'] ?? '')) ?>"<?= $viewOnly ? ' disabled' : '' ?>>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold" for="status">Status</label>
            <select class="form-select" id="status" name="status"<?= $viewOnly ? ' disabled' : '' ?>>
                <option value="active"<?= (($data['status'] ?? 'active') === 'active') ? ' selected' : '' ?>>Active</option>
                <option value="disabled"<?= (($data['status'] ?? '') === 'disabled') ? ' selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold" for="password">Password<?= $isEdit ? '' : ' *' ?></label>
            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password"<?= $isEdit ? '' : ' required' ?><?= $viewOnly ? ' disabled' : '' ?> placeholder="<?= $isEdit ? 'Leave blank to keep current' : '' ?>">
            <div class="form-text">At least 10 characters, with a letter and a number.</div>
        </div>
    </div>

    <?php if ($showFeatureGrants): ?>
    <div class="os-form-section-kicker mb-2">Allowed menus</div>
    <p class="small text-muted mb-3">Only features you can use (and that Admin Access has enabled) appear here. Unchecked menus are hidden and blocked by URL.</p>

    <?php if ($featureGroups === []): ?>
        <div class="alert alert-warning">No assignable menus are available. Ask the vendor to enable features under Settings → Admin Access.</div>
    <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-feat-all="1"<?= $viewOnly ? ' disabled' : '' ?>>Enable all</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-feat-all="0"<?= $viewOnly ? ' disabled' : '' ?>>Disable all</button>
        </div>
        <?php foreach ($featureGroups as $group => $items): ?>
            <div class="os-table-wrap mb-3">
                <div class="px-3 py-2 border-bottom bg-light"><strong><?= e($group) ?></strong></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 os-table">
                        <thead><tr><th style="width:3rem">On</th><th>Menu</th><th>Path</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input" name="features[<?= e($it['key']) ?>]" value="1"
                                        <?= !empty($it['enabled']) ? ' checked' : '' ?> data-feat-toggle<?= $viewOnly ? ' disabled' : '' ?>>
                                </td>
                                <td><?= e($it['label']) ?></td>
                                <td class="os-mono small text-muted"><?= e((string) ($it['path'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!$viewOnly): ?>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit" data-once>Save</button>
            <?php if ($isEdit && $canDelete): ?>
                <button class="btn btn-outline-danger" type="submit" form="ua-delete" data-once onclick="return confirm('Delete this sub-admin?');">Delete</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</form>
<?php if ($isEdit && !$viewOnly && $canDelete): ?>
<form id="ua-delete" method="post" action="<?= e(url('/settings/user-access/' . (int) $existing['id'] . '/delete')) ?>" class="d-none">
    <?= csrf_field() ?>
</form>
<?php endif; ?>
<?php if ($showFeatureGrants): ?>
<script>
document.querySelectorAll('[data-feat-all]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var on = btn.getAttribute('data-feat-all') === '1';
    document.querySelectorAll('[data-feat-toggle]').forEach(function (cb) { cb.checked = on; });
  });
});
</script>
<?php endif; ?>
