<?php

declare(strict_types=1);

namespace Ownswitch\Services\Dial;

use PDO;

/**
 * Portal-side dummy dialogs for Dial Lab — no trunk, no SIP to carrier.
 * Appear on Calls Online and count toward SIP concurrent limits.
 */
final class LabDialogService
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS lab_dialogs (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    callid VARCHAR(128) NOT NULL,
                    sip_user VARCHAR(64) NOT NULL,
                    from_user VARCHAR(64) NOT NULL DEFAULT 'lab',
                    to_user VARCHAR(64) NOT NULL DEFAULT 'lab-sink',
                    caller_id VARCHAR(64) NULL,
                    state TINYINT UNSIGNED NOT NULL DEFAULT 4,
                    started_at INT UNSIGNED NOT NULL,
                    created_by INT UNSIGNED NULL,
                    UNIQUE KEY uq_lab_callid (callid),
                    KEY idx_lab_sip (sip_user),
                    KEY idx_lab_started (started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable) {
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function start(string $sipUser, int $count, ?int $createdBy = null, int $staggerSec = 0): array
    {
        $sipUser = strtolower(preg_replace('/[^A-Za-z0-9_.\-]/', '', $sipUser) ?? '');
        $count = max(1, min(50, $count));
        $staggerSec = max(0, min(30, $staggerSec));
        if ($sipUser === '') {
            return [];
        }
        $now = time();
        $out = [];
        $st = $this->pdo->prepare(
            'INSERT INTO lab_dialogs (callid, sip_user, from_user, to_user, caller_id, state, started_at, created_by)
             VALUES (?, ?, ?, ?, ?, 4, ?, ?)'
        );
        for ($i = 0; $i < $count; $i++) {
            $callid = 'lab-' . bin2hex(random_bytes(8)) . '@dial-lab';
            $started = $now - ($staggerSec > 0 ? ($i * $staggerSec) : 0);
            $from = 'lab' . ($i + 1);
            $to = 'sink' . ($i + 1);
            $cli = '1555' . str_pad((string) (($i + 1) % 10000), 7, '0', STR_PAD_LEFT);
            $st->execute([$callid, $sipUser, $from, $to, $cli, $started, $createdBy]);
            $out[] = $this->rowToNormalized([
                'id' => (int) $this->pdo->lastInsertId(),
                'callid' => $callid,
                'sip_user' => $sipUser,
                'from_user' => $from,
                'to_user' => $to,
                'caller_id' => $cli,
                'state' => 4,
                'started_at' => $started,
            ]);
        }
        return $out;
    }

    public function endByCallid(string $callid): bool
    {
        $callid = trim($callid);
        if ($callid === '' || !str_starts_with($callid, 'lab-')) {
            return false;
        }
        $st = $this->pdo->prepare('DELETE FROM lab_dialogs WHERE callid = ?');
        $st->execute([$callid]);
        return $st->rowCount() > 0;
    }

    public function endAll(?string $sipUser = null): int
    {
        if ($sipUser !== null && $sipUser !== '') {
            $st = $this->pdo->prepare('DELETE FROM lab_dialogs WHERE sip_user = ?');
            $st->execute([strtolower($sipUser)]);
            return $st->rowCount();
        }
        return (int) $this->pdo->exec('DELETE FROM lab_dialogs');
    }

    public function countForSip(string $sipUser): int
    {
        $sipUser = strtolower(trim($sipUser));
        if ($sipUser === '') {
            return 0;
        }
        try {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM lab_dialogs WHERE sip_user = ?');
            $st->execute([$sipUser]);
            return max(0, (int) $st->fetchColumn());
        } catch (\Throwable) {
            return 0;
        }
    }

    public function total(): int
    {
        try {
            return max(0, (int) $this->pdo->query('SELECT COUNT(*) FROM lab_dialogs')->fetchColumn());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Normalized rows compatible with CallsOnlineController::normalize shape.
     *
     * @return list<array<string,mixed>>
     */
    public function listNormalized(?string $sipUser = null): array
    {
        try {
            if ($sipUser !== null && $sipUser !== '') {
                $st = $this->pdo->prepare('SELECT * FROM lab_dialogs WHERE sip_user = ? ORDER BY started_at ASC');
                $st->execute([strtolower($sipUser)]);
            } else {
                $st = $this->pdo->query('SELECT * FROM lab_dialogs ORDER BY started_at ASC');
            }
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $this->rowToNormalized($row);
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function rowToNormalized(array $row): array
    {
        $started = (int) ($row['started_at'] ?? time());
        $dur = max(0, time() - $started);
        $callid = (string) ($row['callid'] ?? '');
        $sip = (string) ($row['sip_user'] ?? '');
        $from = (string) ($row['from_user'] ?? 'lab');
        $to = (string) ($row['to_user'] ?? 'lab-sink');
        $color = 'os-rt-pink-soft';
        if ($dur >= 600) {
            $color = 'os-rt-green';
        } elseif ($dur >= 60) {
            $color = 'os-rt-pink';
        }
        return [
            'callid' => $callid,
            'dialog' => 'lab:' . (string) ($row['id'] ?? ''),
            'sip_user' => $sip,
            'from' => $from,
            'from_user' => $from,
            'to' => $to,
            'to_user' => $to,
            'state' => 'Connected',
            'state_raw' => 4,
            'bucket' => 'connected',
            'duration' => $dur,
            'caller_id' => (string) ($row['caller_id'] ?? ''),
            'from_tag' => 'lab',
            'color' => $color,
            'is_lab' => true,
            'uuid' => $callid,
        ];
    }
}
