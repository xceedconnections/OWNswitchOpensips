<?php

declare(strict_types=1);

namespace Ownswitch\Services\Tariffs;

use Ownswitch\Repositories\AreaCodeRepository;
use Ownswitch\Support\SpreadsheetReader;
use Ownswitch\Support\SpreadsheetWriter;
use PDO;
use RuntimeException;

final class AreaCodeCsvService
{
    public const HEADERS = ['code', 'name', 'notes'];
    public const REQUIRED = ['code', 'name'];

    public function __construct(private PDO $db)
    {
    }

    public function exportCsv(): string
    {
        $rows = [];
        foreach ($this->allRows() as $row) {
            $rows[] = [$row['code'], $row['name'], $row['notes']];
        }
        return self::toCsv(self::HEADERS, $rows);
    }

    public function templateCsv(): string
    {
        return self::toCsv(self::HEADERS, self::templateRows());
    }

    public function exportXlsx(): string
    {
        $rows = [];
        foreach ($this->allRows() as $row) {
            $rows[] = [$row['code'], $row['name'], $row['notes']];
        }
        return SpreadsheetWriter::xlsx(self::HEADERS, $rows, 'Area codes');
    }

    public function templateXlsx(): string
    {
        return SpreadsheetWriter::xlsx(self::HEADERS, self::templateRows(), 'Area codes');
    }

    public function exportExcelXml(): string
    {
        $rows = [];
        foreach ($this->allRows() as $row) {
            $rows[] = [$row['code'], $row['name'], $row['notes']];
        }
        return SpreadsheetWriter::excelXml(self::HEADERS, $rows, 'Area codes');
    }

    public function templateExcelXml(): string
    {
        return SpreadsheetWriter::excelXml(self::HEADERS, self::templateRows(), 'Area codes');
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private static function toCsv(array $headers, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new RuntimeException('temp');
        }
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);
        return $csv;
    }

    /** @return list<array{code:string,name:string,notes:string}> */
    private function allRows(): array
    {
        $st = $this->db->query('SELECT code, name, notes FROM area_codes ORDER BY code');
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'notes' => (string) ($row['notes'] ?? ''),
            ];
        }
        return $rows;
    }

    /** @return list<list<string>> */
    private static function templateRows(): array
    {
        return [
            ['1213', 'USA LA', ''],
            ['1203', 'USA Connecticut', ''],
        ];
    }

    /**
     * @param array<string,string> $map
     * @return array{ok:bool,errors:list<string>,rows:list<array{code:string,name:string,notes:?string}>}
     */
    public function previewMapped(array $headers, array $dataRows, array $map): array
    {
        $mapped = SpreadsheetReader::mapRows($headers, $dataRows, $map);
        $errors = [];
        $rows = [];
        $seen = [];
        foreach ($mapped as $i => $assoc) {
            $line = $i + 2;
            $code = self::digits((string) ($assoc['code'] ?? ''));
            $name = trim((string) ($assoc['name'] ?? ''));
            $notes = trim((string) ($assoc['notes'] ?? ''));
            if ($code === '') {
                $errors[] = "Row {$line}: invalid area code (digits only)";
                continue;
            }
            if (strlen($code) > 32) {
                $errors[] = "Row {$line}: area code too long";
                continue;
            }
            if ($name === '') {
                $errors[] = "Row {$line}: name / description is required";
                continue;
            }
            if (mb_strlen($name) > 120) {
                $errors[] = "Row {$line}: name too long (max 120)";
                continue;
            }
            if (mb_strlen($notes) > 255) {
                $errors[] = "Row {$line}: notes too long (max 255)";
                continue;
            }
            if (isset($seen[$code])) {
                $errors[] = "Duplicate code {$code} in file (rows {$seen[$code]} and {$line})";
                continue;
            }
            $seen[$code] = $line;
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'notes' => $notes !== '' ? $notes : null,
            ];
        }
        return ['ok' => $errors === [], 'errors' => $errors, 'rows' => $rows];
    }

    /** @param list<array{code:string,name:string,notes:?string}> $rows */
    public function importRows(array $rows): int
    {
        $repo = new AreaCodeRepository($this->db);
        $n = 0;
        foreach ($rows as $row) {
            $repo->upsert($row['code'], $row['name'], $row['notes'] ?? null);
            $n++;
        }
        return $n;
    }

    /** Excel often stores dial codes as numbers (1213 or 1213.0). Keep the digits, drop a trailing .0. */
    private static function digits(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^[+-]?\d+\.0+$/', $raw)) {
            $raw = preg_replace('/\.0+$/', '', $raw) ?? $raw;
        }
        return preg_replace('/\D+/', '', $raw) ?? '';
    }
}
