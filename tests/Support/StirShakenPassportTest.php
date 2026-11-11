<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Support;

use Ownswitch\Services\StirShaken\StirShakenPassport;
use Ownswitch\Tests\TestCase;

final class StirShakenPassportTest extends TestCase
{
    /** Fixed P-256 fixture (test only — not a production SHAKEN key). */
    private const PRIV = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIKwesXLwdn6QMcyZQc9K32gNsMNgMezhdHWKmbso2uZZoAoGCCqGSM49
AwEHoUQDQgAEFp9hpMxStMVEVOMT1DnHzixl82LePQ2JZ76YfK4iLGyDmu7xtiVv
jav+p+U96uLXmrGlOh7Ak+Dqm9XNgltZww==
-----END EC PRIVATE KEY-----
PEM;

    public function testIdentityHeaderRoundTripShape(): void
    {
        if (!extension_loaded('openssl')) {
            $this->skip('OpenSSL extension required');
        }
        $key = openssl_pkey_get_private(self::PRIV);
        if ($key === false) {
            // Some Windows/XAMPP builds lack openssl.cnf and reject even static PEMs.
            $this->skip('OpenSSL cannot load EC private keys in this environment');
        }

        $hdr = StirShakenPassport::identityHeader(
            self::PRIV,
            'https://certs.example.com/sp.pem',
            'B',
            '+12135550100',
            '13105551212',
            'e32f4189-cb86-460f-bb92-bd3acb89f29c',
            1700000000
        );

        $this->assertTrue(str_contains($hdr, ';info=<https://certs.example.com/sp.pem>'), 'info param');
        $this->assertTrue(str_ends_with($hdr, ';alg=ES256;ppt=shaken'), 'alg/ppt params');
        $parts = explode(';', $hdr, 2);
        $jwt = $parts[0];
        $chunks = explode('.', $jwt);
        $this->assertTrue(count($chunks) === 3, 'JWS has 3 parts');

        $header = json_decode($this->b64urlDecode($chunks[0]), true);
        $payload = json_decode($this->b64urlDecode($chunks[1]), true);
        $this->assertTrue(is_array($header) && ($header['alg'] ?? '') === 'ES256');
        $this->assertTrue(($header['ppt'] ?? '') === 'shaken');
        $this->assertTrue(($payload['attest'] ?? '') === 'B');
        $this->assertTrue(($payload['orig']['tn'] ?? '') === '12135550100');
        $this->assertTrue(($payload['dest']['tn'][0] ?? '') === '13105551212');
        $this->assertTrue(($payload['iat'] ?? 0) === 1700000000);

        $raw = $this->b64urlDecode($chunks[2]);
        $this->assertTrue(strlen($raw) === 64, 'ES256 raw sig is 64 bytes');
        $details = openssl_pkey_get_details($key);
        $this->assertTrue(is_array($details) && !empty($details['key']), 'public key details');
        $pub = openssl_pkey_get_public($details['key']);
        $this->assertTrue($pub !== false, 'load public key');
        $der = $this->joseToDer($raw);
        $ok = openssl_verify($chunks[0] . '.' . $chunks[1], $der, $pub, OPENSSL_ALGO_SHA256);
        $this->assertTrue($ok === 1, 'signature verifies');
    }

    public function testTnDigitsStripsPlus(): void
    {
        $this->assertTrue(StirShakenPassport::tnDigits('+1 (213) 555-0100') === '12135550100');
        $this->assertTrue(StirShakenPassport::tnDigits('') === '');
    }

    private function b64urlDecode(string $s): string
    {
        $pad = 4 - (strlen($s) % 4);
        if ($pad < 4) {
            $s .= str_repeat('=', $pad);
        }
        $bin = base64_decode(strtr($s, '-_', '+/'), true);
        $this->assertTrue($bin !== false, 'b64url decode');
        return $bin;
    }

    private function joseToDer(string $raw): string
    {
        $r = ltrim(substr($raw, 0, 32), "\x00");
        $s = ltrim(substr($raw, 32, 32), "\x00");
        if ($r === '' || (ord($r[0]) & 0x80)) {
            $r = "\x00" . $r;
        }
        if ($s === '' || (ord($s[0]) & 0x80)) {
            $s = "\x00" . $s;
        }
        return "\x30" . chr(4 + strlen($r) + strlen($s))
            . "\x02" . chr(strlen($r)) . $r
            . "\x02" . chr(strlen($s)) . $s;
    }
}
