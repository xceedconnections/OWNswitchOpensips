<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Multi-number CDR search: comma/space-separated tokens with flexible digit matching.
 *
 * Stored 12138007447 matches searches like 1213800, 2138007447, +2138007447, 02138007447.
 */
final class NumberSearch
{
    /**
     * Split free-text into unique digit tokens.
     *
     * @return list<string>
     */
    public static function tokens(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;|]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $digits = Destination::normalize($part);
            if ($digits === '') {
                $digits = preg_replace('/\D+/', '', $part) ?? '';
            }
            if ($digits !== '') {
                $out[] = $digits;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Equivalent digit forms for one token (trunk 0, country code, national).
     *
     * @return list<string>
     */
    public static function variants(string $digits): array
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if ($digits === '') {
            return [];
        }
        $out = [$digits];
        $stripped = ltrim($digits, '0');
        if ($stripped !== '' && $stripped !== $digits) {
            $out[] = $stripped;
        }
        foreach ([$digits, $stripped] as $base) {
            if ($base === '') {
                continue;
            }
            $len = strlen($base);
            if ($len === 11 && str_starts_with($base, '1')) {
                $out[] = substr($base, 1);
            }
            if ($len === 10) {
                $out[] = '1' . $base;
            }
            // Longer E.164: also try without a leading country-code digit when remaining looks local.
            if ($len >= 11 && $len <= 15) {
                $out[] = substr($base, 1);
            }
        }
        $out = array_values(array_unique(array_filter($out, static fn ($v) => $v !== '')));
        // Prefer longer variants first (more selective LIKE patterns).
        usort($out, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        return $out;
    }

    /**
     * SQL expression that strips common non-digits from a string column.
     */
    public static function digitsExpr(string $columnSql): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL({$columnSql},''),'+',''),' ',''),'-',''),'(',''),')','')";
    }

    /**
     * Build an OR clause matching any token against any column expression.
     *
     * @param list<string> $columnExprs Already-safe SQL expressions (digit-ish)
     * @return array{0:?string,1:list<string>} [sql|null, params]
     */
    public static function matchClause(array $columnExprs, string $raw): array
    {
        $tokens = self::tokens($raw);
        if ($tokens === [] || $columnExprs === []) {
            return [null, []];
        }
        $tokenGroups = [];
        $params = [];
        foreach ($tokens as $token) {
            $ors = [];
            foreach (self::variants($token) as $variant) {
                $esc = Sql::likeEscape($variant);
                $len = strlen($variant);
                foreach ($columnExprs as $col) {
                    // Prefix: 1213800 → 12138007447
                    $ors[] = "{$col} LIKE ?";
                    $params[] = $esc . '%';
                    // Contains / national form: 2138007447 → 12138007447 (len≥7 avoids over-broad scans)
                    if ($len >= 7) {
                        $ors[] = "{$col} LIKE ?";
                        $params[] = '%' . $esc . '%';
                    }
                }
            }
            if ($ors !== []) {
                $tokenGroups[] = '(' . implode(' OR ', $ors) . ')';
            }
        }
        if ($tokenGroups === []) {
            return [null, []];
        }
        // Multiple numbers: match ANY of them
        return ['(' . implode(' OR ', $tokenGroups) . ')', $params];
    }
}
