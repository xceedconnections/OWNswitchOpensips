<?php

declare(strict_types=1);

namespace Ownswitch\Services\Dial;

use Ownswitch\Services\Billing\RateCapService;
use PDO;

/**
 * Single per-trunk dial helper: optional Rate CAP + optional trunk limits + optional trunk CallerID pick.
 */
final class DialTrunkGateService
{
    public function __construct(
        private PDO $pdo,
        private RateCapService $caps,
        private CallLimitService $limits,
        private CallerIdPickerService $callerIds,
    ) {
    }

    /**
     * @return array{
     *   ok:bool,
     *   deny:?string,
     *   deny_class:?string,
     *   trunk_cid:string,
     *   has_trunkcap:int,
     *   has_trklim:int,
     *   has_trunkcidg:int,
     *   reason:string
     * }
     */
    public function authorize(?string $sipUsername, string $destination, int $trunkId, int $callerIdGroupId = 0): array
    {
        $user = $sipUsername !== null ? trim($sipUsername) : '';
        $dst = preg_replace('/\D+/', '', $destination) ?? '';
        $out = [
            'ok' => true,
            'deny' => null,
            'deny_class' => null,
            'trunk_cid' => '',
            'has_trunkcap' => 0,
            'has_trklim' => 0,
            'has_trunkcidg' => $callerIdGroupId > 0 ? 1 : 0,
            'reason' => 'ok',
        ];

        if ($trunkId <= 0) {
            $out['reason'] = 'skip-empty-trunk';
            return $out;
        }

        $needsCap = $this->needsCapCheck($user, $trunkId);
        $out['has_trunkcap'] = $needsCap ? 1 : 0;
        if ($needsCap) {
            $cap = $this->caps->authorize($user !== '' ? $user : null, $dst, $trunkId);
            if (!$cap['allow']) {
                $reason = (string) ($cap['reason'] ?? 'cap');
                $isUser = str_starts_with($reason, 'User CAP')
                    || str_contains($reason, 'SIP account has no customer')
                    || str_contains($reason, 'no customer tariff');
                return [
                    'ok' => false,
                    'deny' => $reason,
                    'deny_class' => $isUser ? 'DENY_USER' : 'DENY_TRUNK',
                    'trunk_cid' => '',
                    'has_trunkcap' => 1,
                    'has_trklim' => 0,
                    'has_trunkcidg' => $out['has_trunkcidg'],
                    'reason' => $reason,
                ];
            }
        }

        $needsLim = $this->needsTrunkLimits($trunkId);
        $out['has_trklim'] = $needsLim ? 1 : 0;
        if ($needsLim) {
            $lim = $this->limits->authorizeTrunk($trunkId);
            if (!$lim['allow']) {
                $reason = (string) ($lim['reason'] ?? 'trunk-limit');
                return [
                    'ok' => false,
                    'deny' => $reason,
                    'deny_class' => 'DENY_LIMIT',
                    'trunk_cid' => '',
                    'has_trunkcap' => $out['has_trunkcap'],
                    'has_trklim' => 1,
                    'has_trunkcidg' => $out['has_trunkcidg'],
                    'reason' => $reason,
                ];
            }
        }

        if ($callerIdGroupId > 0) {
            $pick = $this->callerIds->pickFromGroup($callerIdGroupId, $dst);
            if ($pick !== null && $pick['cli'] !== '') {
                $out['trunk_cid'] = $pick['cli'];
            }
        }

        return $out;
    }

    private function needsCapCheck(string $user, int $trunkId): bool
    {
        if ($user !== '') {
            try {
                $st = $this->pdo->prepare(
                    "SELECT rate_cap FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1"
                );
                $st->execute([$user]);
                $v = $st->fetchColumn();
                if ($v !== false && $v !== null && $v !== '' && is_numeric($v) && (float) $v > 0) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }
        try {
            $st = $this->pdo->prepare('SELECT rate_cap FROM carrier_trunks WHERE id = ? LIMIT 1');
            $st->execute([$trunkId]);
            $v = $st->fetchColumn();
            return $v !== false && $v !== null && $v !== '' && is_numeric($v) && (float) $v > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function needsTrunkLimits(int $trunkId): bool
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT max_channels, max_cps FROM carrier_trunks WHERE id = ? AND status = 'active' LIMIT 1"
            );
            $st->execute([$trunkId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return false;
            }
            $maxCh = (int) ($row['max_channels'] ?? -1);
            $maxCps = (int) ($row['max_cps'] ?? -1);
            if ($maxCps === 0) {
                $maxCps = 1;
            }
            return $maxCh >= 0 || $maxCps >= 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
