<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

use Ownswitch\App;
use PDO;

/**
 * Keep SIP:5060 locked to allow-listed peer/trunk IPs unless password (dynamic)
 * SIP users exist — those need world-open 5060 for digest REGISTER/INVITE.
 */
final class SipFirewallPolicyService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? App::db();
    }

    /** True when any active password-based SIP user exists. */
    public function hasPasswordSipUsers(): bool
    {
        try {
            $n = (int) $this->db()->query(
                "SELECT COUNT(*) FROM sip_accounts
                 WHERE status = 'active'
                   AND LOWER(COALESCE(NULLIF(TRIM(connection_mode), ''), 'dynamic'))
                       IN ('dynamic', 'password', 'digest')"
            )->fetchColumn();
            return $n > 0;
        } catch (\Throwable) {
            return true; // fail open so softphones keep working
        }
    }

    /**
     * Apply firewall mode + optionally refresh trunk IP allows.
     *
     * @return array{ok:bool,detail:string,mode:string}
     */
    public function ensure(): array
    {
        $fw = new SipPeerFirewallService();
        $mode = $this->hasPasswordSipUsers() ? 'open' : 'locked';
        $res = $fw->ensureSipMode($mode);
        return [
            'ok' => $res['ok'],
            'detail' => $res['detail'],
            'mode' => $mode,
        ];
    }
}
