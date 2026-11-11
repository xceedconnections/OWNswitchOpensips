<?php /** @var list<array<string,mixed>> $certs @var bool $canWrite */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">STIR/SHAKEN Cert.</h1>
        <p class="os-page-sub">Upload SHAKEN certificates, then assign one to a SIP user with attestation A, B, or C. Signed Identity headers are added on outbound vendor legs when configured.</p>
    </div>
    <?php if (!empty($canWrite)): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/settings/stir-shaken/create')) ?>"><i class="bi bi-plus-lg me-1"></i>New certificate</a>
    <?php endif; ?>
</div>
<div class="os-table-wrap">
<?php if ($certs === []): ?>
    <div class="os-empty">
        <div class="os-empty-title">No STIR/SHAKEN certificates</div>
        <div>Add a certificate and private key, then select it on a SIP user under the StirShaken tab.</div>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 os-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Issuer</th>
                    <th>Subject</th>
                    <th>Valid From</th>
                    <th>Valid To</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($certs as $c): ?>
                <?php
                $editUrl = url('/settings/stir-shaken/' . (int) $c['id'] . '/edit');
                $vf = !empty($c['valid_from']) ? date('M j, Y', strtotime((string) $c['valid_from'])) : '—';
                $vt = !empty($c['valid_to']) ? date('M j, Y', strtotime((string) $c['valid_to'])) : '—';
                ?>
                <tr>
                    <td><a href="<?= e($editUrl) ?>"><?= e((string) $c['name']) ?></a></td>
                    <td><?= e((string) ($c['issuer'] ?? '—')) ?></td>
                    <td><?= e((string) ($c['subject'] ?? '—')) ?></td>
                    <td><?= e($vf) ?></td>
                    <td><?= e($vt) ?></td>
                    <td><span class="badge text-bg-<?= ($c['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= ($c['status'] ?? '') === 'active' ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-end">
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e($editUrl) ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
