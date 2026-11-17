<?php
/** @var string $cliQuery @var ?list<array<string,mixed>> $cliHits @var bool $cliHitsTruncated @var string $base */
$cliQuery = (string) ($cliQuery ?? '');
$cliHitsTruncated = !empty($cliHitsTruncated);
?>
<form method="get" action="<?= e(url($base)) ?>" class="os-panel p-3 mb-3">
    <label class="form-label small mb-1" for="cli">Find a CallerID in any group</label>
    <div class="row g-2 align-items-center">
        <div class="col-12 col-md-6 col-lg-4">
            <input type="search" class="form-control form-control-sm os-mono" id="cli" name="cli" value="<?= e($cliQuery) ?>" placeholder="12135550100 or a name" maxlength="64">
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-primary btn-sm" type="submit">Search all groups</button>
        </div>
        <?php if ($cliQuery !== ''): ?>
            <div class="col-auto"><a class="btn btn-link btn-sm" href="<?= e(url($base)) ?>">Clear</a></div>
        <?php endif; ?>
    </div>
    <?php if (is_array($cliHits)): ?>
        <?php if ($cliHits === []): ?>
            <p class="small text-muted mb-0 mt-2">No CallerID matches “<?= e($cliQuery) ?>”.</p>
        <?php else: ?>
            <p class="small text-muted mb-2 mt-2"><?= e((string) count($cliHits)) ?> match(es)<?= $cliHitsTruncated ? ' (showing the first 200)' : '' ?>.</p>
            <div class="os-table-scroll">
                <table class="os-table">
                    <thead>
                    <tr>
                        <th scope="col">CLI number</th>
                        <th scope="col">Name</th>
                        <th scope="col">Group</th>
                        <th scope="col">Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cliHits as $hit): ?>
                        <tr>
                            <td class="os-mono"><?= e((string) $hit['cli_number']) ?></td>
                            <td><?= e((string) ($hit['name'] ?? '')) !== '' ? e((string) $hit['name']) : '<span class="text-muted">—</span>' ?></td>
                            <td><a href="<?= e(url($base . '/' . (int) $hit['group_id'] . '/edit')) ?>"><?= e((string) $hit['group_name']) ?></a></td>
                            <td><span class="badge text-bg-<?= ($hit['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst((string) ($hit['status'] ?? ''))) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</form>
