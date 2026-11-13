<?php
/**
 * @var string $title @var string $base @var string $singular @var list<array<string,mixed>> $fields
 * @var array $values @var array<string,string> $errors @var ?array $existing @var bool $canWrite
 * @var list<array{ip:string,port:int,online:bool,ms:?int,state:string}>|null $peerIpLatency
 * @var bool|null $enhancedForm
 */
$action = $existing ? $base . '/' . (int) $existing['id'] : $base;
$isEdit = $existing !== null;
$canWrite = $canWrite ?? true;
$viewOnly = !$canWrite;
$peerIpLatency = is_array($peerIpLatency ?? null) ? $peerIpLatency : [];
$enhancedForm = !empty($enhancedForm) || in_array(($base ?? ''), ['/sip-users', '/trunks', '/providers'], true);
?>
<?php
$formSub = $viewOnly
    ? 'Read-only — contact your provider to change these settings'
    : ($isEdit ? 'Update existing record' : 'Create a new ' . strtolower($singular));
if (($base ?? '') === '/trunk-groups' && !$viewOnly) {
    $formSub = 'Choose which trunks carry your calls and in what order. Each call tries your first trunk; if it can’t connect, it moves to the next one on your list.';
}
if (($base ?? '') === '/area-codes' && !$viewOnly && !$isEdit) {
    $formSub = 'Add one area code, or upload Excel / CSV below to add many.';
}
$sectionHints = [
    'Account' => 'Identity, Type (Dynamic / IP), password or allowed IPs, qualify, and status.',
    'CLI and dial format' => 'Caller ID rules, length checks, destination rewrite, and CLI block lists.',
    'StirShaken' => 'Outbound STIR/SHAKEN certificate and attestation (A / B / C) for Identity headers toward vendors.',
    'Routing & media' => 'Trunk group, DNC, codecs, concurrent call limits, and media path.',
    'IPs' => 'Live status per allowed IP — green + ms only while that IP has an active SIP REGISTER.',
    'General' => 'Core trunk identity, signalling endpoint, and vendor auth.',
    'Dial & CLI' => 'Number rewriting and outbound Caller ID toward the vendor.',
    'Limits' => 'Capacity, CAP, DNC, and gateway probing.',
    'Delayed Bye' => 'Optional hangup delay billed toward the vendor.',
    'Media' => 'Codec offer order for this gateway.',
];
$sectionIcons = [
    'Account' => 'bi-person-badge',
    'CLI and dial format' => 'bi-telephone-forward',
    'StirShaken' => 'bi-shield-check',
    'Routing & media' => 'bi-broadcast',
    'IPs' => 'bi-globe2',
    'General' => 'bi-sliders',
    'Dial & CLI' => 'bi-signpost-2',
    'Limits' => 'bi-speedometer2',
    'Delayed Bye' => 'bi-hourglass-split',
    'Media' => 'bi-soundwave',
];
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($title) ?></h1>
        <p class="os-page-sub"><?= e($formSub) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach (($headerLinks ?? []) as $link): ?>
            <a class="btn <?= e((string) ($link['class'] ?? 'btn-outline-secondary')) ?> btn-sm" href="<?= e(url((string) $link['href'])) ?>">
                <?php if (!empty($link['icon'])): ?><i class="bi bi-<?= e((string) $link['icon']) ?> me-1"></i><?php endif; ?>
                <?= e((string) $link['label']) ?>
            </a>
        <?php endforeach; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url($base)) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>

<?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger"><?= e($errors['_form']) ?></div>
<?php endif; ?>

<?php
$sections = [];
foreach ($fields as $f) {
    $sec = (string) ($f['section'] ?? 'General');
    $sections[$sec][] = $f;
}
if ($peerIpLatency !== []) {
    $sections['IPs'] = [['_peer_ips' => true]];
}
$sectionIds = array_keys($sections);
$useTabs = count($sectionIds) > 1;
?>
<?php
$formAttrs = '';
if (($base ?? '') === '/sip-users') {
    $formAttrs .= ' data-os-sip-auth';
}
if (($base ?? '') === '/trunks') {
    $formAttrs .= ' data-os-trunk-auth';
}
$panelClass = 'os-panel col-12' . ($enhancedForm ? ' os-entity-form' : '');
?>
<?php if ($viewOnly): ?>
<div class="<?= e($panelClass) ?>"<?= $formAttrs ?>>
<?php else: ?>
<form method="post" action="<?= e(url($action)) ?>" class="<?= e($panelClass) ?>" novalidate<?= $formAttrs ?>>
<?php endif; ?>
    <div class="p-3 p-md-4<?= $enhancedForm ? ' os-entity-form-body' : '' ?>">
        <?php if (!$viewOnly): ?><?= csrf_field() ?><?php endif; ?>
        <?php if ($useTabs): ?>
            <ul class="nav nav-tabs os-form-tabs<?= $enhancedForm ? ' os-form-tabs--rich' : '' ?> mb-3" role="tablist">
                <?php foreach ($sectionIds as $i => $sec):
                    $tid = 'tab_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($sec));
                    $icon = $sectionIcons[$sec] ?? 'bi-folder2';
                    ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link<?= $i === 0 ? ' active' : '' ?>" id="<?= e($tid) ?>-btn" data-bs-toggle="tab" data-bs-target="#<?= e($tid) ?>" type="button" role="tab">
                            <?php if ($enhancedForm): ?><i class="bi <?= e($icon) ?> me-1"></i><?php endif; ?><?= e($sec) ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="tab-content">
        <?php endif; ?>

        <?php foreach ($sections as $sec => $secFields):
            $tid = 'tab_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($sec));
            $si = array_search($sec, $sectionIds, true);
            $hint = $sectionHints[$sec] ?? '';
            ?>
            <?php if ($useTabs): ?><div class="tab-pane fade<?= $si === 0 ? ' show active' : '' ?>" id="<?= e($tid) ?>" role="tabpanel"><?php endif; ?>
            <?php if ($enhancedForm && $hint !== ''): ?>
                <div class="os-form-section-intro">
                    <div class="os-form-section-kicker"><?= e($sec) ?></div>
                    <p class="os-form-section-lead mb-0"><?= e($hint) ?></p>
                </div>
            <?php endif; ?>
            <?php if ($sec === 'IPs'): ?>
                <div class="os-ip-latency">
                    <div class="os-ip-latency-grid">
                        <?php foreach ($peerIpLatency as $peer):
                            $online = !empty($peer['online']);
                            $ms = isset($peer['ms']) && is_numeric($peer['ms']) ? (int) $peer['ms'] : null;
                            $state = trim((string) ($peer['state'] ?? ''));
                            if ($online && $ms !== null && $ms > 0) {
                                $msCls = $ms < 80 ? 'ok' : ($ms < 200 ? 'warn' : 'bad');
                            } elseif ($online) {
                                $msCls = 'ok'; // IP AUTH / REACHABLE without ms
                            } else {
                                $msCls = 'off';
                            }
                            ?>
                            <div class="os-ip-latency-card os-ip-latency-card--<?= e($msCls) ?>">
                                <div class="os-ip-latency-top">
                                    <span class="os-presence-dot <?= $online ? 'os-presence-dot--on' : 'os-presence-dot--off' ?>" title="<?= e($state !== '' ? $state : ($online ? 'Online' : 'Offline')) ?>"></span>
                                    <span class="os-ip-latency-ip os-mono"><?= e((string) $peer['ip']) ?></span>
                                    <span class="os-ip-latency-port">:<?= e((string) ($peer['port'] ?? 5060)) ?></span>
                                </div>
                                <div class="os-ip-latency-ms">
                                    <?php if ($online && $ms !== null && $ms > 0): ?>
                                        <span class="os-mono"><?= e((string) $ms) ?> ms</span>
                                    <?php elseif ($online): ?>
                                        <span class="text-muted"><?= e($state !== '' ? $state : 'IP AUTH') ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">offline</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="os-ip-latency-note mb-0">Allowed IPs authorize INVITEs via ACL. Live (green) and ms appear only while OpenSIPS has an active REGISTER from that IP — not from OPTIONS or an open firewall. IP-only peers (no password) never REGISTER, so they stay offline here; use Dynamic (username/password) when you need Live/ms.</p>
                </div>
            <?php else: ?>
            <div class="row g-3<?= $useTabs ? ' mb-2' : '' ?><?= $enhancedForm ? ' os-form-fields' : '' ?>">
            <?php foreach ($secFields as $f):
                if (!empty($f['list_only']) || !empty($f['_peer_ips'])) {
                    continue;
                }
                $name = (string) $f['name'];
                $type = (string) ($f['type'] ?? 'text');
                if ($viewOnly && $type === 'password') {
                    continue; // never show password fields in read-only view
                }
                $readonly = $viewOnly || ($isEdit && !empty($f['readonly_on_edit']));
                $val = array_key_exists($name, $values) ? $values[$name] : ($f['default'] ?? '');
                if ($type === 'password') {
                    $val = '';
                }
                $id = 'f_' . $name;
                $err = $errors[$name] ?? null;
                $cls = 'form-control' . ($err ? ' is-invalid' : '');
                $wide = in_array($type, ['textarea', 'password', 'multiselect', 'trunk_waterfall', 'default_or_seconds'], true) || !empty($f['wide']);
                $addLabel = (string) ($f['add_label'] ?? ('+ Add ' . strtolower((string) ($f['label'] ?? 'item')) . '…'));
                $fieldWrap = $enhancedForm
                    ? ('os-field-tile' . ($wide ? ' os-field-tile--wide' : '') . ($err ? ' os-field-tile--error' : ''))
                    : '';
                ?>
                <div class="<?= $wide ? 'col-12' : 'col-12 col-md-6' ?>">
                    <div class="<?= e($fieldWrap) ?>">
                    <?php if ($type === 'trunk_waterfall'):
                        $wfMeta = $f['trunk_meta'] ?? [];
                        $wfOpts = $f['options'] ?? [];
                        $wfStatsWindow = (int) ($f['stats_window'] ?? 60);
                        $wfOn = [];
                        if (is_array($val)) {
                            $wfOn = array_values(array_filter(array_map('strval', $val)));
                        } else {
                            $wfOn = array_values(array_filter(array_map('trim', explode(',', (string) $val)), static fn ($x) => $x !== ''));
                        }
                        $wfOn = array_values(array_filter($wfOn, static fn ($c) => isset($wfOpts[$c]) || isset($wfMeta[$c])));
                        $wfOffRaw = (string) ($values['trunk_off_ids'] ?? '');
                        $wfOff = array_values(array_filter(array_map('trim', explode(',', $wfOffRaw)), static fn ($x) => $x !== '' && !in_array($x, $wfOn, true) && (isset($wfOpts[$x]) || isset($wfMeta[$x]))));
                        $wfUsed = array_merge($wfOn, $wfOff);
                        require __DIR__ . '/../routing/_waterfall.php';
                    elseif ($type === 'checkbox'): ?>
                        <div class="form-check mt-md-2">
                            <input class="form-check-input" type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1"<?= !empty($val) ? ' checked' : '' ?><?= $readonly ? ' disabled' : '' ?>>
                            <label class="form-check-label" for="<?= e($id) ?>"><?= e($f['label']) ?></label><?= !empty($f['help']) ? ' ' . field_help($f['help']) : '' ?>
                        </div>
                    <?php else: ?>
                        <label class="form-label fw-semibold" for="<?= e($id) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="text-danger">*</span>' : '' ?><?= !empty($f['help']) ? ' ' . field_help($f['help']) : '' ?></label>
                        <?php if ($type === 'default_or_seconds'):
                            $sysSec = (int) ($f['system_seconds'] ?? 7200);
                            $curSec = (int) ((string) $val !== '' ? $val : '0');
                            $isDefault = $curSec <= 0;
                            $minSec = (int) ($f['min'] ?? 60);
                            $maxSec = (int) ($f['max'] ?? 604800);
                            ?>
                            <div class="os-duration<?= $err ? ' is-invalid' : '' ?>" data-os-duration>
                                <select class="form-select mb-2" id="<?= e($id) ?>_mode" data-duration-mode<?= $readonly ? ' disabled' : '' ?>>
                                    <option value="default"<?= $isDefault ? ' selected' : '' ?>>Default (system · <?= e((string) $sysSec) ?>s)</option>
                                    <option value="custom"<?= !$isDefault ? ' selected' : '' ?>>Custom seconds</option>
                                </select>
                                <input type="hidden" name="<?= e($name) ?>" id="<?= e($id) ?>" value="<?= e($isDefault ? '0' : (string) $curSec) ?>" data-duration-value>
                                <input class="form-control<?= $err ? ' is-invalid' : '' ?>" type="text" inputmode="numeric" id="<?= e($id) ?>_custom" data-duration-custom
                                       value="<?= e($isDefault ? '' : (string) $curSec) ?>" placeholder="e.g. 3600"<?= $readonly ? ' readonly' : '' ?><?= $isDefault ? ' disabled' : '' ?>
                                       min="<?= $minSec ?>" max="<?= $maxSec ?>">
                                <div class="form-text" data-duration-hint><?= $isDefault
                                    ? 'Uses Settings → Prepaid hard max call duration.'
                                    : ('Enter ' . $minSec . '–' . $maxSec . ' seconds.') ?></div>
                            </div>
                        <?php elseif ($type === 'select'): ?>
                            <select class="form-select<?= $err ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $readonly ? ' disabled' : '' ?>>
                                <?php if (empty($f['required']) && !isset($f['options'][''])): ?><option value="">—</option><?php endif; ?>
                                <?php foreach ($f['options'] as $k => $label):
                                    $optPlan = $f['option_data'][$k]['plan'] ?? $f['option_data'][(string) $k]['plan'] ?? null;
                                    ?>
                                    <option value="<?= e($k) ?>"<?= $optPlan !== null ? ' data-plan="' . e((string) $optPlan) . '"' : '' ?><?= ((string) $val === (string) $k) ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($type === 'multiselect'):
                            $opts = $f['options'] ?? [];
                            $selected = [];
                            if (is_array($val)) {
                                $selected = array_values(array_filter(array_map('strval', $val)));
                            } else {
                                $selected = array_values(array_filter(array_map('trim', explode(',', (string) $val)), static fn ($x) => $x !== ''));
                            }
                            $selected = array_values(array_filter($selected, static fn ($c) => isset($opts[$c])));
                            ?>
                            <div class="os-ms<?= $err ? ' is-invalid' : '' ?>" data-os-multiselect data-name="<?= e($name) ?>"<?= $readonly ? ' data-readonly="1"' : '' ?>>
                                <input type="hidden" name="<?= e($name) ?>" id="<?= e($id) ?>" value="<?= e(implode(',', $selected)) ?>">
                                <div class="os-ms-chips" data-chips>
                                    <?php foreach ($selected as $code): ?>
                                        <span class="os-ms-chip" data-value="<?= e($code) ?>">
                                            <span class="os-ms-chip-label"><?= e($opts[$code] ?? $code) ?></span>
                                            <?php if (!$readonly): ?>
                                                <button type="button" class="os-ms-chip-x" data-remove aria-label="Remove <?= e($code) ?>">&times;</button>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!$readonly): ?>
                                    <select class="form-select form-select-sm os-ms-add" data-add aria-label="<?= e($addLabel) ?>">
                                        <option value=""><?= e($addLabel) ?></option>
                                        <?php foreach ($opts as $k => $label): ?>
                                            <option value="<?= e($k) ?>"<?= in_array((string) $k, $selected, true) ? ' disabled' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <template data-chip-tpl>
                                    <span class="os-ms-chip" data-value="">
                                        <span class="os-ms-chip-label"></span>
                                        <button type="button" class="os-ms-chip-x" data-remove aria-label="Remove">&times;</button>
                                    </span>
                                </template>
                            </div>
                        <?php elseif ($type === 'textarea'): ?>
                            <textarea class="<?= e($cls) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" rows="<?= e((string) ($f['rows'] ?? 3)) ?>"<?= isset($f['max']) ? ' maxlength="' . (int) $f['max'] . '"' : '' ?> placeholder="<?= e((string) ($f['placeholder'] ?? '')) ?>"><?= e($val) ?></textarea>
                        <?php else:
                            $htmlType = match ($type) { 'email' => 'email', 'password' => 'password', default => 'text' };
                            $inputmode = in_array($type, ['int', 'prefix'], true) ? 'numeric' : ($type === 'number' ? 'decimal' : 'text');
                            ?>
                            <input class="<?= e($cls) ?>" type="<?= e($htmlType) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($val) ?>"
                                   inputmode="<?= e($inputmode) ?>" placeholder="<?= e((string) ($f['placeholder'] ?? '')) ?>"<?= isset($f['max']) && !in_array($type, ['int', 'number'], true) ? ' maxlength="' . (int) $f['max'] . '"' : '' ?>
                                   <?= $readonly ? ' readonly' : '' ?><?= $type === 'password' ? ' autocomplete="new-password"' : '' ?>>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($err): ?><div class="invalid-feedback d-block"><?= e($err) ?></div><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($useTabs): ?></div><?php endif; ?>
        <?php endforeach; ?>

        <?php if ($useTabs): ?></div><?php endif; ?>
    </div>
    <div class="border-top px-3 px-md-4 py-3 d-flex flex-wrap gap-2<?= $enhancedForm ? ' os-entity-form-foot' : ' bg-light' ?> rounded-bottom">
        <?php if (!$viewOnly): ?>
            <button type="submit" class="btn btn-primary" data-once><i class="bi bi-check2 me-1"></i>Save</button>
            <a class="btn btn-outline-secondary" href="<?= e(url($base)) ?>">Cancel</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= e(url($base)) ?>"><i class="bi bi-arrow-left me-1"></i>Back to list</a>
        <?php endif; ?>
    </div>
<?php if ($viewOnly): ?></div><?php else: ?></form><?php endif; ?>
<?php if (!empty($afterFormPartial) && $existing !== null && preg_match('#^[a-z0-9_/\-]+$#i', (string) $afterFormPartial)) { require __DIR__ . '/../' . $afterFormPartial . '.php'; } ?>
<?php
$belowFormPartial = (string) ($belowFormPartial ?? '');
if ($belowFormPartial !== '' && preg_match('#^[a-z0-9_/\-]+$#i', $belowFormPartial)) {
    $belowFormFile = __DIR__ . '/../' . $belowFormPartial . '.php';
    if (is_file($belowFormFile)) {
        require $belowFormFile;
    }
}
?>
