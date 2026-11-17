<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

/** CallerID groups (staff-managed) and the CLI numbers inside each group. */
final class CallerIdGroupRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'callerid_groups',
            ['name', 'customer_id', 'status'],
            'none',
            ['name'],
            ['id', 'name', 'status', 'created_at']
        );
        $this->ensureTrunkColumn();
    }

    /** Idempotent: trunk can store a CallerID group even if migrate.php was not run yet. */
    public function ensureTrunkColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $st = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'callerid_group_id'"
            );
            if ((int) $st->fetchColumn() === 0) {
                $this->pdo->exec(
                    'ALTER TABLE carrier_trunks ADD COLUMN callerid_group_id BIGINT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_carrier_trunks_callerid_group (callerid_group_id)'
                );
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] callerid trunk column: ' . $e->getMessage());
        }
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int,pages:int}
     */
    public function searchNumbers(int $groupId, string $q, int $page, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $where = 'callerid_group_id = ?';
        $params = [$groupId];
        $q = trim($q);
        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q) ?? '';
            $name = '%' . $this->like($q) . '%';
            if ($digits !== '') {
                $where .= ' AND (cli_number LIKE ? OR name LIKE ?)';
                $params[] = '%' . $digits . '%';
                $params[] = $name;
            } else {
                $where .= ' AND name LIKE ?';
                $params[] = $name;
            }
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM callerid_group_numbers WHERE {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;
        $st = $this->pdo->prepare(
            "SELECT * FROM callerid_group_numbers WHERE {$where} ORDER BY cli_number, id LIMIT {$perPage} OFFSET {$offset}"
        );
        $st->execute($params);
        return [
            'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * Find a CLI in every group.
     *
     * @return list<array<string,mixed>>
     */
    public function searchAll(string $q, int $limit = 200): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $name = '%' . $this->like($q) . '%';
        $where = ['n.name LIKE ?', 'g.name LIKE ?'];
        $params = [$name, $name];
        if ($digits !== '') {
            $where[] = 'n.cli_number LIKE ?';
            $params[] = '%' . $digits . '%';
        }
        $sql = 'SELECT n.id, n.cli_number, n.name, n.status, g.id AS group_id, g.name AS group_name
                FROM callerid_group_numbers n
                INNER JOIN callerid_groups g ON g.id = n.callerid_group_id
                WHERE ' . implode(' OR ', $where) . '
                ORDER BY n.cli_number, g.name
                LIMIT ' . $limit;
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function exportRows(int $groupId): array
    {
        $st = $this->pdo->prepare(
            'SELECT cli_number, name, status FROM callerid_group_numbers WHERE callerid_group_id = ? ORDER BY cli_number, id'
        );
        $st->execute([$groupId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array{prefix:string,cli_number:string,name:?string,status:string} $row */
    public function addNumber(int $groupId, array $row): int
    {
        $this->pdo->prepare(
            'INSERT INTO callerid_group_numbers (callerid_group_id, prefix, cli_number, name, status) VALUES (?,?,?,?,?)'
        )->execute([$groupId, $row['prefix'], $row['cli_number'], $row['name'], $row['status']]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert CLIs, skipping numbers already in the group.
     *
     * @param list<array{cli:string,name:?string}> $rows
     * @return array{added:int,skipped:int}
     */
    public function insertClis(int $groupId, array $rows, string $status): array
    {
        $seen = $this->existingDigits($groupId);
        $added = 0;
        $skipped = 0;
        $batch = [];
        foreach ($rows as $row) {
            $cli = $row['cli'];
            if (isset($seen[$cli])) {
                $skipped++;
                continue;
            }
            $seen[$cli] = true;
            $batch[] = $row;
            if (count($batch) >= 400) {
                $added += $this->insertChunk($groupId, $batch, $status);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $added += $this->insertChunk($groupId, $batch, $status);
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /** @return array<string,mixed>|null */
    public function findNumber(int $groupId, int $numberId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM callerid_group_numbers WHERE id = ? AND callerid_group_id = ? LIMIT 1');
        $st->execute([$numberId, $groupId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    public function deleteNumber(int $groupId, int $numberId): bool
    {
        $st = $this->pdo->prepare('DELETE FROM callerid_group_numbers WHERE id = ? AND callerid_group_id = ?');
        $st->execute([$numberId, $groupId]);
        return $st->rowCount() > 0;
    }

    /** @param list<int|string> $ids */
    public function deleteByIds(int $groupId, array $ids): int
    {
        $clean = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $clean[$n] = $n;
            }
        }
        $n = 0;
        foreach (array_chunk(array_values($clean), 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->pdo->prepare("DELETE FROM callerid_group_numbers WHERE callerid_group_id = ? AND id IN ({$ph})");
            $st->execute([$groupId, ...$chunk]);
            $n += $st->rowCount();
        }
        return $n;
    }

    /**
     * Remove numbers whose digits are in $clis (stored values may include a leading +).
     *
     * @param list<string> $clis
     */
    public function deleteByClis(int $groupId, array $clis): int
    {
        $want = [];
        foreach ($clis as $cli) {
            $d = preg_replace('/\D+/', '', $cli) ?? '';
            if ($d !== '') {
                $want[$d] = true;
            }
        }
        if ($want === []) {
            return 0;
        }
        $st = $this->pdo->prepare('SELECT id, cli_number FROM callerid_group_numbers WHERE callerid_group_id = ?');
        $st->execute([$groupId]);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $d = preg_replace('/\D+/', '', (string) ($row['cli_number'] ?? '')) ?? '';
            if ($d !== '' && isset($want[$d])) {
                $ids[] = (int) $row['id'];
            }
        }
        return $this->deleteByIds($groupId, $ids);
    }

    /** @return array<string,true> */
    private function existingDigits(int $groupId): array
    {
        $st = $this->pdo->prepare('SELECT cli_number FROM callerid_group_numbers WHERE callerid_group_id = ?');
        $st->execute([$groupId]);
        $seen = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $cli) {
            $d = preg_replace('/\D+/', '', (string) $cli) ?? '';
            if ($d !== '') {
                $seen[$d] = true;
            }
        }
        return $seen;
    }

    /**
     * @param list<array{cli:string,name:?string}> $rows
     */
    private function insertChunk(int $groupId, array $rows, string $status): int
    {
        if ($rows === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($rows), '(?,?,?,?,?)'));
        $params = [];
        foreach ($rows as $row) {
            $params[] = $groupId;
            $params[] = '*';
            $params[] = $row['cli'];
            $params[] = $row['name'];
            $params[] = $status;
        }
        $this->pdo->prepare(
            "INSERT INTO callerid_group_numbers (callerid_group_id, prefix, cli_number, name, status) VALUES {$placeholders}"
        )->execute($params);
        return count($rows);
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
