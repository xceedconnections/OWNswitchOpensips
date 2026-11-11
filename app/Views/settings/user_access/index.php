<?php /** @var list<array<string,mixed>> $users @var bool $canWrite */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">User Access</h1>
        <p class="os-page-sub">Create sub-admin users and choose which menus they can open. They only receive features that Admin Access has enabled for admins, and only the ones you grant here.</p>
    </div>
    <?php if (!empty($canWrite)): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/settings/user-access/create')) ?>"><i class="bi bi-plus-lg me-1"></i>New sub-admin</a>
    <?php endif; ?>
</div>
<div class="os-table-wrap">
<?php if ($users === []): ?>
    <div class="os-empty">
        <div class="os-empty-title">No sub-admins yet</div>
        <div>Add a user, set a password, then tick the menus they are allowed to use.</div>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 os-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th class="os-num">Menus</th>
                    <th>Last login</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php $edit = url('/settings/user-access/' . (int) $u['id'] . '/edit'); ?>
                <tr>
                    <td><a href="<?= e($edit) ?>"><?= e((string) $u['name']) ?></a></td>
                    <td class="os-mono small"><?= e((string) $u['email']) ?></td>
                    <td><span class="badge text-bg-<?= ($u['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= ($u['status'] ?? '') === 'active' ? 'Active' : 'Inactive' ?></span></td>
                    <td class="os-num"><?= e((string) ($u['grant_count'] ?? 0)) ?></td>
                    <td class="small text-muted"><?= e(!empty($u['last_login_at']) ? (string) $u['last_login_at'] : '—') ?></td>
                    <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="<?= e($edit) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
