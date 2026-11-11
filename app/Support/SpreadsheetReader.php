<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Read CSV or simple XLSX (first sheet) into header + rows. No Composer deps.
 */
final class SpreadsheetReader
{
    /**
     * @return array{headers:list<string>,rows:list<list<string>>,error:?string}
     */
    public static function fromUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['headers' => [], 'rows' => [], 'error' => 'Upload failed.'];
        }
        if (($file['size'] ?? 0) > 8_000_000) {
            return ['headers' => [], 'rows' => [], 'error' => 'File too large (max 8 MB).'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = strtolower((string) ($file['name'] ?? ''));
        $raw = (string) file_get_contents($tmp);
        if ($raw === '') {
            return ['headers' => [], 'rows' => [], 'error' => 'Empty file.'];
        }
        if (str_ends_with($name, '.xlsx') || str_starts_with($raw, 'PK')) {
            return self::fromXlsx($tmp);
        }
        $trim = ltrim($raw);
        if (
            str_contains($raw, 'urn:schemas-microsoft-com:office:spreadsheet')
            || str_starts_with($trim, '<Workbook')
        ) {
            return self::fromExcelXml($raw);
        }
        if (str_ends_with($name, '.xls')) {
            return ['headers' => [], 'rows' => [], 'error' => 'Save this Excel file as .xlsx or CSV.'];
        }
        return self::fromCsv($raw);
    }

    /**
     * @return array{headers:list<string>,rows:list<list<string>>,error:?string}
     */
    public static function fromCsv(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return ['headers' => [], 'rows' => [], 'error' => 'Unable to parse CSV.'];
        }
        $delimiter = self::delimiter($contents);
        fwrite($fh, $contents);
        rewind($fh);
        $header = fgetcsv($fh, 0, $delimiter);
        if ($header === false) {
            fclose($fh);
            return ['headers' => [], 'rows' => [], 'error' => 'Empty CSV.'];
        }
        $headers = array_map(static fn ($h) => trim((string) $h), $header);
        $rows = [];
        while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
            if ($data === [null] || (count($data) === 1 && trim((string) $data[0]) === '')) {
                continue;
            }
            $rows[] = array_map(static fn ($c) => trim((string) $c), $data);
        }
        fclose($fh);
        return self::finish($headers, $rows);
    }

    /**
     * Minimal XLSX reader (sharedStrings + first sheet rows).
     *
     * @return array{headers:list<string>,rows:list<list<string>>,error:?string}
     */
    public static function fromXlsx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['headers' => [], 'rows' => [], 'error' => 'XLSX needs PHP zip extension — save as CSV instead.'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return ['headers' => [], 'rows' => [], 'error' => 'Cannot open XLSX file.'];
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($ss) && $ss !== '') {
            if (preg_match_all('/<si[^>]*>(.*?)<\/si>/s', $ss, $m)) {
                foreach ($m[1] as $si) {
                    if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                        $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    } else {
                        $shared[] = '';
                    }
                }
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!is_string($sheet) || $sheet === '') {
            return ['headers' => [], 'rows' => [], 'error' => 'XLSX has no sheet1.'];
        }
        $rowsXml = [];
        if (preg_match_all('/<row\\b[^>]*>(.*?)<\\/row>/s', $sheet, $rm)) {
            foreach ($rm[1] as $rowXml) {
                $row = [];
                if (preg_match_all('/<c\\b([^>]*?)(?:\\/>|>(.*?)<\\/c>)/s', $rowXml, $cm, PREG_SET_ORDER)) {
                    $byCol = [];
                    $max = -1;
                    foreach ($cm as $c) {
                        $attrs = $c[1];
                        $col = 0;
                        if (preg_match('/r="([A-Z]+)\\d+"/i', $attrs, $rmatch)) {
                            $col = self::colIndex($rmatch[1]);
                        }
                        $max = max($max, $col);
                        $byCol[$col] = self::cellText($attrs, $c[2] ?? '', $shared);
                    }
                    for ($i = 0; $i <= $max; $i++) {
                        $row[] = $byCol[$i] ?? '';
                    }
                }
                if ($row !== [] && implode('', $row) !== '') {
                    $rowsXml[] = $row;
                }
            }
        }
        if ($rowsXml === []) {
            return ['headers' => [], 'rows' => [], 'error' => 'No data rows in XLSX.'];
        }
        $headers = array_map(static fn ($h) => trim((string) $h), $rowsXml[0]);
        $data = array_slice($rowsXml, 1);
        return self::finish($headers, $data);
    }

    /**
     * Excel 2003 XML (SpreadsheetML). Used when the PHP zip extension is unavailable.
     *
     * @return array{headers:list<string>,rows:list<list<string>>,error:?string}
     */
    public static function fromExcelXml(string $xml): array
    {
        if (!preg_match_all('/<Row\\b[^>]*>(.*?)<\\/Row>/s', $xml, $rm)) {
            return ['headers' => [], 'rows' => [], 'error' => 'Excel file has no rows.'];
        }
        $rowsXml = [];
        foreach ($rm[1] as $rowXml) {
            if (!preg_match_all('/<Cell\\b([^>]*)>(.*?)<\\/Cell>/s', $rowXml, $cm, PREG_SET_ORDER)) {
                continue;
            }
            $byCol = [];
            $max = 0;
            $col = 1;
            foreach ($cm as $c) {
                if (preg_match('/ss:Index="(\\d+)"/', $c[1], $im)) {
                    $col = (int) $im[1];
                }
                $text = '';
                if (preg_match('/<Data\\b[^>]*>(.*?)<\\/Data>/s', $c[2], $dm)) {
                    $text = trim(html_entity_decode($dm[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                }
                $byCol[$col] = $text;
                $max = max($max, $col);
                $col++;
            }
            $row = [];
            for ($i = 1; $i <= $max; $i++) {
                $row[] = $byCol[$i] ?? '';
            }
            if ($row !== [] && implode('', $row) !== '') {
                $rowsXml[] = $row;
            }
        }
        if ($rowsXml === []) {
            return ['headers' => [], 'rows' => [], 'error' => 'Excel file has no rows.'];
        }
        $headers = array_map(static fn ($h) => trim((string) $h), $rowsXml[0]);
        return self::finish($headers, array_slice($rowsXml, 1));
    }

    /**
     * Comma, tab, or semicolon. A two-column area-code paste is usually tab-separated.
     */
    private static function delimiter(string $contents): string
    {
        $line = '';
        foreach (preg_split("/\r\n|\n|\r/", substr($contents, 0, 8192)) ?: [] as $candidate) {
            if (trim($candidate) !== '') {
                $line = $candidate;
                break;
            }
        }
        $counts = [
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
            ';' => substr_count($line, ';'),
        ];
        arsort($counts);
        $best = array_key_first($counts);
        return ($best !== null && $counts[$best] > 0) ? $best : ',';
    }

    /**
     * A file whose first row is "1571, US-VA" has no titles. Keep titled files unchanged.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @return array{0:list<string>,1:list<list<string>>}
     */
    private static function promoteHeaderless(array $headers, array $rows): array
    {
        $known = [
            'code', 'name', 'notes', 'note', 'prefix', 'destination', 'dest', 'rate', 'price',
            'area', 'area_code', 'areacode', 'description', 'desc', 'city', 'npa', 'comment',
            'comments', 'country', 'region', 'location', 'initial_increment', 'subsequent_increment',
            'connect_fee', 'status', 'effective_from', 'effective_to',
        ];
        foreach ($headers as $h) {
            $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $h) ?? $h, '_'));
            if (in_array($key, $known, true)) {
                return [$headers, $rows];
            }
        }
        $first = trim($headers[0] ?? '');
        $digits = preg_replace('/\D+/', '', $first) ?? '';
        if ($digits === '' || strlen($digits) < 2 || !preg_match('/^[+]?\d[\d\s\-().]*$/', $first)) {
            return [$headers, $rows];
        }
        $width = count($headers);
        foreach ($rows as $row) {
            $width = max($width, count($row));
        }
        $labels = ['code', 'name', 'notes'];
        $synth = [];
        for ($i = 0; $i < $width; $i++) {
            $synth[] = $labels[$i] ?? ('column_' . ($i + 1));
        }
        $headRow = array_map(static fn ($c) => trim((string) $c), $headers);
        while (count($headRow) < $width) {
            $headRow[] = '';
        }
        array_unshift($rows, $headRow);
        return [$synth, $rows];
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @return array{headers:list<string>,rows:list<list<string>>,error:?string}
     */
    private static function finish(array $headers, array $rows): array
    {
        [$headers, $rows] = self::promoteHeaderless($headers, $rows);
        return ['headers' => $headers, 'rows' => $rows, 'error' => null];
    }

    /** @param list<string> $shared */
    private static function cellText(string $attrs, string $inner, array $shared): string
    {
        $type = '';
        if (preg_match('/t="([^"]+)"/', $attrs, $tm)) {
            $type = $tm[1];
        }
        if ($type === 'inlineStr' || ($type !== 's' && preg_match('/<is\\b/s', $inner))) {
            if (preg_match_all('/<t[^>]*>(.*?)<\\/t>/s', $inner, $texts)) {
                return trim(html_entity_decode(implode('', $texts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            }
            return '';
        }
        $v = '';
        if (preg_match('/<v[^>]*>(.*?)<\\/v>/s', $inner, $vm)) {
            $v = html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        if ($type === 's') {
            return trim($shared[(int) $v] ?? '');
        }
        return trim($v);
    }

    private static function colIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $n = 0;
        for ($i = 0, $l = strlen($letters); $i < $l; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $n - 1);
    }

    /**
     * Apply mapping: field => header name (or empty to skip).
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param array<string,string> $map field => source header
     * @return list<array<string,string>>
     */
    public static function mapRows(array $headers, array $rows, array $map): array
    {
        $idx = [];
        foreach ($headers as $i => $h) {
            $idx[strtolower(trim($h))] = $i;
        }
        $out = [];
        foreach ($rows as $row) {
            $assoc = [];
            foreach ($map as $field => $src) {
                $src = trim($src);
                if ($src === '') {
                    $assoc[$field] = '';
                    continue;
                }
                $i = $idx[strtolower($src)] ?? null;
                $assoc[$field] = $i === null ? '' : trim((string) ($row[$i] ?? ''));
            }
            $out[] = $assoc;
        }
        return $out;
    }

    /** Guess map from headers to known fields. @param list<string> $fields @param list<string> $headers */
    public static function guessMap(array $fields, array $headers): array
    {
        $aliases = [
            'prefix' => ['prefix', 'dialcode', 'dial_code', 'code', 'destinationcode', 'dst'],
            'code' => ['code', 'area_code', 'areacode', 'area', 'npa', 'prefix', 'dial_code', 'dialcode'],
            'name' => ['name', 'description', 'city', 'destination', 'region', 'location', 'desc'],
            'notes' => ['notes', 'note', 'comment', 'comments', 'remarks'],
            'destination' => ['destination', 'dest', 'name', 'description', 'country', 'city'],
            'rate' => ['rate', 'price', 'sell', 'sell_price', 'cost', 'buy', 'buy_price'],
            'initial_increment' => ['initial_increment', 'initial', 'initblock', 'init_block', 'min_time'],
            'subsequent_increment' => ['subsequent_increment', 'subsequent', 'billingblock', 'billing_block', 'increment'],
            'connect_fee' => ['connect_fee', 'connection', 'connect', 'setup'],
            'status' => ['status', 'active', 'enabled'],
            'effective_from' => ['effective_from', 'from', 'start', 'valid_from'],
            'effective_to' => ['effective_to', 'to', 'end', 'valid_to'],
            'routing_plan_id' => ['routing_plan_id', 'trunk_group', 'trunkgroup', 'trunk_group_id'],
        ];
        $lower = [];
        foreach ($headers as $h) {
            $lower[strtolower(preg_replace('/[^a-z0-9]+/i', '_', $h) ?? $h)] = $h;
            $lower[strtolower(trim($h))] = $h;
        }
        $map = [];
        foreach ($fields as $f) {
            $map[$f] = '';
            foreach ($aliases[$f] ?? [$f] as $a) {
                $key = strtolower($a);
                if (isset($lower[$key])) {
                    $map[$f] = $lower[$key];
                    break;
                }
            }
        }
        return $map;
    }
}
