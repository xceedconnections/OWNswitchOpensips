<?php

declare(strict_types=1);

use Ownswitch\App;

if (!function_exists('e')) {
    /** HTML-escape for output. ALL view output of dynamic data must go through this. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_url')) {
    function base_url(): string
    {
        return App::request()?->basePath ?? '';
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return base_url() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $rel = 'assets/' . ltrim($path, '/');
        $file = OWNSWITCH_ROOT . '/public/' . $rel;
        $v = is_file($file) ? (string) filemtime($file) : (string) time();
        return base_url() . '/' . $rel . '?v=' . rawurlencode($v);
    }
}

if (!function_exists('switch_logo_url')) {
    /** Authenticated URL for the uploaded switch logo, or null when none is stored. */
    function switch_logo_url(): ?string
    {
        try {
            $rel = (new \Ownswitch\Services\Settings\SettingsService(\Ownswitch\App::db()))->get('switch_logo', '');
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($rel) || $rel === '') {
            return null;
        }
        $name = basename(str_replace('\\', '/', $rel));
        if (!preg_match('/^logo\.(png|jpe?g|webp|svg)$/', $name)) {
            return null;
        }
        $abs = \Ownswitch\App::basePath('storage/uploads/branding/' . $name);
        if (!is_file($abs)) {
            return null;
        }
        $mtime = @filemtime($abs) ?: time();
        return url('/branding/logo') . '?v=' . $mtime;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return App::session()->csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="' . e(App::config('security.csrf.field', '_csrf')) . '" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('old')) {
    /** Previously submitted form value (flash-persisted on validation failure). */
    function old(string $key, mixed $default = ''): mixed
    {
        $old = App::session()->get('_old', []);
        return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
    }
}

if (!function_exists('current_user')) {
    function current_user(): ?array
    {
        return App::request()?->attr('user');
    }
}

if (!function_exists('has_role')) {
    function has_role(string ...$roles): bool
    {
        $u = current_user();
        return $u !== null && in_array($u['role'], $roles, true);
    }
}

if (!function_exists('feature_on')) {
    /** Whether a dashboard/menu feature is enabled for the current user (vendor always true). */
    function feature_on(string $key): bool
    {
        static $svc = null;
        try {
            $svc ??= new \Ownswitch\Services\Access\FeatureVisibilityService(\Ownswitch\App::db());
            return $svc->canSee(current_user(), $key);
        } catch (\Throwable) {
            return true;
        }
    }
}

if (!function_exists('fmt_money')) {
    /** Display formatting only (string-based; never used for settlement). */
    function fmt_money(?string $v, int $scale = 4): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        return bcadd($v, '0', $scale);
    }
}

if (!function_exists('fmt_duration')) {
    function fmt_duration(int|string|null $sec): string
    {
        $sec = max(0, (int) $sec);
        return sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
    }
}

if (!function_exists('nav_active')) {
    function nav_active(string $prefix): string
    {
        $path = App::request()?->path ?? '/';
        if ($prefix === '/') {
            return $path === '/' ? ' active' : '';
        }
        // Exact match, or child path — but avoid /routing lighting up for /routing/simulate
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            // Prefer more specific sibling: /routing should not match /routing/simulate when that item exists separately
            if ($prefix === '/routing' && str_starts_with($path, '/routing/simulate')) {
                return '';
            }
            return ' active';
        }
        return '';
    }
}

if (!function_exists('user_timezone')) {
    function user_timezone(): string
    {
        return \Ownswitch\Support\Timezone::fromUser(current_user());
    }
}

if (!function_exists('fmt_local')) {
    /** Format a UTC datetime for the current user's timezone. */
    function fmt_local(?string $utc, string $format = 'Y-m-d H:i:s'): string
    {
        return \Ownswitch\Support\Timezone::format($utc, user_timezone(), $format);
    }
}

if (!function_exists('tz_label')) {
    function tz_label(?string $tz = null): string
    {
        $tz = \Ownswitch\Support\Timezone::normalize($tz ?? user_timezone());
        return \Ownswitch\Support\Timezone::label($tz);
    }
}

if (!function_exists('field_help')) {
    /**
     * Bootstrap popover "?" next to a form label. Pass the field help / description text.
     * Empty string returns nothing.
     */
    function field_help(mixed $help): string
    {
        $text = trim((string) ($help ?? ''));
        if ($text === '') {
            return '';
        }
        return '<button type="button" class="os-field-help" tabindex="0"'
            . ' data-bs-toggle="popover" data-bs-trigger="focus"'
            . ' data-bs-placement="top" data-bs-html="false"'
            . ' data-bs-custom-class="os-help-popover"'
            . ' title="About this field"'
            . ' data-bs-content="' . e($text) . '"'
            . ' aria-label="Field help"'
            . ' onclick="event.preventDefault();event.stopPropagation();">'
            . '<i class="bi bi-question-circle" aria-hidden="true"></i>'
            . '</button>';
    }
}

if (!function_exists('sort_link')) {
    /**
     * Sortable column header that preserves existing query filters and toggles asc/desc.
     *
     * @param array<string,mixed> $query
     */
    function sort_link(string $basePath, array $query, string $column, string $label, ?string $currentSort = null, ?string $currentDir = null): string
    {
        $currentSort = $currentSort ?? (is_string($query['sort'] ?? null) ? (string) $query['sort'] : '');
        $currentDir = strtolower($currentDir ?? (is_string($query['dir'] ?? null) ? (string) $query['dir'] : 'desc'));
        if ($currentDir !== 'asc' && $currentDir !== 'desc') {
            $currentDir = 'desc';
        }
        $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
        if ($currentSort !== $column) {
            $nextDir = 'asc';
        }
        $q = [];
        foreach ($query as $k => $v) {
            if (!is_string($k) || !(is_string($v) || is_int($v) || is_float($v))) {
                continue;
            }
            if ($k === 'page') {
                continue;
            }
            $q[$k] = (string) $v;
        }
        $q['sort'] = $column;
        $q['dir'] = $nextDir;
        $href = url($basePath) . '?' . http_build_query($q);
        if ($currentSort === $column) {
            $icon = $currentDir === 'asc'
                ? '<i class="bi bi-sort-up-alt ms-1" aria-hidden="true"></i>'
                : '<i class="bi bi-sort-down-alt ms-1" aria-hidden="true"></i>';
            $active = ' is-active';
        } else {
            $icon = '<i class="bi bi-arrow-down-up ms-1 os-sort-muted" aria-hidden="true"></i>';
            $active = '';
        }
        return '<a class="os-sort-link' . $active . '" href="' . e($href) . '">' . e($label) . $icon . '</a>';
    }
}

if (!function_exists('asr_pill')) {
    /** Colored ASR badge for report tables / heroes. */
    function asr_pill(mixed $asr): string
    {
        $n = (float) $asr;
        $cls = $n >= 60.0 ? 'high' : ($n >= 30.0 ? 'mid' : 'low');
        return '<span class="os-metric-pill os-metric-pill--asr os-metric-pill--' . $cls . '" title="Answer Seizure Ratio">'
            . '<span class="os-metric-pill-k">ASR</span>'
            . '<span class="os-metric-pill-v">' . e(number_format($n, 2)) . '%</span></span>';
    }
}

if (!function_exists('acd_pill')) {
    /** Colored ACD badge (average call duration in seconds). */
    function acd_pill(mixed $acd): string
    {
        $n = (float) $acd;
        $cls = $n >= 120.0 ? 'high' : ($n >= 30.0 ? 'mid' : 'low');
        return '<span class="os-metric-pill os-metric-pill--acd os-metric-pill--' . $cls . '" title="Average Call Duration">'
            . '<span class="os-metric-pill-k">ACD</span>'
            . '<span class="os-metric-pill-v">' . e(number_format($n, 1)) . 's</span></span>';
    }
}
