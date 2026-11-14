<?php

declare(strict_types=1);

namespace Ownswitch\Services\Reports;

use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Services\Dial\CallLimitService;
use Ownswitch\Services\Dial\LabDialogService;
use PDO;

/** System-wide live concurrent + CPS for dashboard / Calls Online meters. */
final class LiveDialStatsService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   concurrent:int,
     *   concurrent_live:int,
     *   concurrent_lab:int,
     *   trying:int,
     *   ringing:int,
     *   connected:int,
     *   cps:int,
     *   sip:?array<string,mixed>,
     *   ts:int
     * }
     */
    public function snapshot(?string $sipUser = null): array
    {
        $lab = new LabDialogService($this->pdo);
        $labRows = $lab->listNormalized($sipUser);
        $labN = count($labRows);

        $trying = 0;
        $ringing = 0;
        $connected = $labN; // lab rows are connected
        $liveN = 0;

        $prov = OpensipsProvisioner::fromConfig();
        if ($prov->enabled()) {
            try {
                foreach ($prov->listDialogs() as $d) {
                    if (!is_array($d)) {
                        continue;
                    }
                    if ($sipUser !== null && $sipUser !== '') {
                        $src = strtolower((string) ($d['os_src'] ?? ''));
                        if ($src === '') {
                            foreach (['values', 'Values', 'dlg_vals'] as $bag) {
                                if (!isset($d[$bag]) || !is_array($d[$bag])) {
                                    continue;
                                }
                                if (isset($d[$bag]['os_src'])) {
                                    $src = strtolower((string) $d[$bag]['os_src']);
                                }
                            }
                        }
                        $from = strtolower((string) ($d['from_uri'] ?? $d['caller'] ?? ''));
                        if ($src !== strtolower($sipUser) && !str_contains($from, strtolower($sipUser) . '@')) {
                            continue;
                        }
                    }
                    $liveN++;
                    $st = $d['state'] ?? $d['State'] ?? null;
                    $n = is_numeric($st) ? (int) $st : 0;
                    if ($n === 1) {
                        $trying++;
                    } elseif ($n === 2) {
                        $ringing++;
                    } elseif ($n === 3 || $n === 4) {
                        $connected++;
                    } else {
                        $connected++;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $cps = 0;
        $sipSnap = null;
        $limits = new CallLimitService($this->pdo);
        if ($sipUser !== null && $sipUser !== '') {
            $sipSnap = $limits->snapshotSipUser($sipUser);
            $cps = (int) ($sipSnap['cps'] ?? 0);
        } else {
            // Best-effort: sum CPS for active SIP users that have a non-zero peek
            try {
                $users = $this->pdo->query(
                    "SELECT username FROM sip_accounts WHERE status = 'active' ORDER BY username LIMIT 200"
                )->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($users as $u) {
                    $u = strtolower((string) $u);
                    if ($u === '') {
                        continue;
                    }
                    $cps += $limits->peekCps('sip:' . $u);
                }
            } catch (\Throwable) {
            }
        }

        return [
            'concurrent' => $liveN + $labN,
            'concurrent_live' => $liveN,
            'concurrent_lab' => $labN,
            'trying' => $trying,
            'ringing' => $ringing,
            'connected' => $connected,
            'cps' => $cps,
            'sip' => $sipSnap,
            'ts' => time(),
        ];
    }
}
