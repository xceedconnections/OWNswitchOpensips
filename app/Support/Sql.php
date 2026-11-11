<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** SQL identifier helpers — never interpolate untrusted input into SQL. */
final class Sql
{
    /**
     * Whitelist a column/table identifier. Returns $fallback when not allowed.
     *
     * @param list<string> $allowed
     */
    public static function ident(string $candidate, array $allowed, string $fallback): string
    {
        $candidate = trim($candidate);
        return in_array($candidate, $allowed, true) ? $candidate : $fallback;
    }

    /** Only ASC/DESC. */
    public static function dir(string $dir): string
    {
        return strtolower(trim($dir)) === 'asc' ? 'ASC' : 'DESC';
    }

    /**
     * Escape LIKE wildcards in a bound parameter value (still use prepared statements).
     */
    public static function likeEscape(string $value): string
    {
        return addcslashes($value, "%_\\");
    }
}
