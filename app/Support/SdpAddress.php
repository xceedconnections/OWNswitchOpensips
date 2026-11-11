<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** Public address from a WebRTC offer. The c= line is often 0.0.0.0; srflx is the user. */
final class SdpAddress
{
    public static function publicIp(string $sdp): string
    {
        $srflx = '';
        $relay = '';
        $host = '';
        if (preg_match_all('/^a=candidate:\S+ \d+ \S+ \d+ (\S+) \d+ typ (srflx|relay|host)\b/mi', $sdp, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $ip = CallerIp::usable((string) $hit[1]);
                if ($ip === '') {
                    continue;
                }
                $type = strtolower((string) $hit[2]);
                if ($type === 'srflx' && $srflx === '') {
                    $srflx = $ip;
                } elseif ($type === 'relay' && $relay === '') {
                    $relay = $ip;
                } elseif ($type === 'host' && $host === '') {
                    $host = $ip;
                }
            }
        }
        if ($srflx !== '') {
            return $srflx;
        }
        if ($relay !== '') {
            return $relay;
        }
        if (preg_match('/^c=IN IP[46] (\S+)/mi', $sdp, $c)) {
            $ip = CallerIp::usable((string) $c[1]);
            if ($ip !== '') {
                return $ip;
            }
        }
        return $host;
    }
}
