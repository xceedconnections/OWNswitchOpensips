<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Tariffs;

use Ownswitch\Services\Tariffs\AreaCodeCsvService;
use Ownswitch\Support\SpreadsheetReader;
use Ownswitch\Support\SpreadsheetWriter;
use Ownswitch\Tests\TestCase;
use PDO;
use ZipArchive;

final class AreaCodeSpreadsheetTest extends TestCase
{
    private function svc(): AreaCodeCsvService
    {
        return new AreaCodeCsvService(new PDO('sqlite::memory:'));
    }

    public function test_excel_number_does_not_keep_trailing_decimal(): void
    {
        $preview = $this->svc()->previewMapped(
            ['code', 'name'],
            [['1213.0', 'USA LA'], ['1203', 'USA Connecticut']],
            ['code' => 'code', 'name' => 'name', 'notes' => '']
        );
        $this->assertTrue($preview['ok'], implode('; ', $preview['errors']));
        $this->assertSame('1213', $preview['rows'][0]['code']);
        $this->assertSame('1203', $preview['rows'][1]['code']);
        $this->assertNull($preview['rows'][0]['notes']);
    }

    public function test_headerless_tab_file_imports_every_row(): void
    {
        $tsv = "1571\tUS-VA\n1703\tUS-VA\n1757\tUS-VA\n";
        $parsed = SpreadsheetReader::fromCsv($tsv);
        $this->assertNull($parsed['error']);
        $this->assertSame(['code', 'name'], $parsed['headers']);
        $this->assertCount(3, $parsed['rows']);
        $this->assertSame(['1571', 'US-VA'], $parsed['rows'][0]);
        $map = SpreadsheetReader::guessMap(AreaCodeCsvService::HEADERS, $parsed['headers']);
        $preview = $this->svc()->previewMapped($parsed['headers'], $parsed['rows'], $map);
        $this->assertTrue($preview['ok'], implode('; ', $preview['errors']));
        $this->assertSame('1571', $preview['rows'][0]['code']);
        $this->assertSame('US-VA', $preview['rows'][0]['name']);
        $this->assertSame('1757', $preview['rows'][2]['code']);
    }

    public function test_titled_csv_is_not_treated_as_data(): void
    {
        $parsed = SpreadsheetReader::fromCsv("code,name\n1213,USA LA\n");
        $this->assertSame(['code', 'name'], $parsed['headers']);
        $this->assertSame([['1213', 'USA LA']], $parsed['rows']);
    }

    public function test_guesses_area_code_city_and_comment_headers(): void
    {
        $map = SpreadsheetReader::guessMap(
            AreaCodeCsvService::HEADERS,
            ['Area Code', 'City', 'Comment']
        );
        $this->assertSame('Area Code', $map['code']);
        $this->assertSame('City', $map['name']);
        $this->assertSame('Comment', $map['notes']);
    }

    public function test_excel_xml_roundtrip_keeps_codes_as_text(): void
    {
        $xml = SpreadsheetWriter::excelXml(
            ['Area Code', 'City', 'Comment'],
            [['1213', 'USA & LA', 'west']],
            'Area codes'
        );
        $parsed = SpreadsheetReader::fromExcelXml($xml);
        $this->assertNull($parsed['error']);
        $this->assertSame(['Area Code', 'City', 'Comment'], $parsed['headers']);
        $this->assertSame([['1213', 'USA & LA', 'west']], $parsed['rows']);
        $map = SpreadsheetReader::guessMap(AreaCodeCsvService::HEADERS, $parsed['headers']);
        $preview = $this->svc()->previewMapped($parsed['headers'], $parsed['rows'], $map);
        $this->assertTrue($preview['ok'], implode('; ', $preview['errors']));
        $this->assertSame('1213', $preview['rows'][0]['code']);
        $this->assertSame('USA & LA', $preview['rows'][0]['name']);
        $this->assertSame('west', $preview['rows'][0]['notes']);
    }

    public function test_xlsx_roundtrip_and_inline_strings(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->skip('PHP zip extension is not loaded');
        }
        $bin = SpreadsheetWriter::xlsx(
            ['code', 'name', 'notes'],
            [['1213', 'USA & LA', 'west']],
            'Area codes'
        );
        $path = tempnam(sys_get_temp_dir(), 'acx');
        $this->assertNotNull($path);
        file_put_contents($path, $bin);
        try {
            $parsed = SpreadsheetReader::fromXlsx($path);
        } finally {
            @unlink($path);
        }
        $this->assertNull($parsed['error']);
        $this->assertSame(['code', 'name', 'notes'], $parsed['headers']);
        $this->assertSame([['1213', 'USA & LA', 'west']], $parsed['rows']);

        $inline = $this->inlineSheet();
        $parsed = SpreadsheetReader::fromXlsx($inline);
        @unlink($inline);
        $this->assertNull($parsed['error']);
        $this->assertSame(['Area Code', 'Description'], $parsed['headers']);
        $this->assertSame([['1213.0', 'USA LA']], $parsed['rows']);
    }

    private function inlineSheet(): string
    {
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1">'
            . '<c r="A1" t="inlineStr"><is><t>Area Code</t></is></c>'
            . '<c r="B1" t="inlineStr"><is><t>Description</t></is></c>'
            . '</row>'
            . '<row r="2">'
            . '<c r="A2"><v>1213.0</v></c>'
            . '<c r="B2" t="inlineStr"><is><t>USA LA</t></is></c>'
            . '</row>'
            . '</sheetData></worksheet>';
        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Area codes" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'aci');
        if ($tmp === false) {
            $this->fail('tempnam');
        }
        @unlink($tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
            $this->fail('zip');
        }
        $zip->addFromString('[Content_Types].xml', $types);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        return $tmp;
    }
}
