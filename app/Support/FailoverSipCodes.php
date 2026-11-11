<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Settings → Failover SIP codes.
 * Numeric codes (408, …) continue to the next trunk after a vendor SIP reply.
 * TCAP / CAP = trunk rate CAP → try next trunk. UCAP = user rate CAP → never try next trunk.
 */
final class FailoverSipCodes
{
    public const DEFAULT = '408,480,486,503,TCAP';

    /** @return list<string> uppercase tokens */
    public static function parse(?string $raw): array
    {
        $raw = strtoupper(trim((string) $raw));
        if ($raw === '') {
            $raw = self::DEFAULT;
        }
        $out = [];
        foreach (preg_split('/[,\s;|]+/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\d{3}$/', $part) || in_array($part, ['UCAP', 'TCAP', 'CAP'], true)) {
                if (!in_array($part, $out, true)) {
                    $out[] = $part;
                }
            }
        }
        return $out !== [] ? $out : self::parse(self::DEFAULT);
    }

    /** @return list<string> 3-digit SIP codes only (OpenSIPS / OpenSIPS) */
    public static function numeric(?string $raw): array
    {
        $nums = [];
        foreach (self::parse($raw) as $t) {
            if (preg_match('/^\d{3}$/', $t)) {
                $nums[] = $t;
            }
        }
        return $nums !== [] ? $nums : ['408', '480', '486', '503'];
    }

    /** Trunk buy-rate CAP may skip this trunk and try the next. */
    public static function allowsTrunkCap(?string $raw): bool
    {
        $tokens = self::parse($raw);
        $hasCapWord = false;
        foreach ($tokens as $t) {
            if ($t === 'TCAP' || $t === 'CAP') {
                return true;
            }
            if ($t === 'UCAP') {
                $hasCapWord = true;
            }
        }
        // Legacy lists (only 408,480,…) keep the old always-failover trunk CAP behaviour.
        return !$hasCapWord;
    }

    /** User sell-rate CAP never continues to another trunk. */
    public static function allowsUserCapFailover(?string $raw): bool
    {
        return false;
    }

    public static function normalize(?string $raw): ?string
    {
        $parts = self::parse($raw);
        return $parts === [] ? null : implode(',', $parts);
    }
}
