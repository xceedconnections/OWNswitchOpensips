<div class="os-panel p-3 p-md-4 mt-3">
    <h2 class="h6 mb-1">Add many from Excel or CSV</h2>
    <p class="text-muted small mb-3">Upload a spreadsheet to create or update area codes. Use two columns: the dial code (include the country digit, e.g. 1213) and the city name. A title row is optional. Comma, tab, or Excel files are accepted. Headings such as Area Code, Description, or City are recognized. An existing code is updated.</p>
    <form method="post" action="<?= e(url('/area-codes/import/upload')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="quick" value="1">
        <div class="col-12 col-md-8">
            <label class="form-label fw-semibold" for="area_code_file">File (CSV or Excel)</label>
            <input type="file" id="area_code_file" name="file" class="form-control" accept=".csv,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        </div>
        <div class="col-12 col-md-4">
            <button class="btn btn-primary w-100" type="submit" data-once><i class="bi bi-upload me-1"></i>Import codes</button>
        </div>
    </form>
    <div class="d-flex flex-wrap gap-3 mt-3 small">
        <a href="<?= e(url('/area-codes/export?template=1')) ?>"><i class="bi bi-download me-1"></i>CSV template</a>
        <a href="<?= e(url('/area-codes/export?format=xlsx&template=1')) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Excel template</a>
    </div>
</div>
