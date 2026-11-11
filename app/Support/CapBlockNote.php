<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** Text stored on a CDR when a trunk or SIP-user rate CAP blocks a call. */
final class CapBlockNote
{
    /**
     * @param list<array{id:int,name:string,buy:string,cap:string}> $skips
     */
    public static function encode(array $skips): string
    {
        $parts = [];
        foreach ($skips as $skip) {
            $name = str_replace(['^', '~'], ' ', (string) $skip['name']);
            $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
            $parts[] = (int) $skip['id'] . '~' . $name . '~' . (string) $skip['buy'] . '~' . (string) $skip['cap'];
        }
        return implode('^', $parts);
    }

    /**
     * @return list<array{id:int,name:string,buy:string,cap:string}>
     */
    public static function decode(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode('^', $raw) as $part) {
            $bits = explode('~', $part);
            if (count($bits) < 4) {
                continue;
            }
            $id = (int) $bits[0];
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => $bits[1] !== '' ? $bits[1] : ('trunk ' . $id),
                'buy' => $bits[2],
                'cap' => $bits[3],
            ];
        }
        return $out;
    }

    public static function trunkLine(string $name, string $buy, string $cap): string
    {
        $name = trim($name) !== '' ? trim($name) : 'trunk';
        if ($buy === '') {
            return 'Blocked by trunk CAP (' . $name . ', no provider rate, cap ' . $cap . ')';
        }
        return 'Blocked by trunk CAP (' . $name . ', buy ' . $buy . ' > cap ' . $cap . ')';
    }

    /** @param list<array{id:int,name:string,buy:string,cap:string}> $skips */
    public static function summary(array $skips): string
    {
        $lines = [];
        foreach ($skips as $skip) {
            $lines[] = self::trunkLine((string) $skip['name'], (string) $skip['buy'], (string) $skip['cap']);
        }
        if ($lines === []) {
            return 'Blocked by trunk CAP';
        }
        if (count($lines) === 1) {
            return $lines[0];
        }
        $bits = [];
        foreach ($skips as $skip) {
            $name = trim((string) $skip['name']) !== '' ? trim((string) $skip['name']) : 'trunk';
            $buy = (string) $skip['buy'];
            $cap = (string) $skip['cap'];
            $bits[] = $buy === ''
                ? ($name . ' no provider rate, cap ' . $cap)
                : ($name . ' buy ' . $buy . ' > cap ' . $cap);
        }
        return mb_substr('Blocked by trunk CAP: ' . implode('; ', $bits), 0, 180);
    }

    public static function isTrunk(string $reason): bool
    {
        return str_starts_with(trim($reason), 'Blocked by trunk CAP');
    }

    public static function isUser(string $reason): bool
    {
        return str_starts_with(trim($reason), 'Blocked by user CAP');
    }

    public static function isCap(string $reason): bool
    {
        return self::isTrunk($reason) || self::isUser($reason);
    }

    /** CDR / SIP-code column: UCAP (user) or TCAP (trunk). */
    public static function code(string $reason): ?string
    {
        if (self::isUser($reason)) {
            return 'UCAP';
        }
        if (self::isTrunk($reason)) {
            return 'TCAP';
        }
        return null;
    }

    /** List RESULT column for a CAP block. */
    public static function resultLabel(string $reason): string
    {
        return self::isCap($reason) ? 'CAP' : '';
    }
}
