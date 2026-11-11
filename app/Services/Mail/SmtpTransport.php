<?php

declare(strict_types=1);

namespace Ownswitch\Services\Mail;

/** Minimal SMTP client (AUTH LOGIN, STARTTLS / SSL). No Composer deps. */
final class SmtpTransport
{
    /**
     * @param array{
     *   host:string,port:int,encryption:string,username:string,password:string,
     *   from_email:string,from_name:string
     * } $cfg
     * @return array{ok:bool,detail:string}
     */
    public function send(array $cfg, string $to, string $subject, string $textBody): array
    {
        $host = trim($cfg['host']);
        $port = (int) $cfg['port'];
        $enc = strtolower(trim($cfg['encryption']));
        $user = (string) $cfg['username'];
        $pass = (string) $cfg['password'];
        $fromEmail = trim($cfg['from_email']);
        $fromName = trim($cfg['from_name']);
        $to = trim($to);

        if ($host === '' || $port < 1 || $port > 65535) {
            return ['ok' => false, 'detail' => 'SMTP host/port is not configured.'];
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'detail' => 'From email is missing or invalid.'];
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'detail' => 'Recipient email is invalid.'];
        }

        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $remote . ':' . $port,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );
        if (!is_resource($fp)) {
            return ['ok' => false, 'detail' => 'Cannot connect to SMTP: ' . ($errstr !== '' ? $errstr : (string) $errno)];
        }
        stream_set_timeout($fp, 20);

        try {
            $this->expect($fp, [220]);
            $this->cmd($fp, 'EHLO ownswitch.local', [250]);
            if ($enc === 'tls') {
                $this->cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed.');
                }
                $this->cmd($fp, 'EHLO ownswitch.local', [250]);
            }
            if ($user !== '') {
                $this->cmd($fp, 'AUTH LOGIN', [334]);
                $this->cmd($fp, base64_encode($user), [334]);
                $this->cmd($fp, base64_encode($pass), [235]);
            }
            $this->cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd($fp, 'DATA', [354]);

            $fromHeader = $fromName !== ''
                ? $this->encodeHeader($fromName) . ' <' . $fromEmail . '>'
                : $fromEmail;
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'From: ' . $fromHeader,
                'To: <' . $to . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'X-Mailer: Ownswitch',
            ];
            $body = preg_replace("/\r\n|\r|\n/", "\r\n", $textBody) ?? $textBody;
            $body = preg_replace('/^\./m', '..', $body) ?? $body;
            fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
            $this->expect($fp, [250]);
            $this->cmd($fp, 'QUIT', [221]);
            fclose($fp);
            return ['ok' => true, 'detail' => 'Sent'];
        } catch (\Throwable $e) {
            fclose($fp);
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @param resource $fp @param list<int> $ok */
    private function cmd($fp, string $line, array $ok): void
    {
        fwrite($fp, $line . "\r\n");
        $this->expect($fp, $ok);
    }

    /** @param resource $fp @param list<int> $ok */
    private function expect($fp, array $ok): void
    {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($data, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new \RuntimeException('SMTP ' . $code . ': ' . trim($data));
        }
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
