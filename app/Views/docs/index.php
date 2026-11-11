<?php
/** @var array<string,list<array{slug:string,title:string,summary:string,group:string}>> $groups */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Documentation</h1>
        <p class="os-page-sub">Operator guide for Ownswitch options — trunks, SIP users, billing, Delayed Bye, and more</p>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($groups as $group => $items): ?>
        <div class="col-12">
            <div class="os-panel p-3 p-md-4">
                <h2 class="h5 mb-3"><?= e($group) ?></h2>
                <div class="list-group list-group-flush os-docs-toc">
                    <?php foreach ($items as $item): ?>
                        <a class="list-group-item list-group-item-action px-0" href="<?= e(url('/docs/' . $item['slug'])) ?>">
                            <div class="fw-semibold"><?= e($item['title']) ?></div>
                            <div class="small text-muted"><?= e($item['summary']) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    Tip: on every edit form, click the <i class="bi bi-question-circle"></i> next to a field for a popup description.
</p>
