<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use Ownswitch\Support\TenantScope;
use PDO;

final class PrefixRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo, 'prefixes', ['prefix', 'destination'], 'none', ['prefix', 'destination'], ['id', 'prefix', 'destination']);
    }

    /** @return array<string,string> prefix => "prefix — Destination" */
    public function options(int $limit = 5000): array
    {
        $st = $this->pdo->query('SELECT prefix, destination FROM prefixes ORDER BY LENGTH(prefix) DESC, prefix ASC LIMIT ' . max(1, $limit));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = (string) $r['prefix'];
            $d = trim((string) ($r['destination'] ?? ''));
            $out[$p] = $d !== '' ? ($p . ' — ' . $d) : $p;
        }
        return $out;
    }

    public function destinationFor(string $prefix): ?string
    {
        $st = $this->pdo->prepare('SELECT destination FROM prefixes WHERE prefix = ? LIMIT 1');
        $st->execute([$prefix]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** Ensure a prefix row exists (used when importing tariffs). */
    public function upsert(string $prefix, string $destination): void
    {
        $prefix = preg_replace('/\D+/', '', $prefix) ?? '';
        if ($prefix === '') {
            return;
        }
        $destination = trim($destination);
        if ($destination === '') {
            $destination = 'Prefix ' . $prefix;
        }
        $this->pdo->prepare(
            'INSERT INTO prefixes (prefix, destination) VALUES (?,?)
             ON DUPLICATE KEY UPDATE destination = IF(VALUES(destination) <> "" AND VALUES(destination) NOT LIKE "Prefix %", VALUES(destination), destination)'
        )->execute([$prefix, $destination]);
    }

    /** @param list<int> $ids */
    public function deleteByIds(TenantScope $scope, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE id IN ({$ph})");
        $st->execute($ids);
        return $st->rowCount();
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function deleteFiltered(TenantScope $scope, array $filters, ?string $search, bool $allowAll = false): int
    {
        $hasFilter = false;
        foreach ($filters as $v) {
            if ($v !== null && $v !== '') {
                $hasFilter = true;
                break;
            }
        }
        if (!$hasFilter && ($search === null || trim($search) === '') && !$allowAll) {
            return 0;
        }
        [$where, $params] = $this->buildWhere($scope, $filters, $search);
        $st = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE {$where}");
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * @param list<int> $ids
     * @return list<string> updated prefix codes
     */
    public function updateDestinationByIds(array $ids, string $destination): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        $destination = trim($destination);
        if ($ids === [] || $destination === '') {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT prefix FROM `{$this->table}` WHERE id IN ({$ph})");
        $st->execute($ids);
        $prefixes = array_values(array_filter(array_map(
            static fn ($r) => (string) ($r['prefix'] ?? ''),
            $st->fetchAll(PDO::FETCH_ASSOC) ?: []
        )));
        if ($prefixes === []) {
            return [];
        }
        $upd = $this->pdo->prepare("UPDATE `{$this->table}` SET destination = ? WHERE id IN ({$ph})");
        $upd->execute([$destination, ...$ids]);
        return $prefixes;
    }

    /**
     * Match comma/space-separated dial codes (exact or starts-with) and set destination.
     *
     * @param list<string> $codes
     * @return list<string> updated prefix codes
     */
    public function updateDestinationByPrefixMatch(array $codes, string $destination, string $mode = 'starts'): array
    {
        $destination = trim($destination);
        $codes = array_values(array_unique(array_filter(array_map(
            static fn ($c) => preg_replace('/\D+/', '', (string) $c) ?? '',
            $codes
        ), static fn ($c) => $c !== '')));
        if ($codes === [] || $destination === '') {
            return [];
        }
        $mode = $mode === 'exact' ? 'exact' : 'starts';
        $clauses = [];
        $params = [];
        foreach ($codes as $code) {
            if ($mode === 'exact') {
                $clauses[] = 'prefix = ?';
                $params[] = $code;
            } else {
                $clauses[] = 'prefix LIKE ?';
                $params[] = $code . '%';
            }
        }
        $where = '(' . implode(' OR ', $clauses) . ')';
        $st = $this->pdo->prepare("SELECT prefix FROM `{$this->table}` WHERE {$where}");
        $st->execute($params);
        $prefixes = array_values(array_filter(array_map(
            static fn ($r) => (string) ($r['prefix'] ?? ''),
            $st->fetchAll(PDO::FETCH_ASSOC) ?: []
        )));
        if ($prefixes === []) {
            return [];
        }
        $upd = $this->pdo->prepare("UPDATE `{$this->table}` SET destination = ? WHERE {$where}");
        $upd->execute([$destination, ...$params]);
        return $prefixes;
    }

    /** Keep tariff destination labels in sync for the given prefix codes. */
    public function syncTariffDestinations(array $prefixes, string $destination): void
    {
        $destination = trim($destination);
        $prefixes = array_values(array_unique(array_filter(array_map('strval', $prefixes), static fn ($p) => $p !== '')));
        if ($prefixes === [] || $destination === '') {
            return;
        }
        $ph = implode(',', array_fill(0, count($prefixes), '?'));
        try {
            $this->pdo->prepare("UPDATE rate_plan_tariffs SET destination = ? WHERE prefix IN ({$ph})")
                ->execute([$destination, ...$prefixes]);
        } catch (\PDOException) {
        }
        try {
            $this->pdo->prepare("UPDATE carrier_tariffs SET destination = ? WHERE prefix IN ({$ph})")
                ->execute([$destination, ...$prefixes]);
        } catch (\PDOException) {
        }
    }
}
