<?php
/** @var ?array $cert @var array $errors @var array $data @var ?string $resolvedX5u @var bool|null $canWrite */
$isEdit = $cert !== null;
$canWrite = $canWrite ?? true;
$viewOnly = !$canWrite;
$action = $isEdit
    ? url('/settings/stir-shaken/' . (int) $cert['id'])
    : url('/settings/stir-shaken');
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($title) ?></h1>
        <p class="os-page-sub">EC P-256 certificate + private key used to sign outbound SHAKEN Identity headers</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings/stir-shaken')) ?>">Back</a>
</div>
<?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger"><?= e((string) $errors['_form']) ?></div>
<?php endif; ?>
<form method="post" action="<?= e($action) ?>" class="os-panel col-12 col-lg-9 p-3 p-md-4">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="name">Name</label>
        <input class="form-control" id="name" name="name" required maxlength="120" value="<?= e((string) ($data['name'] ?? '')) ?>"<?= $viewOnly ? ' disabled' : '' ?>>
        <div class="form-text">Shown in the SIP user StirShaken certificate dropdown.</div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold" for="status">Status</label>
            <select class="form-select" id="status" name="status"<?= $viewOnly ? ' disabled' : '' ?>>
                <option value="active"<?= (($data['status'] ?? 'active') === 'active') ? ' selected' : '' ?>>Active</option>
                <option value="disabled"<?= (($data['status'] ?? '') === 'disabled') ? ' selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold" for="origid">Origid</label>
            <input class="form-control" id="origid" name="origid" maxlength="64" value="<?= e((string) ($data['origid'] ?? '')) ?>" placeholder="Auto-generated UUID if blank"<?= $viewOnly ? ' disabled' : '' ?>>
            <div class="form-text">PASSporT <code>origid</code> claim (UUID). Leave blank on create to auto-generate.</div>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="x5u_url">x5u URL (optional)</label>
        <input class="form-control" id="x5u_url" name="x5u_url" maxlength="512" value="<?= e((string) ($data['x5u_url'] ?? '')) ?>" placeholder="https://certs.example.com/sp.pem"<?= $viewOnly ? ' disabled' : '' ?>>
        <div class="form-text">
            Public URL where carriers fetch this certificate.
            <?php if (!empty($resolvedX5u)): ?>
                Current: <code class="user-select-all"><?= e($resolvedX5u) ?></code>
            <?php else: ?>
                Leave blank to use the portal URL <code>/stir/cert/{token}.pem</code> (requires a reachable <code>APP_URL</code>).
            <?php endif; ?>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="notes">Notes</label>
        <input class="form-control" id="notes" name="notes" maxlength="255" value="<?= e((string) ($data['notes'] ?? '')) ?>"<?= $viewOnly ? ' disabled' : '' ?>>
    </div>
    <?php if ($isEdit): ?>
        <div class="alert alert-secondary small mb-3">
            <div><strong>Issuer:</strong> <?= e((string) ($cert['issuer'] ?? '—')) ?></div>
            <div><strong>Subject:</strong> <?= e((string) ($cert['subject'] ?? '—')) ?></div>
            <div><strong>Valid:</strong>
                <?= e(!empty($cert['valid_from']) ? (string) $cert['valid_from'] : '—') ?>
                →
                <?= e(!empty($cert['valid_to']) ? (string) $cert['valid_to'] : '—') ?>
            </div>
            <div class="mt-1">Paste new PEM below only when replacing the certificate or key. Leave blank to keep the current materials.</div>
        </div>
    <?php endif; ?>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="cert_pem">Certificate PEM<?= $isEdit ? '' : ' *' ?></label>
        <textarea class="form-control font-monospace" id="cert_pem" name="cert_pem" rows="8"<?= $viewOnly ? ' disabled' : '' ?><?= $isEdit ? '' : ' required' ?> placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"><?= e((string) ($data['cert_pem'] ?? '')) ?></textarea>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="private_key_pem">Private key PEM<?= $isEdit ? '' : ' *' ?></label>
        <textarea class="form-control font-monospace" id="private_key_pem" name="private_key_pem" rows="8"<?= $viewOnly ? ' disabled' : '' ?><?= $isEdit ? '' : ' required' ?> placeholder="-----BEGIN EC PRIVATE KEY-----&#10;...&#10;-----END EC PRIVATE KEY-----"><?= e((string) ($data['private_key_pem'] ?? '')) ?></textarea>
        <div class="form-text">Stored encrypted at rest. Must be the EC P-256 key that matches the certificate.</div>
    </div>
    <?php if (!$viewOnly): ?>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit" data-once>Save</button>
            <?php if ($isEdit): ?>
                <button class="btn btn-outline-danger" type="submit" form="stir-delete" data-once onclick="return confirm('Delete this certificate? SIP users using it will have STIR/SHAKEN cleared.');">Delete</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</form>
<?php if ($isEdit && !$viewOnly): ?>
<form id="stir-delete" method="post" action="<?= e(url('/settings/stir-shaken/' . (int) $cert['id'] . '/delete')) ?>" class="d-none">
    <?= csrf_field() ?>
</form>
<?php endif; ?>
