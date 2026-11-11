<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Support\DocsCatalog;

/** In-portal operator documentation (Settings → Documentation). */
final class DocsController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $items = DocsCatalog::index();
        $groups = [];
        foreach ($items as $item) {
            $groups[$item['group']][] = $item;
        }
        return $this->view('docs/index', [
            'title' => 'Documentation',
            'groups' => $groups,
        ]);
    }

    public function show(Request $r, array $p = []): Response
    {
        $slug = strtolower(trim((string) ($p['slug'] ?? '')));
        $article = DocsCatalog::get($slug);
        if ($article === null) {
            return $this->notFound();
        }
        return $this->view('docs/show', [
            'title' => $article['title'],
            'slug' => $slug,
            'article' => $article,
            'toc' => DocsCatalog::index(),
        ]);
    }
}
