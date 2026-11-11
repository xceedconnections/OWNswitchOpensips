<?php

declare(strict_types=1);

namespace Ownswitch\Services\StirShaken;

/**
 * Build a SHAKEN PASSporT and SIP Identity header (RFC 8224 / ATIS SHAKEN).
 * Uses ES256 (P-256 + SHA-256). OpenSIPS may also sign natively when built with
 * libstirshaken; this path signs in the portal so each SIP user can pick a cert.
 */
final class StirShakenPassport
{
    /**
     * @param string $attest A|B|C
     * @return string SIP Identity header value (no "Identity:" prefix)
     */
    public static function identityHeader(
        string $privateKeyPem,
        string $x5u,
        string $attest,
        string $origTn,
        string $destTn,
        string $origid,
        ?int $iat = null
    ): string {
        $attest = strtoupper(trim($attest));
        if (!in_array($attest, ['A', 'B', 'C'], true)) {
            throw new \InvalidArgumentException('Attestation must be A, B, or C.');
        }
        $origTn = self::tnDigits($origTn);
        $destTn = self::tnDigits($destTn);
        if ($origTn === '' || $destTn === '') {
            throw new \InvalidArgumentException('orig and dest telephone numbers are required.');
        }
        $x5u = trim($x5u);
        if ($x5u === '' || !preg_match('#^https?://#i', $x5u)) {
            throw new \InvalidArgumentException('x5u must be an http(s) URL.');
        }
        $origid = trim($origid);
        if ($origid === '') {
            throw new \InvalidArgumentException('origid is required.');
        }

        $header = [
            'alg' => 'ES256',
            'ppt' => 'shaken',
            'typ' => 'passport',
            'x5u' => $x5u,
        ];
        $payload = [
            'attest' => $attest,
            'dest' => ['tn' => [$destTn]],
            'iat' => $iat ?? time(),
            'orig' => ['tn' => $origTn],
            'origid' => $origid,
        ];

        $passport = self::signEs256($header, $payload, $privateKeyPem);
        return $passport . ';info=<' . $x5u . '>;alg=ES256;ppt=shaken';
    }

    /** Digits only (SHAKEN TN claim); strips leading +. */
    public static function tnDigits(string $tn): string
    {
        $tn = trim($tn);
        if (str_starts_with($tn, '+')) {
            $tn = substr($tn, 1);
        }
        return preg_replace('/\D+/', '', $tn) ?? '';
    }

    /**
     * @param array<string,mixed> $header
     * @param array<string,mixed> $payload
     */
    public static function signEs256(array $header, array $payload, string $privateKeyPem): string
    {
        $h = self::b64url(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $p = self::b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signingInput = $h . '.' . $p;

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new \RuntimeException('Invalid STIR/SHAKEN private key (OpenSSL could not parse it).');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || (int) ($details['type'] ?? 0) !== OPENSSL_KEYTYPE_EC) {
            throw new \RuntimeException('STIR/SHAKEN private key must be an EC (P-256) key.');
        }
        $curve = (string) ($details['ec']['curve_name'] ?? '');
        if ($curve !== '' && !in_array($curve, ['prime256v1', 'P-256', 'secp256r1'], true)) {
            throw new \RuntimeException('STIR/SHAKEN private key must use the P-256 (prime256v1) curve.');
        }

        $der = '';
        if (!openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign PASSporT (openssl_sign).');
        }
        $raw = self::derEcdsaToJose($der, 32);
        return $signingInput . '.' . self::b64url($raw);
    }

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Convert OpenSSL DER ECDSA signature to fixed-length R||S for JWS. */
    public static function derEcdsaToJose(string $der, int $partLen): string
    {
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") {
            throw new \RuntimeException('Invalid ECDSA DER signature.');
        }
        $seqLen = self::readDerLen($der, $offset);
        $end = $offset + $seqLen;
        if (($der[$offset++] ?? '') !== "\x02") {
            throw new \RuntimeException('Invalid ECDSA DER signature (R).');
        }
        $rLen = self::readDerLen($der, $offset);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;
        if (($der[$offset++] ?? '') !== "\x02") {
            throw new \RuntimeException('Invalid ECDSA DER signature (S).');
        }
        $sLen = self::readDerLen($der, $offset);
        $s = substr($der, $offset, $sLen);
        $offset += $sLen;
        if ($offset !== $end) {
            throw new \RuntimeException('Invalid ECDSA DER signature length.');
        }
        return self::padInt($r, $partLen) . self::padInt($s, $partLen);
    }

    private static function readDerLen(string $der, int &$offset): int
    {
        $b = ord($der[$offset++] ?? "\x00");
        if (($b & 0x80) === 0) {
            return $b;
        }
        $n = $b & 0x7f;
        if ($n < 1 || $n > 3) {
            throw new \RuntimeException('Invalid DER length.');
        }
        $len = 0;
        for ($i = 0; $i < $n; $i++) {
            $len = ($len << 8) | ord($der[$offset++] ?? "\x00");
        }
        return $len;
    }

    private static function padInt(string $bin, int $len): string
    {
        $bin = ltrim($bin, "\x00");
        if (strlen($bin) > $len) {
            throw new \RuntimeException('ECDSA integer too large for JOSE.');
        }
        return str_pad($bin, $len, "\x00", STR_PAD_LEFT);
    }
}
