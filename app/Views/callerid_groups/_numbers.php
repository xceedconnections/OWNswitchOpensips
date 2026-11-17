<?php
/** @var array $group @var array $numberResult @var array $numberQuery @var string $q @var bool $canWrite @var string $base */
$gid = (int) $group['id'];
$canWrite = !empty($canWrite);
$q = (string) ($q ?? '');
$edit = $base . '/' . $gid . '/edit';
$result = $numberResult;
$query = $numberQuery;
?>
<div class="os-panel col-12 mt-3">
    <div class="p-3 p-md-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h2 class="h6 fw-semibold mb-1">CLI numbers <span class="os-count"><?= e((string) $result['total']) ?></span></h2>
                <p class="small text-muted mb-0">Numbers in this group are rotated on each call. A CLI that shares the longest starting digits with the dialled number is preferred. When this group is selected on a trunk, that CLI is forced to the vendor and written on the CDR.</p>
            </div>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url($base . '/' . $gid . '/export')) ?>"><i class="bi bi-download me-1"></i>Export CSV</a>
        </div>

        <?php if ($canWrite): ?>
        <form method="post" action="<?= e(url($base . '/' . $gid . '/numbers')) ?>" class="row g-2 align-items-end mb-3">
            <?= csrf_field() ?>
            <div class="col-6 col-md-4">
                <label class="form-label small mb-0" for="cgn_cli">CLI number</label>
                <input class="form-control form-control-sm os-mono" id="cgn_cli" name="cli_number" maxlength="32" required placeholder="12135550123" autocomplete="off">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0" for="cgn_name">Name</label>
                <input class="form-control form-control-sm" id="cgn_name" name="name" maxlength="64" placeholder="optional">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="cgn_status">Status</label>
                <select class="form-select form-select-sm" id="cgn_status" name="status">
                    <option value="active">Active</option>
                    <option value="disabled">Disabled</option>
                </select>
            </div>
            <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm" data-once>Add</button></div>
        </form>

        <form method="post" action="<?= e(url($base . '/' . $gid . '/import')) ?>" enctype="multipart/form-data" class="border rounded p-3 mb-3 bg-light">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-12 col-lg-6">
                    <label class="form-label small mb-0" for="cgn_text">Bulk text</label>
                    <textarea class="form-control form-control-sm os-mono" id="cgn_text" name="cli_text" rows="6" placeholder="12135550100&#10;12135550101, West coast&#10;12135550102"></textarea>
                    <div class="form-text">One CLI per line. Optional name after a comma, tab, or space. Lines starting with # are ignored.</div>
                </div>
                <div class="col-12 col-lg-6">
                    <label class="form-label small mb-0" for="cgn_file">CSV or Excel</label>
                    <input class="form-control form-control-sm" id="cgn_file" type="file" name="file" accept=".csv,.txt,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                    <div class="form-text">Columns <code>cli_number</code> (or the first column) and optional <code>name</code>. A file of bare numbers works too. Max 8 MB, 20,000 numbers.</div>
                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <label class="form-label small mb-0" for="cgn_mode">Action</label>
                            <select class="form-select form-select-sm" id="cgn_mode" name="mode">
                                <option value="add">Add numbers</option>
                                <option value="delete">Delete these numbers</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0" for="cgn_bulk_status">Status for adds</label>
                            <select class="form-select form-select-sm" id="cgn_bulk_status" name="status">
                                <option value="active">Active</option>
                                <option value="disabled">Disabled</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2" data-once>Upload</button>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <form method="get" action="<?= e(url($edit)) ?>" class="row g-2 align-items-end mb-3">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-0" for="cgn_q">Search this group</label>
                <input type="search" class="form-control form-control-sm" id="cgn_q" name="q" value="<?= e($q) ?>" placeholder="CLI or name" maxlength="64">
            </div>
            <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Search</button></div>
            <?php if ($q !== ''): ?>
                <div class="col-auto"><a class="btn btn-link btn-sm" href="<?= e(url($edit)) ?>">Clear</a></div>
            <?php endif; ?>
        </form>

        <?php if ((int) $result['total'] === 0): ?>
            <div class="text-muted small"><?= $q !== '' ? 'No numbers match that search.' : 'No numbers yet.' ?></div>
        <?php else: ?>
        <form method="post" action="<?= e(url($base . '/' . $gid . '/numbers/bulk-delete')) ?>" data-submitter-confirm>
            <?= csrf_field() ?>
            <div class="os-table-scroll">
                <table class="os-table">
                    <thead>
                    <tr>
                        <?php if ($canWrite): ?>
                            <th scope="col" style="width:2rem"><input type="checkbox" data-check-all=".cgn-check" aria-label="Select all on this page"></th>
                        <?php endif; ?>
                        <th scope="col">CLI number</th>
                        <th scope="col">Name</th>
                        <th scope="col">Status</th>
                        <?php if ($canWrite): ?><th scope="col" class="os-col-actions">Actions</th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($result['rows'] as $n): ?>
                        <tr>
                            <?php if ($canWrite): ?>
                                <td><input class="cgn-check" type="checkbox" name="ids[]" value="<?= e((string) $n['id']) ?>" aria-label="Select <?= e((string) $n['cli_number']) ?>"></td>
                            <?php endif; ?>
                            <td class="os-mono"><?= e((string) $n['cli_number']) ?></td>
                            <td><?= e((string) ($n['name'] ?? '')) !== '' ? e((string) $n['name']) : '<span class="text-muted">—</span>' ?></td>
                            <td><span class="badge text-bg-<?= $n['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst((string) $n['status'])) ?></span></td>
                            <?php if ($canWrite): ?>
                            <td class="os-col-actions">
                                <button type="submit" class="btn btn-outline-danger btn-sm" formaction="<?= e(url($base . '/' . $gid . '/numbers/' . (int) $n['id'] . '/delete')) ?>" formmethod="post" data-confirm="Remove this number?" aria-label="Delete"><i class="bi bi-trash"></i></button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canWrite): ?>
                <div class="pt-2">
                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Remove the selected numbers?">Delete selected</button>
                </div>
            <?php endif; ?>
            <?php include __DIR__ . '/../partials/pagination.php'; ?>
        </form>
        <?php endif; ?>
    </div>
</div>
