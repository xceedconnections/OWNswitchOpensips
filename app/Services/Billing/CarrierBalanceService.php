<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

use PDO;
use PDOException;

/**
 * Provider (carrier) wallet. Sign: amount > 0 credits, < 0 debits.
 * Low / negative balance never blocks trunks — balance is bookkeeping only.
 */
final class CarrierBalanceService
{
    public const TYPES = ['topup', 'adjustment', 'refund', 'call_charge', 'reversal'];

    public function __construct(private PDO $pdo)
    {
    }

    public function ensureSchema(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS carrier_balances (
                    carrier_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                    balance    DECIMAL(18,6) NOT NULL DEFAULT 0,
                    currency   CHAR(3) NOT NULL DEFAULT \'USD\',
                    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS carrier_ledger_entries (
                    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    carrier_id         BIGINT UNSIGNED NOT NULL,
                    entry_type         VARCHAR(24) NOT NULL,
                    amount             DECIMAL(18,6) NOT NULL,
                    currency           CHAR(3) NOT NULL DEFAULT \'USD\',
                    affects_balance    TINYINT(1) NOT NULL DEFAULT 1,
                    cdr_id             BIGINT UNSIGNED NULL,
                    source_system      VARCHAR(32) NULL,
                    external_ref       VARCHAR(191) NULL,
                    idempotency_key    VARCHAR(191) NOT NULL,
                    description        VARCHAR(255) NULL,
                    created_by         BIGINT UNSIGNED NULL,
                    created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                    UNIQUE KEY uq_carrier_ledger_idem (idempotency_key),
                    KEY ix_carrier_ledger_carrier (carrier_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable $e) {
            error_log('[ownswitch] carrier balance schema: ' . $e->getMessage());
        }
    }

    public function ensureBalanceRow(int $carrierId, string $currency = 'USD'): void
    {
        $this->ensureSchema();
        $this->pdo->prepare(
            'INSERT IGNORE INTO carrier_balances (carrier_id, balance, currency) VALUES (?,?,?)'
        )->execute([$carrierId, '0.000000', $currency]);
    }

    /**
     * @param array{
     *   carrier_id:int,entry_type:string,amount:string,currency?:string,idempotency_key:string,
     *   affects_balance?:bool,cdr_id?:?int,source_system?:?string,external_ref?:?string,
     *   description?:?string,created_by?:?int
     * } $e
     * @return array{id:int,duplicate:bool}
     */
    public function post(array $e): array
    {
        if (!in_array($e['entry_type'], self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown carrier ledger entry type');
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $e['amount'])) {
            throw new \InvalidArgumentException('Amount must be a decimal string');
        }
        if ($e['idempotency_key'] === '') {
            throw new \InvalidArgumentException('idempotency_key is required');
        }
        $affects = $e['affects_balance'] ?? true;
        $cid = (int) $e['carrier_id'];
        $currency = (string) ($e['currency'] ?? 'USD');

        $this->ensureBalanceRow($cid, $currency);

        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare('SELECT balance FROM carrier_balances WHERE carrier_id = ? FOR UPDATE')->execute([$cid]);

            $dup = $this->pdo->prepare('SELECT id FROM carrier_ledger_entries WHERE idempotency_key = ?');
            $dup->execute([$e['idempotency_key']]);
            $existing = $dup->fetchColumn();
            if ($existing !== false) {
                $this->pdo->commit();
                return ['id' => (int) $existing, 'duplicate' => true];
            }

            try {
                $st = $this->pdo->prepare(
                    'INSERT INTO carrier_ledger_entries
                        (carrier_id, entry_type, amount, currency, affects_balance, cdr_id, source_system, external_ref, idempotency_key, description, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $st->execute([
                    $cid,
                    $e['entry_type'],
                    RatingService::round($e['amount'], 6),
                    $currency,
                    $affects ? 1 : 0,
                    $e['cdr_id'] ?? null,
                    $e['source_system'] ?? null,
                    $e['external_ref'] ?? null,
                    $e['idempotency_key'],
                    $e['description'] ?? null,
                    $e['created_by'] ?? null,
                ]);
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    $dup->execute([$e['idempotency_key']]);
                    $this->pdo->commit();
                    return ['id' => (int) $dup->fetchColumn(), 'duplicate' => true];
                }
                throw $ex;
            }
            $id = (int) $this->pdo->lastInsertId();
            if ($affects) {
                $this->pdo->prepare('UPDATE carrier_balances SET balance = balance + ?, currency = ? WHERE carrier_id = ?')
                    ->execute([RatingService::round($e['amount'], 6), $currency, $cid]);
            }
            $this->pdo->commit();
            return ['id' => $id, 'duplicate' => false];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function balance(int $carrierId): string
    {
        $this->ensureBalanceRow($carrierId);
        $st = $this->pdo->prepare('SELECT balance FROM carrier_balances WHERE carrier_id = ?');
        $st->execute([$carrierId]);
        $v = $st->fetchColumn();
        return $v === false ? '0.000000' : RatingService::round((string) $v, 6);
    }

    /** @return array<int,string> carrier_id => balance */
    public function balancesFor(array $carrierIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $carrierIds), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $this->ensureSchema();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT carrier_id, balance FROM carrier_balances WHERE carrier_id IN ({$ph})");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['carrier_id']] = RatingService::round((string) $row['balance'], 6);
        }
        foreach ($ids as $id) {
            if (!isset($out[$id])) {
                $out[$id] = '0.000000';
            }
        }
        return $out;
    }

    /** Debit provider for a connected call buy cost. Never throws to CDR path callers — logs instead. */
    public function chargeCall(int $carrierId, string $cost, int $cdrId, string $key, string $dst, string $currency = 'USD'): void
    {
        if ($carrierId <= 0 || bccomp($cost, '0', 6) <= 0) {
            return;
        }
        try {
            $this->post([
                'carrier_id' => $carrierId,
                'entry_type' => 'call_charge',
                'amount' => bcmul($cost, '-1', 6),
                'currency' => $currency,
                'cdr_id' => $cdrId,
                'source_system' => 'cdr',
                'external_ref' => $key,
                'idempotency_key' => 'cdr:' . $key . ':carrier',
                'description' => 'Call buy ' . $dst,
            ]);
        } catch (\Throwable $e) {
            error_log('[ownswitch] carrier call charge: ' . $e->getMessage());
        }
    }
}
