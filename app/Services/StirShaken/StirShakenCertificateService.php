<?php

declare(strict_types=1);

namespace Ownswitch\Services\StirShaken;

use Ownswitch\App;
use Ownswitch\Support\Crypto;
use Ownswitch\Support\Env;
use PDO;

/** Persist and resolve STIR/SHAKEN certificates for admin UI and outbound signing. */
final class StirShakenCertificateService
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 500): array
    {
        $st = $this->db->query(
            'SELECT id, name, public_token, x5u_url, origid, issuer, subject, valid_from, valid_to, status, notes, created_at, updated_at
             FROM stir_certificates
             ORDER BY name
             LIMIT ' . max(1, min(2000, $limit))
        );
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return array<string,string> id => name for SIP user dropdown */
    public function options(bool $activeOnly = true): array
    {
        $out = ['' => '— None (STIR/SHAKEN off) —'];
        try {
            $sql = 'SELECT id, name FROM stir_certificates';
            if ($activeOnly) {
                $sql .= " WHERE status = 'active'";
            }
            $sql .= ' ORDER BY name LIMIT 500';
            foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $out[(string) $row['id']] = (string) $row['name'];
            }
        } catch (\Throwable) {
            // table not migrated yet
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM stir_certificates WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByPublicToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $st = $this->db->prepare(
            "SELECT * FROM stir_certificates WHERE public_token = ? AND status = 'active' LIMIT 1"
        );
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array{ok:true,id:int}|array{ok:false,error:string}
     */
    public function create(
        string $name,
        string $certPem,
        string $privateKeyPem,
        string $x5uUrl = '',
        string $origid = '',
        string $status = 'active',
        string $notes = ''
    ): array {
        $parsed = $this->validateMaterials($certPem, $privateKeyPem);
        if (isset($parsed['error'])) {
            return ['ok' => false, 'error' => $parsed['error']];
        }
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Name is required.'];
        }
        $token = bin2hex(random_bytes(16));
        $origid = trim($origid) !== '' ? trim($origid) : $this->newOrigid();
        $x5uUrl = trim($x5uUrl);
        $status = $status === 'disabled' ? 'disabled' : 'active';
        $notes = $notes !== '' ? mb_substr(trim($notes), 0, 255) : null;
        try {
            $st = $this->db->prepare(
                'INSERT INTO stir_certificates
                 (name, public_token, cert_pem, private_key_enc, x5u_url, origid, issuer, subject, valid_from, valid_to, status, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                mb_substr($name, 0, 120),
                $token,
                $parsed['cert_pem'],
                Crypto::encrypt($parsed['key_pem']),
                $x5uUrl !== '' ? mb_substr($x5uUrl, 0, 512) : null,
                mb_substr($origid, 0, 64),
                $parsed['issuer'],
                $parsed['subject'],
                $parsed['valid_from'],
                $parsed['valid_to'],
                $status,
                $notes,
            ]);
            return ['ok' => true, 'id' => (int) $this->db->lastInsertId()];
        } catch (\PDOException $e) {
            $msg = ((int) ($e->errorInfo[1] ?? 0) === 1062)
                ? 'A certificate with that name already exists.'
                : 'Could not save the certificate.';
            return ['ok' => false, 'error' => $msg];
        }
    }

    /**
     * @return array{ok:true}|array{ok:false,error:string}
     */
    public function update(
        int $id,
        string $name,
        ?string $certPem,
        ?string $privateKeyPem,
        string $x5uUrl = '',
        string $origid = '',
        string $status = 'active',
        string $notes = ''
    ): array {
        $row = $this->find($id);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Certificate not found.'];
        }
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Name is required.'];
        }
        $certPem = $certPem !== null ? trim($certPem) : '';
        $privateKeyPem = $privateKeyPem !== null ? trim($privateKeyPem) : '';
        $meta = [
            'cert_pem' => (string) $row['cert_pem'],
            'key_enc' => (string) $row['private_key_enc'],
            'issuer' => $row['issuer'] ?? null,
            'subject' => $row['subject'] ?? null,
            'valid_from' => $row['valid_from'] ?? null,
            'valid_to' => $row['valid_to'] ?? null,
        ];
        if ($certPem !== '' || $privateKeyPem !== '') {
            $useCert = $certPem !== '' ? $certPem : (string) $row['cert_pem'];
            $useKey = $privateKeyPem !== '' ? $privateKeyPem : Crypto::decrypt((string) $row['private_key_enc']);
            $parsed = $this->validateMaterials($useCert, $useKey);
            if (isset($parsed['error'])) {
                return ['ok' => false, 'error' => $parsed['error']];
            }
            $meta['cert_pem'] = $parsed['cert_pem'];
            $meta['key_enc'] = Crypto::encrypt($parsed['key_pem']);
            $meta['issuer'] = $parsed['issuer'];
            $meta['subject'] = $parsed['subject'];
            $meta['valid_from'] = $parsed['valid_from'];
            $meta['valid_to'] = $parsed['valid_to'];
        }
        $origid = trim($origid) !== '' ? trim($origid) : (string) $row['origid'];
        $x5uUrl = trim($x5uUrl);
        $status = $status === 'disabled' ? 'disabled' : 'active';
        $notes = $notes !== '' ? mb_substr(trim($notes), 0, 255) : null;
        try {
            $this->db->prepare(
                'UPDATE stir_certificates
                 SET name = ?, cert_pem = ?, private_key_enc = ?, x5u_url = ?, origid = ?,
                     issuer = ?, subject = ?, valid_from = ?, valid_to = ?, status = ?, notes = ?
                 WHERE id = ?'
            )->execute([
                mb_substr($name, 0, 120),
                $meta['cert_pem'],
                $meta['key_enc'],
                $x5uUrl !== '' ? mb_substr($x5uUrl, 0, 512) : null,
                mb_substr($origid, 0, 64),
                $meta['issuer'],
                $meta['subject'],
                $meta['valid_from'],
                $meta['valid_to'],
                $status,
                $notes,
                $id,
            ]);
            return ['ok' => true];
        } catch (\PDOException $e) {
            $msg = ((int) ($e->errorInfo[1] ?? 0) === 1062)
                ? 'A certificate with that name already exists.'
                : 'Could not save the certificate.';
            return ['ok' => false, 'error' => $msg];
        }
    }

    public function delete(int $id): void
    {
        $this->db->prepare('UPDATE sip_accounts SET stir_certificate_id = NULL WHERE stir_certificate_id = ?')
            ->execute([$id]);
        $this->db->prepare('DELETE FROM stir_certificates WHERE id = ?')->execute([$id]);
    }

    /** Absolute x5u URL for signing / display. */
    public function resolveX5u(array $cert): string
    {
        $custom = trim((string) ($cert['x5u_url'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $token = (string) ($cert['public_token'] ?? '');
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        if ($base === '') {
            $host = (string) App::config('integrations.opensips.sip_domain', 'localhost');
            $base = 'https://' . $host;
        }
        return $base . '/stir/cert/' . $token . '.pem';
    }

    /**
     * Build SIP Identity for an outbound leg, or null when STIR is off / incomplete.
     *
     * @param array<string,mixed> $account sip_accounts row
     */
    public function identityForOutbound(array $account, string $origTn, string $destTn): ?string
    {
        $attest = strtoupper(trim((string) ($account['stir_attestation'] ?? '')));
        $certId = (int) ($account['stir_certificate_id'] ?? 0);
        if ($certId <= 0 || !in_array($attest, ['A', 'B', 'C'], true)) {
            return null;
        }
        $cert = $this->find($certId);
        if ($cert === null || ($cert['status'] ?? '') !== 'active') {
            return null;
        }
        try {
            $key = Crypto::decrypt((string) $cert['private_key_enc']);
            return StirShakenPassport::identityHeader(
                $key,
                $this->resolveX5u($cert),
                $attest,
                $origTn,
                $destTn,
                (string) $cert['origid']
            );
        } catch (\Throwable $e) {
            error_log('[ownswitch] stir identity: ' . $e->getMessage());
            return null;
        }
    }

    public function ensureSipColumns(): void
    {
        try {
            $st = $this->db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'stir_certificate_id'"
            );
            if ((int) $st->fetchColumn() === 0) {
                $this->db->exec(
                    'ALTER TABLE sip_accounts
                     ADD COLUMN stir_certificate_id BIGINT UNSIGNED NULL DEFAULT NULL,
                     ADD COLUMN stir_attestation VARCHAR(1) NULL DEFAULT NULL,
                     ADD KEY idx_sip_accounts_stir_cert (stir_certificate_id)'
                );
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] stir sip columns ensure: ' . $e->getMessage());
        }
    }

    /**
     * @return array{cert_pem:string,key_pem:string,issuer:?string,subject:?string,valid_from:?string,valid_to:?string}|array{error:string}
     */
    public function validateMaterials(string $certPem, string $privateKeyPem): array
    {
        $certPem = $this->normalizePem($certPem, 'CERTIFICATE');
        $privateKeyPem = $this->normalizePem($privateKeyPem, 'PRIVATE KEY|EC PRIVATE KEY');
        if ($certPem === null) {
            return ['error' => 'Certificate PEM is missing or invalid (need a BEGIN CERTIFICATE block).'];
        }
        if ($privateKeyPem === null) {
            return ['error' => 'Private key PEM is missing or invalid.'];
        }
        $x509 = openssl_x509_read($certPem);
        if ($x509 === false) {
            return ['error' => 'OpenSSL could not parse the certificate PEM.'];
        }
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            return ['error' => 'OpenSSL could not parse the private key PEM.'];
        }
        if (!openssl_x509_check_private_key($x509, $key)) {
            return ['error' => 'Private key does not match the certificate.'];
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || (int) ($details['type'] ?? 0) !== OPENSSL_KEYTYPE_EC) {
            return ['error' => 'STIR/SHAKEN requires an EC (P-256) private key.'];
        }
        $info = openssl_x509_parse($x509, false);
        if (!is_array($info)) {
            return ['error' => 'Could not read certificate subject/issuer.'];
        }
        $issuer = $this->dnString($info['issuer'] ?? null);
        $subject = $this->dnString($info['subject'] ?? null);
        $validFrom = isset($info['validFrom_time_t'])
            ? gmdate('Y-m-d H:i:s', (int) $info['validFrom_time_t'])
            : null;
        $validTo = isset($info['validTo_time_t'])
            ? gmdate('Y-m-d H:i:s', (int) $info['validTo_time_t'])
            : null;
        return [
            'cert_pem' => $certPem,
            'key_pem' => $privateKeyPem,
            'issuer' => $issuer,
            'subject' => $subject,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ];
    }

    private function normalizePem(string $pem, string $types): ?string
    {
        $pem = str_replace(["\r\n", "\r"], "\n", trim($pem));
        if ($pem === '') {
            return null;
        }
        if (!preg_match('/-----BEGIN (' . $types . ')-----[\s\S]+?-----END \1-----/', $pem, $m)) {
            return null;
        }
        return $m[0];
    }

    /** @param mixed $dn */
    private function dnString(mixed $dn): ?string
    {
        if (!is_array($dn)) {
            return null;
        }
        $parts = [];
        foreach (['CN', 'O', 'OU', 'C', 'ST', 'L'] as $k) {
            if (!empty($dn[$k]) && is_string($dn[$k])) {
                $parts[] = $k . '=' . $dn[$k];
            }
        }
        if ($parts === []) {
            foreach ($dn as $k => $v) {
                if (is_string($k) && is_string($v) && $v !== '') {
                    $parts[] = $k . '=' . $v;
                }
            }
        }
        $s = implode(', ', $parts);
        return $s !== '' ? mb_substr($s, 0, 255) : null;
    }

    private function newOrigid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $h = bin2hex($data);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
            . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }
}
