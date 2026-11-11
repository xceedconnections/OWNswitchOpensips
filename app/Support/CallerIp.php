<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** Client address helpers for CDRs. Loopback is the local WebSocket proxy, not the user. */
final class CallerIp
{
    public static function usable(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || self::isLoopback($ip) || $ip === '0.0.0.0' || $ip === '::') {
            return '';
        }
        return $ip;
    }

    public static function isLoopback(string $ip): bool
    {
        $ip = strtolower(trim($ip));
        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        return str_starts_with($ip, '127.');
    }

    /** True when OpenSIPS only saw nginx on this host, so a dialer-supplied IP may replace it. */
    public static function isLocalProxy(string $ip): bool
    {
        return trim($ip) === '' || self::isLoopback($ip);
    }
}
