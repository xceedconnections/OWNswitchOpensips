<?php

declare(strict_types=1);

namespace Ownswitch\Integrations\OpenSIPS;

/**
 * Minimal UDP SIP UAC used to INVITE a monitor extension with an SDP offer
 * (RTPengine subscribe) and collect the 200 OK answer SDP.
 */
final class SipInviteClient
{
    /**
     * @return array{ok:bool,code:int,sdp:string,detail:string}
     */
    public function inviteWithSdp(
        string $requestUri,
        string $toUri,
        string $fromUri,
        string $sdp,
        string $localIp,
        float $timeoutSec = 25.0,
    ): array {
        $requestUri = trim($requestUri);
        $toUri = trim($toUri);
        $fromUri = trim($fromUri);
        $sdp = trim(str_replace("\r\n", "\n", $sdp));
        $sdp = str_replace("\n", "\r\n", $sdp);
        if ($requestUri === '' || $toUri === '' || $fromUri === '' || $sdp === '') {
            return ['ok' => false, 'code' => 0, 'sdp' => '', 'detail' => 'Missing SIP URI or SDP'];
        }
        $target = self::parseSipUri($requestUri);
        if ($target === null) {
            return ['ok' => false, 'code' => 0, 'sdp' => '', 'detail' => 'Invalid request URI'];
        }
        $localIp = trim($localIp) !== '' ? trim($localIp) : '127.0.0.1';
        $sock = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);
        if (!$sock) {
            return ['ok' => false, 'code' => 0, 'sdp' => '', 'detail' => 'UDP bind failed: ' . $errstr];
        }
        $localName = stream_socket_get_name($sock, false);
        $localPort = 0;
        if (is_string($localName) && preg_match('/:(\d+)$/', $localName, $m)) {
            $localPort = (int) $m[1];
        }
        if ($localPort <= 0) {
            fclose($sock);
            return ['ok' => false, 'code' => 0, 'sdp' => '', 'detail' => 'Could not read local UDP port'];
        }

        $callId = bin2hex(random_bytes(8)) . '@' . $localIp;
        $fromTag = bin2hex(random_bytes(4));
        $branch = 'z9hG4bK' . bin2hex(random_bytes(6));
        $cseq = 1;
        $bodyLen = strlen($sdp);
        $contact = "sip:monitor@{$localIp}:{$localPort}";
        $invite = "INVITE {$requestUri} SIP/2.0\r\n"
            . "Via: SIP/2.0/UDP {$localIp}:{$localPort};branch={$branch};rport\r\n"
            . "Max-Forwards: 70\r\n"
            . "From: <{$fromUri}>;tag={$fromTag}\r\n"
            . "To: <{$toUri}>\r\n"
            . "Call-ID: {$callId}\r\n"
            . "CSeq: {$cseq} INVITE\r\n"
            . "Contact: <{$contact}>\r\n"
            . "Content-Type: application/sdp\r\n"
            . "Content-Length: {$bodyLen}\r\n"
            . "\r\n"
            . $sdp;

        $dest = "udp://{$target['host']}:{$target['port']}";
        $peer = @stream_socket_client($dest, $errno, $errstr, 2.0);
        if (!$peer) {
            fclose($sock);
            return ['ok' => false, 'code' => 0, 'sdp' => '', 'detail' => "Connect {$dest}: {$errstr}"];
        }
        stream_set_timeout($peer, 1);
        fwrite($peer, $invite);
        // Also send via bound socket so replies return to our Via port
        stream_socket_sendto($sock, $invite, 0, "{$target['host']}:{$target['port']}");
        fclose($peer);

        $deadline = microtime(true) + max(5.0, $timeoutSec);
        $toTag = '';
        $answerSdp = '';
        $lastCode = 0;
        $lastReason = '';
        while (microtime(true) < $deadline) {
            $read = [$sock];
            $write = null;
            $except = null;
            $remain = max(0.2, $deadline - microtime(true));
            $sec = (int) $remain;
            $usec = (int) (($remain - $sec) * 1_000_000);
            if (@stream_select($read, $write, $except, $sec, $usec) < 1) {
                continue;
            }
            $raw = @stream_socket_recvfrom($sock, 65535, 0, $peerName);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            if (!str_contains($raw, $callId)) {
                continue;
            }
            if (!preg_match('#^SIP/2\.0\s+(\d{3})\s+([^\r\n]*)#', $raw, $rm)) {
                continue;
            }
            $code = (int) $rm[1];
            $reason = trim($rm[2]);
            $lastCode = $code;
            $lastReason = $reason;
            if ($code >= 100 && $code < 200) {
                continue;
            }
            if ($code >= 300) {
                fclose($sock);
                return [
                    'ok' => false,
                    'code' => $code,
                    'sdp' => '',
                    'detail' => "Monitor rejected INVITE ({$code} {$reason})",
                ];
            }
            if ($code === 200) {
                if (preg_match('/\r\nTo:[^\r\n]*;tag=([^\s;>]+)/i', $raw, $tm)) {
                    $toTag = $tm[1];
                }
                $answerSdp = self::extractSdp($raw);
                $ack = "ACK {$requestUri} SIP/2.0\r\n"
                    . "Via: SIP/2.0/UDP {$localIp}:{$localPort};branch=z9hG4bK" . bin2hex(random_bytes(6)) . ";rport\r\n"
                    . "Max-Forwards: 70\r\n"
                    . "From: <{$fromUri}>;tag={$fromTag}\r\n"
                    . "To: <{$toUri}>" . ($toTag !== '' ? ";tag={$toTag}" : '') . "\r\n"
                    . "Call-ID: {$callId}\r\n"
                    . "CSeq: {$cseq} ACK\r\n"
                    . "Content-Length: 0\r\n"
                    . "\r\n";
                @stream_socket_sendto($sock, $ack, 0, "{$target['host']}:{$target['port']}");
                fclose($sock);
                if ($answerSdp === '') {
                    return ['ok' => false, 'code' => 200, 'sdp' => '', 'detail' => '200 OK without SDP'];
                }
                return ['ok' => true, 'code' => 200, 'sdp' => $answerSdp, 'detail' => 'Monitor answered'];
            }
        }
        fclose($sock);
        return [
            'ok' => false,
            'code' => $lastCode,
            'sdp' => '',
            'detail' => $lastCode > 0
                ? "Monitor INVITE timed out after {$lastCode} {$lastReason}"
                : 'Monitor INVITE timed out (no reply — is the extension registered?)',
        ];
    }

    /** @return array{host:string,port:int}|null */
    public static function parseSipUri(string $uri): ?array
    {
        $uri = trim($uri);
        if (!preg_match('#^sips?:([^@;>\s]+@)?([^;>\s:]+)(?::(\d+))?#i', $uri, $m)) {
            return null;
        }
        $host = $m[2];
        $port = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 5060;
        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }
        return ['host' => $host, 'port' => $port];
    }

    private static function extractSdp(string $msg): string
    {
        $parts = preg_split("/\r\n\r\n/", $msg, 2);
        if (!is_array($parts) || count($parts) < 2) {
            return '';
        }
        $body = trim($parts[1]);
        if ($body === '' || !str_starts_with($body, 'v=')) {
            return '';
        }
        return $body;
    }
}
