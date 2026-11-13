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

    /** True when at least one active IP-auth SIP peer is configured. */
    public function hasIpAuthPeers(): bool
    {
        try {
            $n = (int) $this->db()->query(
                "SELECT COUNT(*) FROM sip_accounts
                 WHERE status = 'active'
                   AND LOWER(COALESCE(connection_mode, '')) = 'ip'"
            )->fetchColumn();
            return $n > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * open  = world SIP:5060 (password softphones, or no IP peers yet)
     * locked = only allow-listed peer/trunk IPs (IP-auth-only installs)
     *
     * @return array{ok:bool,detail:string,mode:string}
     */
    public function ensure(): array
    {
        $fw = new SipPeerFirewallService();
        // Lock only when IP peers exist and there are no password users.
        // Empty DB / no peers must stay open so we never brick 5060.
        if ($this->hasPasswordSipUsers() || !$this->hasIpAuthPeers()) {
            $mode = 'open';
        } else {
            $mode = 'locked';
        }
        $res = $fw->ensureSipMode($mode);
        return [
            'ok' => $res['ok'],
            'detail' => $res['detail'],
            'mode' => $mode,
        ];
    }
}
