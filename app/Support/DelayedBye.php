<?php

declare(strict_types=1);

namespace Ownswitch\Support;

use PDO;

/**
 * Trunk Delayed Bye / MCD helpers.
 *
 * Extension after the real hangup:
 *   max(0, MCD − actual) + Delayed Bye
 * so the carrier leg is held until about max(actual, MCD) + Delayed Bye.
 *
 * Customer charges always use actual talk time. Carrier buy cost uses the
 * extended duration only when delayed_bye_count_buying is enabled on the trunk.
 */
final class DelayedBye
{
    public const MAX_SECONDS = 3600;

    /** Seconds to hold the carrier leg after the real hangup. */
    public static function extensionSeconds(int $actual, int $mcd, int $delayedBye): int
    {
        $actual = max(0, $actual);
        $mcd = max(0, min(self::MAX_SECONDS, $mcd));
        $delayedBye = max(0, min(self::MAX_SECONDS, $delayedBye));
        return max(0, $mcd - $actual) + $delayedBye;
    }

    /** Connected seconds to use for carrier / buy rating. */
    public static function carrierDuration(int $actual, int $mcd, int $delayedBye, bool $countBuying): int
    {
        $actual = max(0, $actual);
        if (!$countBuying) {
            return $actual;
        }
        return $actual + self::extensionSeconds($actual, $mcd, $delayedBye);
    }

    public static function enabled(int $mcd, int $delayedBye): bool
    {
        return $mcd > 0 || $delayedBye > 0;
    }

    public static function clampSeconds(mixed $raw): int
    {
        $n = (int) $raw;
        if ($n < 0) {
            return 0;
        }
        if ($n > self::MAX_SECONDS) {
            return self::MAX_SECONDS;
        }
        return $n;
    }

    /** Idempotent ALTER when migrate was skipped. */
    public static function ensureColumns(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $cols = [
            'delayed_bye' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'delayed_bye_mcd' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'delayed_bye_count_buying' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];
        foreach ($cols as $col => $def) {
            try {
                $st = $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carrier_trunks'
                       AND COLUMN_NAME = " . $pdo->quote($col)
                );
                if ((int) $st->fetchColumn() === 0) {
                    $pdo->exec("ALTER TABLE carrier_trunks ADD COLUMN `{$col}` {$def}");
                }
            } catch (\Throwable $e) {
                error_log('[ownswitch] delayed_bye ensure ' . $col . ': ' . $e->getMessage());
            }
        }
        $done = true;
    }
}
