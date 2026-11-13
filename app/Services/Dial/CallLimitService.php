<?php

declare(strict_types=1);

namespace Ownswitch\Services\Dial;

use PDO;

/**
 * Enforce SIP-user / trunk concurrent-call and CPS limits for OpenSIPS dial helpers.
 *
 * -1 = unlimited. 0 is treated as 1 for CPS (matches portal validation).
 */
final class CallLimitService
{
    public function __construct(private PDO $portalDb, private ?PDO $opensipsDb = null)
    {
    }

    /**
     * @return array{allow:bool,reason:string}
     */
    public function authorizeSipUser(string $username): array
    {
        $username = strtolower(trim($username));
        if ($username === '') {
            return ['allow' => true, 'reason' => 'skip-empty-user'];
        }

        $limits = $this->sipLimits($username);
        if ($limits === null) {
            return ['allow' => true, 'reason' => 'no-profile'];
        }

        $maxCh = (int) $limits['max_channels'];
        $maxCps = (int) $limits['max_cps'];
        if ($maxCps === 0) {
            $maxCps = 1;
        }

        if ($maxCps >= 0) {
            $cps = $this->bumpCps('sip:' . $username);
            if ($cps > $maxCps) {
                return ['allow' => false, 'reason' => "cps {$cps}>{$maxCps}"];
            }
        }

        if ($maxCh >= 0) {
            // Current INVITE already has create_dialog + os_src, so count includes self.
            // Deny only when strictly over the limit (using > max).
            $using = $this->countSipDialogs($username);
            if ($using > $maxCh) {
                return ['allow' => false, 'reason' => "channels {$using}>{$maxCh}"];
            }
        }

        return ['allow' => true, 'reason' => 'ok'];
    }

    /**
     * @return array{allow:bool,reason:string}
     */
    public function authorizeTrunk(int $trunkId): array
    {
        if ($trunkId <= 0) {
            return ['allow' => true, 'reason' => 'skip-empty-trunk'];
        }

        $limits = $this->trunkLimits($trunkId);
        if ($limits === null) {
            return ['allow' => true, 'reason' => 'no-trunk'];
        }

        $maxCh = (int) $limits['max_channels'];
        $maxCps = (int) $limits['max_cps'];
        if ($maxCps === 0) {
            $maxCps = 1;
        }

        if ($maxCps >= 0) {
            $cps = $this->bumpCps('trunk:' . $trunkId);
            if ($cps > $maxCps) {
                return ['allow' => false, 'reason' => "cps {$cps}>{$maxCps}"];
            }
        }

        if ($maxCh >= 0) {
            // tid dlg_val may already be set for this dialog — treat as inclusive of self.
            $using = $this->countTrunkDialogs($trunkId);
            if ($using > $maxCh) {
                return ['allow' => false, 'reason' => "channels {$using}>{$maxCh}"];
            }
        }

        return ['allow' => true, 'reason' => 'ok'];
    }

    /** @return array{max_channels:int,max_cps:int}|null */
    private function sipLimits(string $username): ?array
    {
        try {
            $st = $this->portalDb->prepare(
                "SELECT max_channels, max_cps FROM sip_accounts
                 WHERE LOWER(username) = ? AND status = 'active' LIMIT 1"
            );
            $st->execute([$username]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return [
                    'max_channels' => (int) ($row['max_channels'] ?? -1),
                    'max_cps' => (int) ($row['max_cps'] ?? -1),
                ];
            }
        } catch (\Throwable) {
        }

        $os = $this->opensips();
        if ($os === null) {
            return null;
        }
        try {
            $st = $os->prepare(
                'SELECT max_channels, max_cps FROM ownswitch_sip WHERE LOWER(username) = ? LIMIT 1'
            );
            $st->execute([$username]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            return [
                'max_channels' => (int) ($row['max_channels'] ?? -1),
                'max_cps' => (int) ($row['max_cps'] ?? -1),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{max_channels:int,max_cps:int}|null */
    private function trunkLimits(int $trunkId): ?array
    {
        try {
            $st = $this->portalDb->prepare(
                "SELECT max_channels, max_cps FROM carrier_trunks WHERE id = ? AND status = 'active' LIMIT 1"
            );
            $st->execute([$trunkId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            return [
                'max_channels' => (int) ($row['max_channels'] ?? -1),
                'max_cps' => (int) ($row['max_cps'] ?? -1),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function countSipDialogs(string $username): int
    {
        $os = $this->opensips();
        if ($os === null) {
            return 0;
        }
        try {
            // Prefer dialog_vars os_src (set by OpenSIPS resolve_os_src)
            if ($this->opensipsHasTable('dialog_vars')) {
                $st = $os->prepare(
                    "SELECT COUNT(DISTINCT CONCAT(dialog.hash_entry, ':', dialog.hash_id))
                     FROM dialog
                     INNER JOIN dialog_vars ON dialog_vars.hash_entry = dialog.hash_entry
                       AND dialog_vars.hash_id = dialog.hash_id
                     WHERE dialog_vars.dialog_key = 'os_src'
                       AND LOWER(dialog_vars.dialog_value) = ?"
                );
                $st->execute([$username]);
                return max(0, (int) $st->fetchColumn());
            }
            if ($this->opensipsHasTable('dialog')) {
                $like = '%sip:' . $username . '@%';
                $st = $os->prepare(
                    'SELECT COUNT(*) FROM dialog WHERE LOWER(caller_uri) LIKE ? OR LOWER(callee_uri) LIKE ?'
                );
                $st->execute([$like, $like]);
                return max(0, (int) $st->fetchColumn());
            }
        } catch (\Throwable) {
        }
        return 0;
    }

    private function countTrunkDialogs(int $trunkId): int
    {
        $os = $this->opensips();
        if ($os === null || !$this->opensipsHasTable('dialog_vars')) {
            return 0;
        }
        try {
            $st = $os->prepare(
                "SELECT COUNT(DISTINCT CONCAT(dialog.hash_entry, ':', dialog.hash_id))
                 FROM dialog
                 INNER JOIN dialog_vars ON dialog_vars.hash_entry = dialog.hash_entry
                   AND dialog_vars.hash_id = dialog.hash_id
                 WHERE dialog_vars.dialog_key = 'tid'
                   AND dialog_vars.dialog_value = ?"
            );
            $st->execute([(string) $trunkId]);
            return max(0, (int) $st->fetchColumn());
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Increment 1-second CPS bucket; returns count in current second. */
    private function bumpCps(string $key): int
    {
        $bucket = $key . ':' . time();
        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $n = (int) $redis->incr('ownswitch:cps:' . $bucket);
                if ($n === 1) {
                    $redis->expire('ownswitch:cps:' . $bucket, 3);
                }
                return $n;
            } catch (\Throwable) {
            }
        }

        // File fallback (per-second counters under /dev/shm or temp)
        $dir = is_dir('/dev/shm') ? '/dev/shm/ownswitch-cps' : sys_get_temp_dir() . '/ownswitch-cps';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $file = $dir . '/' . preg_replace('/[^A-Za-z0-9_.:-]/', '_', $bucket) . '.cnt';
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return 1;
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $n = max(0, (int) trim((string) $raw)) + 1;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $n);
            flock($fp, LOCK_UN);
            @chmod($file, 0660);
            return $n;
        } finally {
            fclose($fp);
        }
    }

    private function opensips(): ?PDO
    {
        if ($this->opensipsDb instanceof PDO) {
            return $this->opensipsDb;
        }
        try {
            $prov = \Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner::fromConfig();
            $this->opensipsDb = $prov->pdo();
            return $this->opensipsDb;
        } catch (\Throwable) {
            return null;
        }
    }

    private function opensipsHasTable(string $table): bool
    {
        $os = $this->opensips();
        if ($os === null) {
            return false;
        }
        try {
            $st = $os->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = " . $os->quote($table) . " LIMIT 1"
            );
            return (bool) $st?->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function redis(): ?\Redis
    {
        if (!class_exists(\Redis::class)) {
            return null;
        }
        try {
            $host = (string) \Ownswitch\App::config('app.redis.host', '127.0.0.1');
            $port = (int) \Ownswitch\App::config('app.redis.port', 6379);
            $pass = (string) \Ownswitch\App::config('app.redis.password', '');
            $r = new \Redis();
            if (!$r->connect($host, $port, 0.2)) {
                return null;
            }
            if ($pass !== '') {
                $r->auth($pass);
            }
            return $r;
        } catch (\Throwable) {
            return null;
        }
    }
}
