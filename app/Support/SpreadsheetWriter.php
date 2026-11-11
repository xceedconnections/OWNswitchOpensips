<?php

declare(strict_types=1);

namespace Ownswitch\Support;

use RuntimeException;
use ZipArchive;

/** Minimal .xlsx writer (shared strings + one sheet). No Composer deps. */
final class SpreadsheetWriter
{
    /**
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public static function xlsx(array $headers, array $rows, string $sheetName = 'Sheet1'): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Excel export needs the PHP zip extension.');
        }

        $strings = [];
        $index = [];
        $intern = static function (string $value) use (&$strings, &$index): int {
            if (isset($index[$value])) {
                return $index[$value];
            }
            $i = count($strings);
            $strings[] = $value;
            $index[$value] = $i;
            return $i;
        };

        $sheetRows = '';
        $r = 1;
        foreach (array_merge([$headers], $rows) as $row) {
            $cells = '';
            $c = 0;
            foreach ($row as $value) {
                $text = self::xmlText((string) ($value ?? ''));
                $si = $intern($text);
                $cells .= '<c r="' . self::colName($c) . $r . '" t="s"><v>' . $si . '</v></c>';
                $c++;
            }
            $sheetRows .= '<row r="' . $r . '">' . $cells . '</row>';
            $r++;
        }

        $shared = '';
        foreach ($strings as $value) {
            $space = ($value !== trim($value)) ? ' xml:space="preserve"' : '';
            $shared .= '<si><t' . $space . '>' . self::xml($value) . '</t></si>';
        }
        $count = count($strings);
        $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">'
            . $shared
            . '</sst>';

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . $sheetRows
            . '</sheetData></worksheet>';

        $name = self::sheetName($sheetName);
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($name) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temporary Excel file.');
        }
        @unlink($tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Cannot create Excel file.');
        }
        $zip->addFromString('[Content_Types].xml', $types);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/sharedStrings.xml', $sst);
        $zip->close();
        $bin = file_get_contents($tmp);
        @unlink($tmp);
        if (!is_string($bin) || $bin === '') {
            throw new RuntimeException('Excel file was empty.');
        }
        return $bin;
    }

    /**
     * Excel 2003 XML. Opens in Excel without the PHP zip extension.
     *
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public static function excelXml(array $headers, array $rows, string $sheetName = 'Sheet1'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<?mso-application progid="Excel.Sheet"?>' . "\n"
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Worksheet ss:Name="' . self::xml(self::sheetName($sheetName)) . '"><Table>';
        foreach (array_merge([$headers], $rows) as $row) {
            $xml .= '<Row>';
            foreach ($row as $value) {
                $text = self::xml(self::xmlText((string) ($value ?? '')));
                $xml .= '<Cell><Data ss:Type="String">' . $text . '</Data></Cell>';
            }
            $xml .= '</Row>';
        }
        $xml .= '</Table></Worksheet></Workbook>';
        return $xml;
    }

    private static function colName(int $index): string
    {
        $name = '';
        $n = $index + 1;
        while ($n > 0) {
            $n--;
            $name = chr(65 + ($n % 26)) . $name;
            $n = intdiv($n, 26);
        }
        return $name;
    }

    private static function sheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\\/*?:\\[\\]]/', ' ', $name) ?? $name;
        $name = trim($name);
        if ($name === '') {
            $name = 'Sheet1';
        }
        return mb_substr($name, 0, 31);
    }

    private static function xmlText(string $value): string
    {
        return preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', '', $value) ?? $value;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
