<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Tariffs\AreaCodeCsvService;
use Ownswitch\Services\Tariffs\ImportUploadStore;
use Ownswitch\Support\SpreadsheetReader;

final class AreaCodeImportController extends BaseController
{
    public function form(Request $r): Response
    {
        return $this->view('area_codes/import', $this->vars('upload'));
    }

    public function export(Request $r): Response
    {
        $fmt = strtolower((string) $r->query('format', 'csv'));
        $template = (string) $r->query('template', '') === '1';
        $svc = new AreaCodeCsvService($this->db());
        $excel = in_array($fmt, ['xlsx', 'excel', 'xls'], true);
        if ($excel) {
            $useXlsx = class_exists(\ZipArchive::class);
            try {
                if ($useXlsx) {
                    $bin = $template ? $svc->templateXlsx() : $svc->exportXlsx();
                    $name = $template ? 'area-codes-template.xlsx' : 'area-codes.xlsx';
                    $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                } else {
                    $bin = $template ? $svc->templateExcelXml() : $svc->exportExcelXml();
                    $name = $template ? 'area-codes-template.xls' : 'area-codes.xls';
                    $type = 'application/vnd.ms-excel; charset=UTF-8';
                }
            } catch (\Throwable $e) {
                return new Response($e->getMessage(), 500, ['Content-Type' => 'text/plain; charset=utf-8']);
            }
            return new Response($bin, 200, [
                'Content-Type' => $type,
                'Content-Disposition' => 'attachment; filename="' . $name . '"',
                'Cache-Control' => 'no-store',
            ]);
        }
        $csv = $template ? $svc->templateCsv() : $svc->exportCsv();
        if (!str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = "\xEF\xBB\xBF" . $csv;
        }
        $name = $template ? 'area-codes-template.csv' : 'area-codes.csv';
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function upload(Request $r): Response
    {
        $quick = (string) $r->input('quick', '') === '1';
        try {
            $stored = ImportUploadStore::storeUpload($_FILES['file'] ?? []);
        } catch (\Throwable $e) {
            if ($quick) {
                return $this->redirect($r, '/area-codes/create', $e->getMessage(), 'danger');
            }
            return $this->view('area_codes/import', $this->vars('upload', $e->getMessage()), 422);
        }
        $map = SpreadsheetReader::guessMap(AreaCodeCsvService::HEADERS, $stored['headers']);
        if ($quick && (int) $stored['row_count'] < 1) {
            ImportUploadStore::forget($stored['token']);
            return $this->redirect($r, '/area-codes/create', 'The file has no area code rows to import.', 'warning');
        }
        if ($quick && $map['code'] !== '' && $map['name'] !== '') {
            try {
                $data = ImportUploadStore::load($stored['token']);
                $preview = (new AreaCodeCsvService($this->db()))->previewMapped($data['headers'], $data['rows'], $map);
            } catch (\Throwable $e) {
                return $this->redirect($r, '/area-codes/create', $e->getMessage(), 'danger');
            }
            if ($preview['ok'] && $preview['rows'] !== []) {
                $n = (new AreaCodeCsvService($this->db()))->importRows($preview['rows']);
                ImportUploadStore::forget($stored['token']);
                $this->audit($r, 'area_code_import', 'area_codes', null, null, ['count' => $n, 'quick' => true]);
                return $this->redirect($r, '/area-codes', "Imported {$n} area code(s).");
            }
            if (!$preview['ok']) {
                return $this->view('area_codes/import', $this->vars(
                    'preview',
                    'Validation failed. Fix the file or adjust the column mapping.',
                    [
                        'token' => $stored['token'],
                        'headers' => $stored['headers'],
                        'map' => $map,
                        'sample' => $stored['sample'],
                        'row_count' => $stored['row_count'],
                        'preview' => $preview,
                    ]
                ), 422);
            }
        }
        return $this->view('area_codes/import', $this->vars('map', 'Map columns, then validate.', [
            'token' => $stored['token'],
            'headers' => $stored['headers'],
            'map' => $map,
            'sample' => $stored['sample'],
            'row_count' => $stored['row_count'],
        ]));
    }

    public function preview(Request $r): Response
    {
        $token = (string) $r->input('token', '');
        $map = $this->readMap($r);
        try {
            $data = ImportUploadStore::load($token);
        } catch (\Throwable $e) {
            return $this->view('area_codes/import', $this->vars('upload', $e->getMessage()), 422);
        }
        if ($map['code'] === '' || $map['name'] === '') {
            return $this->view('area_codes/import', $this->vars('map', 'Map both code and name.', [
                'token' => $token,
                'headers' => $data['headers'],
                'map' => $map,
                'sample' => array_slice($data['rows'], 0, 5),
                'row_count' => count($data['rows']),
            ]), 422);
        }
        $preview = (new AreaCodeCsvService($this->db()))->previewMapped($data['headers'], $data['rows'], $map);
        return $this->view('area_codes/import', $this->vars(
            'preview',
            $preview['ok'] ? 'Validation OK — commit to save.' : 'Validation failed.',
            [
                'token' => $token,
                'headers' => $data['headers'],
                'map' => $map,
                'sample' => array_slice($data['rows'], 0, 5),
                'row_count' => count($data['rows']),
                'preview' => $preview,
            ]
        ));
    }

    public function commit(Request $r): Response
    {
        $token = (string) $r->input('token', '');
        $map = $this->readMap($r);
        try {
            $data = ImportUploadStore::load($token);
            $preview = (new AreaCodeCsvService($this->db()))->previewMapped($data['headers'], $data['rows'], $map);
            if (!$preview['ok']) {
                throw new \RuntimeException(implode('; ', $preview['errors']));
            }
            $n = (new AreaCodeCsvService($this->db()))->importRows($preview['rows']);
            ImportUploadStore::forget($token);
            $this->audit($r, 'area_code_import', 'area_codes', null, null, ['count' => $n]);
            return $this->view('area_codes/import', $this->vars('upload', "Imported {$n} area code(s)."));
        } catch (\Throwable $e) {
            return $this->view('area_codes/import', $this->vars('upload', 'Import failed: ' . $e->getMessage()), 422);
        }
    }

    /** @return array{code:string,name:string,notes:string} */
    private function readMap(Request $r): array
    {
        return [
            'code' => trim((string) $r->input('map_code', '')),
            'name' => trim((string) $r->input('map_name', '')),
            'notes' => trim((string) $r->input('map_notes', '')),
        ];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function vars(string $step, ?string $flash = null, array $extra = []): array
    {
        // array_merge so $extra overrides defaults (PHP + keeps left-hand keys).
        return array_merge([
            'title' => 'Area code import / export',
            'step' => $step,
            'flash' => $flash,
            'token' => null,
            'headers' => [],
            'map' => [],
            'sample' => [],
            'row_count' => 0,
            'preview' => null,
            'fields' => AreaCodeCsvService::HEADERS,
            'required' => AreaCodeCsvService::REQUIRED,
        ], $extra);
    }
}
