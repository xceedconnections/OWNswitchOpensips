<?php

declare(strict_types=1);

namespace Ownswitch\Support;

use PDO;

/**
 * SIP-user destination prefix rewrite rules.
 *
 * One rule per line (or comma-separated): FROM=TO
 * If the dialled digits start with FROM, replace that prefix with TO.
 *
 * Example: 2=12 turns 2138007447 into 12138007447.
 * Longest FROM match wins.
 */
final class DstPrefixMap
{
    /**
     * @return list<array{from:string,to:string}>
     */
    public static function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^(\d{1,15})\s*[=:>→]\s*(\d{1,15})$/u', $line, $m)) {
                continue;
            }
            $from = $m[1];
            $to = $m[2];
            if ($from === $to) {
                continue;
            }
            $out[] = ['from' => $from, 'to' => $to];
        }
        // Longest FROM first
        usort($out, static fn (array $a, array $b): int => strlen($b['from']) <=> strlen($a['from']));
        // Dedupe by FROM (keep longest / first)
        $seen = [];
        $uniq = [];
        foreach ($out as $row) {
            if (isset($seen[$row['from']])) {
                continue;
            }
            $seen[$row['from']] = true;
            $uniq[] = $row;
        }
        return $uniq;
    }

    /** Normalize for DB storage: one FROM=TO per line, longest first. */
    public static function normalize(?string $raw): string
    {
        $rows = self::parse($raw);
        if ($rows === []) {
            return '';
        }
        return implode("\n", array_map(
            static fn (array $r): string => $r['from'] . '=' . $r['to'],
            $rows
        ));
    }

    /** Apply first matching rule; returns digits-only destination. */
    public static function apply(?string $rawMap, string $destination): string
    {
        $dst = preg_replace('/\D+/', '', $destination) ?? '';
        if ($dst === '') {
            return '';
        }
        foreach (self::parse($rawMap) as $rule) {
            $from = $rule['from'];
            if ($from !== '' && str_starts_with($dst, $from)) {
                return $rule['to'] . substr($dst, strlen($from));
            }
        }
        return $dst;
    }

    /** Idempotent ALTER when migrate was skipped. */
    public static function ensureColumn(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $st = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'dst_prefix_map'"
            );
            if ((int) $st->fetchColumn() === 0) {
                $pdo->exec('ALTER TABLE sip_accounts ADD COLUMN dst_prefix_map TEXT NULL AFTER dst_format');
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] dst_prefix_map ensure: ' . $e->getMessage());
        }
        $done = true;
    }
}
