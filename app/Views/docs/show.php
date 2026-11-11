<?php
/** @var string $slug @var array $article @var list<array{slug:string,title:string,summary:string,group:string}> $toc */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($article['title']) ?></h1>
        <p class="os-page-sub"><?= e($article['summary']) ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/docs')) ?>"><i class="bi bi-arrow-left me-1"></i>All docs</a>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="os-panel p-3 p-md-4 os-docs-body">
            <?php foreach ($article['sections'] as $sec): ?>
                <h2><?= e($sec['heading']) ?></h2>
                <?= $sec['html'] ?>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="os-panel p-3 p-md-4">
            <h2 class="h6 text-muted text-uppercase mb-3">Topics</h2>
            <div class="list-group list-group-flush os-docs-toc">
                <?php foreach ($toc as $item): ?>
                    <a class="list-group-item list-group-item-action px-0<?= $item['slug'] === $slug ? ' active' : '' ?>"
                       href="<?= e(url('/docs/' . $item['slug'])) ?>"><?= e($item['title']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
