<?php
/** @var array $schema @var array $values @var array<string,string> $errors @var ?string $logoUrl @var array<string,string> $codecOptions @var bool $smtpPasswordSet @var string $activeTab */
$codecOptions = $codecOptions ?? [];
$smtpPasswordSet = !empty($smtpPasswordSet);
$activeTab = in_array(($activeTab ?? 'general'), ['general', 'email'], true) ? $activeTab : 'general';
$groups = \Ownswitch\Services\Settings\SettingsService::groups();

$renderField = static function (string $key, array $meta, array $values, array $errors, array $codecOptions, bool $smtpPasswordSet): void {
    [$label, $type, $help] = $meta;
    if ($type === 'logo') {
        return;
    }
    $err = $errors[$key] ?? null;
    $val = $values[$key] ?? '';
    $helpIcon = $help ? ' ' . field_help($help) : '';
    ?>
    <div class="mb-3">
        <?php if ($type === 'bool'): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"<?= in_array((string) $val, ['1', 'true'], true) ? ' checked' : '' ?>>
                <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?><?= $helpIcon ?></label>
            </div>
        <?php elseif ($type === 'timezone'): ?>
            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?><?= $helpIcon ?></label>
            <select class="form-select<?= $err ? ' is-invalid' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>">
                <?php foreach (\Ownswitch\Support\Timezone::GROUPS as $region => $zones): ?>
                    <optgroup label="<?= e($region) ?>">
                        <?php foreach ($zones as $zoneId => $zoneLabel): ?>
                            <option value="<?= e($zoneId) ?>"<?= $val === $zoneId ? ' selected' : '' ?>><?= e($zoneLabel) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
                <?php if ($val !== '' && !isset(\Ownswitch\Support\Timezone::OPTIONS[$val])): ?>
                    <option value="<?= e((string) $val) ?>" selected><?= e((string) $val) ?></option>
                <?php endif; ?>
            </select>
        <?php elseif ($type === 'media'): ?>
            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?><?= $helpIcon ?></label>
            <select class="form-select" id="<?= e($key) ?>" name="<?= e($key) ?>">
                <option value="relay"<?= $val === 'relay' ? ' selected' : '' ?>>Relay — media via OpenSIPS / RTPengine</option>
                <option value="direct"<?= $val === 'direct' ? ' selected' : '' ?>>Direct — peer-to-peer RTP (direct media)</option>
                <option value="record"<?= $val === 'record' ? ' selected' : '' ?>>Record — relay + recording</option>
            </select>
        <?php elseif ($type === 'codecs'):
            $selected = array_values(array_filter(array_map('trim', explode(',', (string) $val)), static fn ($x) => $x !== ''));
            ?>
            <label class="form-label"><?= e($label) ?><?= $helpIcon ?></label>
            <div class="row g-2">
                <?php foreach ($codecOptions as $code => $optLabel): ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="form-check">
                            <input class="form-check-input<?= $err ? ' is-invalid' : '' ?>" type="checkbox"
                                   name="<?= e($key) ?>[]" id="<?= e($key . '_' . $code) ?>"
                                   value="<?= e($code) ?>"<?= in_array($code, $selected, true) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="<?= e($key . '_' . $code) ?>"><?= e($optLabel) ?></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($type === 'smtp_provider'): ?>
            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?><?= $helpIcon ?></label>
            <select class="form-select<?= $err ? ' is-invalid' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>">
                <option value="gmail"<?= $val === 'gmail' ? ' selected' : '' ?>>Gmail</option>
                <option value="custom"<?= $val !== 'gmail' ? ' selected' : '' ?>>Custom / private SMTP</option>
            </select>
        <?php elseif ($type === 'smtp_encryption'): ?>
            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?><?= $helpIcon ?></label>
            <select class="form-select<?= $err ? ' is-invalid' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>">
                <option value="tls"<?= $val === 'tls' || $val === '' ? ' selected' : '' ?>>TLS (STARTTLS)</option>
                <option value="ssl"<?= $val === 'ssl' ? ' selected' : '' ?>>SSL (SMTPS)</option>
                <option value="none"<?= $val === 'none' ? ' selected' : '' ?>>None</option>
            </select>
        <?php elseif ($type === 'secret'): ?>
            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?><?= $helpIcon ?></label>
            <input class="form-control<?= $err ? ' is-invalid' : '' ?>" type="password" id="<?= e($key) ?>" name="<?= e($key) ?>" value="" autocomplete="new-password" maxlength="190" placeholder="<?= $smtpPasswordSet ? '•••••••• (leave blank to keep)' : '' ?>">
        <?php else:
            $max = match (true) {
                $key === 'failover_sip_codes' => '120',
                in_array($key, ['switch_name', 'smtp_host', 'smtp_username', 'smtp_from_name', 'smtp_from_email'], true) => '190',
                $type === 'email' => '190',
                $type === 'smtp_port' => '5',
                default => '80',
            };
            $inputType = $type === 'email' ? 'email' : 'text';
            ?>
            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?><?= $helpIcon ?></label>
            <input class="form-control<?= $err ? ' is-invalid' : '' ?>" type="<?= e($inputType) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e((string) $val) ?>" maxlength="<?= e($max) ?>">
        <?php endif; ?>
        <?php if ($err): ?><div class="invalid-feedback d-block"><?= e($err) ?></div><?php endif; ?>
    </div>
    <?php
};
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Settings</h1>
        <p class="os-page-sub">Branding, billing, codecs, capacity, failover, and outbound email (SMTP)</p>
    </div>
</div>
<form method="post" action="<?= e(url('/settings')) ?>" enctype="multipart/form-data" class="os-panel col-12" id="settings-form">
    <div class="p-3 p-md-4">
        <?= csrf_field() ?>
        <input type="hidden" name="active_tab" id="active_tab" value="<?= e($activeTab) ?>">

        <ul class="nav nav-tabs mb-4" role="tablist">
            <?php foreach ($groups as $gid => $glabel): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link<?= $activeTab === $gid ? ' active' : '' ?>" id="tab-<?= e($gid) ?>" type="button"
                            data-bs-toggle="tab" data-bs-target="#pane-<?= e($gid) ?>" role="tab"
                            aria-controls="pane-<?= e($gid) ?>" aria-selected="<?= $activeTab === $gid ? 'true' : 'false' ?>"
                            data-tab="<?= e($gid) ?>"><?= e($glabel) ?></button>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade<?= $activeTab === 'general' ? ' show active' : '' ?>" id="pane-general" role="tabpanel" aria-labelledby="tab-general">
                <div class="mb-4 pb-3 border-bottom">
                    <label class="form-label" for="switch_logo">Switch logo <?= field_help('PNG / JPG / WEBP / SVG · max 2 MB · shown in the top navigation for admin and customers') ?></label>
                    <?php if (!empty($logoUrl)): ?>
                        <div class="mb-2 p-2 border rounded bg-light d-inline-block">
                            <img src="<?= e($logoUrl) ?>" alt="Current logo" style="max-height:48px;max-width:220px;object-fit:contain">
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="switch_logo_clear" id="switch_logo_clear" value="1">
                            <label class="form-check-label" for="switch_logo_clear">Remove current logo</label>
                        </div>
                    <?php endif; ?>
                    <input class="form-control<?= isset($errors['switch_logo']) ? ' is-invalid' : '' ?>" type="file" id="switch_logo" name="switch_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                    <?php if (isset($errors['switch_logo'])): ?><div class="invalid-feedback d-block"><?= e($errors['switch_logo']) ?></div><?php endif; ?>
                </div>
                <?php foreach ($schema as $key => $meta):
                    $group = $meta[3] ?? 'general';
                    if ($group !== 'general') {
                        continue;
                    }
                    $renderField($key, $meta, $values, $errors, $codecOptions, $smtpPasswordSet);
                endforeach; ?>
            </div>

            <div class="tab-pane fade<?= $activeTab === 'email' ? ' show active' : '' ?>" id="pane-email" role="tabpanel" aria-labelledby="tab-email">
                <p class="text-muted small mb-3">Configure Gmail or a private SMTP server. Refill emails go out only when this is configured, “Allow refill emails” is on, the admin ticks Send email on the refill form, and the user has not opted out on My profile.</p>
                <?php foreach ($schema as $key => $meta):
                    $group = $meta[3] ?? 'general';
                    if ($group !== 'email') {
                        continue;
                    }
                    $renderField($key, $meta, $values, $errors, $codecOptions, $smtpPasswordSet);
                endforeach; ?>
            </div>
        </div>
    </div>
    <div class="border-top px-3 px-md-4 py-3 bg-light rounded-bottom">
        <button class="btn btn-primary" type="submit" data-once><i class="bi bi-check2 me-1"></i>Save settings</button>
    </div>
</form>
<script>
(function () {
    var form = document.getElementById('settings-form');
    if (!form) return;
    var tabInput = document.getElementById('active_tab');
    form.querySelectorAll('[data-tab]').forEach(function (btn) {
        btn.addEventListener('shown.bs.tab', function () {
            if (tabInput) tabInput.value = btn.getAttribute('data-tab') || 'general';
        });
    });
    var provider = document.getElementById('smtp_provider');
    var host = document.getElementById('smtp_host');
    var port = document.getElementById('smtp_port');
    var enc = document.getElementById('smtp_encryption');
    function applyGmail() {
        if (!provider || provider.value !== 'gmail') return;
        if (host && (!host.value || host.value === 'smtp.gmail.com')) host.value = 'smtp.gmail.com';
        if (port && (!port.value || port.value === '587' || port.value === '465')) port.value = '587';
        if (enc && (enc.value === '' || enc.value === 'tls' || enc.value === 'ssl')) enc.value = 'tls';
    }
    if (provider) {
        provider.addEventListener('change', applyGmail);
        applyGmail();
    }
})();
</script>
