<?php
/** @var array $user @var string $timezone @var string $effective @var array<string,string> $options @var list<string> $errors @var array{name?:string,email?:string,email_notifications?:string} $form */
$effective = $effective ?? $timezone;
$form = $form ?? [];
$nameVal = (string) ($form['name'] ?? $user['name'] ?? '');
$emailVal = (string) ($form['email'] ?? $user['email'] ?? '');
$notifyOn = (($form['email_notifications'] ?? '1') === '1');
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">My profile</h1>
        <p class="os-page-sub">Update username, email, password, notifications, and the clock used for reports</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/account/profile')) ?>" class="row g-3" autocomplete="off">
    <?= csrf_field() ?>
    <div class="col-lg-6">
        <div class="os-panel p-3 h-100">
            <h2 class="h6 mb-3">Account</h2>
            <div class="mb-3">
                <label class="form-label" for="name">Username</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= e($nameVal) ?>" required maxlength="120" autocomplete="username">
                <div class="form-text">Used to sign in (same as Email). Letters, numbers, dots, underscores, and hyphens.</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= e($emailVal) ?>" required maxlength="190" autocomplete="email">
            </div>
            <div class="mb-3">
                <label class="form-label" for="role_display">Role</label>
                <input type="text" class="form-control" id="role_display" value="<?= e((string) ($user['role'] ?? '')) ?>" readonly disabled>
            </div>
            <hr>
            <h2 class="h6 mb-3">Password</h2>
            <div class="mb-3">
                <label class="form-label" for="current">Current password</label>
                <input type="password" class="form-control" id="current" name="current" autocomplete="current-password">
                <div class="form-text">Required only when you change username, email, or set a new password.</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new">New password <span class="text-muted fw-normal">(optional)</span></label>
                <input type="password" class="form-control" id="new" name="new" autocomplete="new-password">
            </div>
            <div class="mb-0">
                <label class="form-label" for="confirm">Confirm new password</label>
                <input type="password" class="form-control" id="confirm" name="confirm" autocomplete="new-password">
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="os-panel p-3 h-100">
            <h2 class="h6 mb-3">Timezone</h2>
            <div class="mb-3">
                <label class="form-label" for="timezone">Timezone</label>
                <select class="form-select" id="timezone" name="timezone" required>
                    <option value="system"<?= $timezone === 'system' ? ' selected' : '' ?>>Same as system settings (<?= e(\Ownswitch\Support\Timezone::label(\Ownswitch\Support\Timezone::systemDefault())) ?>)</option>
                    <?php foreach (\Ownswitch\Support\Timezone::GROUPS as $region => $zones): ?>
                        <optgroup label="<?= e($region) ?>">
                            <?php foreach ($zones as $zoneId => $zoneLabel): ?>
                                <option value="<?= e($zoneId) ?>"<?= $timezone === $zoneId ? ' selected' : '' ?>><?= e($zoneLabel) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                    <?php if ($timezone !== 'system' && !isset($options[$timezone])): ?>
                        <option value="<?= e($timezone) ?>" selected><?= e($timezone) ?></option>
                    <?php endif; ?>
                </select>
                <div class="form-text">
                    Dashboard totals, stats, CDR times, and report dates follow this clock. Currently: <?= e(\Ownswitch\Support\Timezone::label($effective)) ?>.
                </div>
            </div>
            <hr>
            <h2 class="h6 mb-3">Email notifications</h2>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="email_notifications" name="email_notifications" value="1"<?= $notifyOn ? ' checked' : '' ?>>
                <label class="form-check-label" for="email_notifications">Receive email from portal services</label>
            </div>
            <div class="form-text mb-3">When enabled, you can get refill notices and similar service mail if the administrator sends them. Default is on.</div>
            <button type="submit" class="btn btn-primary btn-sm" data-once>Save profile</button>
        </div>
    </div>
</form>
