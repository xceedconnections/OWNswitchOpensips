<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CallerIdGroupRepository;
use Ownswitch\Support\SpreadsheetReader;

/**
 * Settings → CallerID: pools of outbound CLIs, assignable to a SIP user or forced on a trunk.
 */
final class CallerIdGroupController extends CrudController
{
    protected string $base = '/callerid-groups';
    protected string $singular = 'CallerID group';
    protected string $plural = 'CallerID groups';
    protected array $writeRoles = ['super_admin', 'admin'];
    protected string $emptyHint = 'No CallerID groups. Create a group, add CLI numbers, then assign it to a SIP user or a trunk.';

    private const IMPORT_LIMIT = 20000;

    /** @var ?array<string,mixed> group shown on the edit form (drives the numbers panel) */
    private ?array $formGroup = null;

    protected function repo(): BaseRepository
    {
        return new CallerIdGroupRepository($this->db());
    }

    private function groups(): CallerIdGroupRepository
    {
        return new CallerIdGroupRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['customer_id', 'status'];
    }

    protected function fields(Request $r): array
    {
        return [
            ['name' => 'name', 'label' => 'Group name', 'required' => true, 'max' => 120, 'list' => true],
            [
                'name' => 'customer_id',
                'label' => 'Customer',
                'type' => 'select',
                'options' => ['' => 'Global (any SIP user)'] + $this->customerOptions($r),
                'list' => true,
                'help' => 'Global groups can be assigned to any SIP user or trunk. Pick a customer to restrict the group to that customer’s SIP users.',
            ],
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'required' => true,
                'options' => ['active' => 'Active', 'disabled' => 'Disabled'],
                'default' => 'active',
                'list' => true,
            ],
            ['name' => 'number_count', 'label' => 'Numbers', 'list' => true, 'list_only' => true],
        ];
    }

    protected function enrichRows(Request $r, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        try {
            $ids = array_map(static fn ($x) => (int) $x['id'], $rows);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $this->db()->prepare("SELECT callerid_group_id, COUNT(*) c FROM callerid_group_numbers WHERE callerid_group_id IN ({$in}) GROUP BY callerid_group_id");
            $st->execute($ids);
            $counts = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $counts[(int) $c['callerid_group_id']] = (int) $c['c'];
            }
            foreach ($rows as &$row) {
                $row['number_count'] = $counts[(int) $row['id']] ?? 0;
            }
            unset($row);
        } catch (\Throwable) {
        }
        return $rows;
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $cid = $data['customer_id'] ?? null;
        $data['customer_id'] = ($cid === null || $cid === '') ? null : (int) $cid;
        if ($data['customer_id'] !== null && !isset($this->customerOptions($r)[$data['customer_id']])) {
            throw new \DomainException('Select a valid customer.');
        }
        return $data;
    }

    protected function beforeDelete(Request $r, array $row): ?string
    {
        $id = (int) $row['id'];
        $parts = [];
        try {
            $st = $this->db()->prepare('SELECT COUNT(*) FROM sip_accounts WHERE callerid_group_id = ?');
            $st->execute([$id]);
            $n = (int) $st->fetchColumn();
            if ($n > 0) {
                $parts[] = "{$n} SIP user(s)";
            }
        } catch (\Throwable) {
        }
        try {
            $st = $this->db()->prepare('SELECT COUNT(*) FROM carrier_trunks WHERE callerid_group_id = ?');
            $st->execute([$id]);
            $n = (int) $st->fetchColumn();
            if ($n > 0) {
                $parts[] = "{$n} trunk(s)";
            }
        } catch (\Throwable) {
        }
        return $parts === [] ? null : 'This group is assigned to ' . implode(' and ', $parts) . '; unassign it first.';
    }

    protected function form(Request $r, ?array $existing, array $values, array $errors, int $status = 200): Response
    {
        $this->formGroup = $existing;
        return parent::form($r, $existing, $values, $errors, $status);
    }

    protected function extra(Request $r): array
    {
        if ($r->path === $this->base && $this->formGroup === null) {
            $cliQ = trim((string) ($r->query['cli'] ?? ''));
            $hits = $cliQ !== '' ? $this->groups()->searchAll($cliQ) : null;
            return [
                'listTopPartial' => 'callerid_groups/_search',
                'cliQuery' => $cliQ,
                'cliHits' => $hits,
                'cliHitsTruncated' => is_array($hits) && count($hits) >= 200,
            ];
        }
        if ($this->formGroup === null) {
            return [];
        }
        $gid = (int) $this->formGroup['id'];
        $q = trim((string) ($r->query['q'] ?? ''));
        $page = max(1, (int) ($r->query['page'] ?? 1));
        return [
            'afterFormPartial' => 'callerid_groups/_numbers',
            'group' => $this->formGroup,
            'numberResult' => $this->groups()->searchNumbers($gid, $q, $page),
            'numberQuery' => array_filter(['q' => $q], static fn ($v) => $v !== ''),
            'q' => $q,
        ];
    }

    public function addNumber(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $group = $this->groups()->find((int) $p['id'], $this->scope($r));
        if ($group === null) {
            return $this->notFound();
        }
        $back = $this->base . '/' . (int) $group['id'] . '/edit';
        $parsed = $this->normalizeCliRows([[
            'cli' => (string) ($r->post['cli_number'] ?? ''),
            'name' => (string) ($r->post['name'] ?? ''),
        ]]);
        if ($parsed['rows'] === []) {
            return $this->redirect($r, $back, 'CLI number must be 3–30 digits.', 'danger');
        }
        $status = (string) ($r->post['status'] ?? 'active');
        if (!in_array($status, ['active', 'disabled'], true)) {
            $status = 'active';
        }
        $row = $parsed['rows'][0];
        $res = $this->groups()->insertClis((int) $group['id'], [$row], $status);
        if ($res['added'] === 0) {
            return $this->redirect($r, $back, 'That CLI is already in this group.', 'danger');
        }
        $this->audit($r, 'create', 'callerid_group_numbers', (int) $group['id'], null, $row + ['callerid_group_id' => (int) $group['id']]);
        return $this->redirect($r, $back, 'Number added.');
    }

    public function deleteNumber(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $group = $this->groups()->find((int) $p['id'], $this->scope($r));
        if ($group === null) {
            return $this->notFound();
        }
        $back = $this->base . '/' . (int) $group['id'] . '/edit';
        $numberId = (int) ($p['numberId'] ?? 0);
        $row = $this->groups()->findNumber((int) $group['id'], $numberId);
        if ($row === null) {
            return $this->redirect($r, $back, 'Number not found.', 'danger');
        }
        $this->groups()->deleteNumber((int) $group['id'], $numberId);
        $this->audit($r, 'delete', 'callerid_group_numbers', $numberId, $row, null);
        return $this->redirect($r, $back, 'Number removed.');
    }

    public function bulkDelete(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $group = $this->groups()->find((int) $p['id'], $this->scope($r));
        if ($group === null) {
            return $this->notFound();
        }
        $back = $this->base . '/' . (int) $group['id'] . '/edit';
        $ids = $r->post['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $n = $this->groups()->deleteByIds((int) $group['id'], $ids);
        $this->audit($r, 'delete', 'callerid_group_numbers', (int) $group['id'], null, ['count' => $n]);
        return $this->redirect($r, $back, $n > 0 ? "Removed {$n} number(s)." : 'Nothing selected.');
    }

    public function importNumbers(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $group = $this->groups()->find((int) $p['id'], $this->scope($r));
        if ($group === null) {
            return $this->notFound();
        }
        $gid = (int) $group['id'];
        $back = $this->base . '/' . $gid . '/edit';
        $mode = (string) ($r->post['mode'] ?? 'add');
        if (!in_array($mode, ['add', 'delete'], true)) {
            $mode = 'add';
        }
        try {
            $raw = $this->rowsFromText((string) ($r->post['cli_text'] ?? ''));
            $file = $r->files['file'] ?? null;
            if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $parsed = SpreadsheetReader::fromUpload($file);
                if (($parsed['error'] ?? null) !== null) {
                    return $this->redirect($r, $back, (string) $parsed['error'], 'danger');
                }
                $raw = array_merge($raw, $this->rowsFromSpreadsheet($parsed));
            }
            $norm = $this->normalizeCliRows($raw);
            if (count($norm['rows']) > self::IMPORT_LIMIT) {
                return $this->redirect($r, $back, 'Too many numbers (max ' . self::IMPORT_LIMIT . ' per upload).', 'danger');
            }
            if ($norm['rows'] === []) {
                return $this->redirect($r, $back, 'No valid CLI numbers found. Use one number per line, or a CSV/Excel file.', 'danger');
            }
            if ($mode === 'delete') {
                $n = $this->groups()->deleteByClis($gid, array_column($norm['rows'], 'cli'));
                $this->audit($r, 'delete', 'callerid_group_numbers', $gid, null, ['count' => $n, 'invalid' => $norm['invalid']]);
                $msg = "Removed {$n} number(s).";
                if ($norm['invalid'] > 0) {
                    $msg .= " Skipped {$norm['invalid']} invalid line(s).";
                }
                return $this->redirect($r, $back, $msg);
            }
            $status = (string) ($r->post['status'] ?? 'active');
            if (!in_array($status, ['active', 'disabled'], true)) {
                $status = 'active';
            }
            $res = $this->groups()->insertClis($gid, $norm['rows'], $status);
            $this->audit($r, 'import', 'callerid_group_numbers', $gid, null, $res + ['invalid' => $norm['invalid']]);
            $msg = "Added {$res['added']} number(s).";
            $skip = $res['skipped'] + $norm['invalid'];
            if ($skip > 0) {
                $msg .= " Skipped {$skip} (already in the group or invalid).";
            }
            return $this->redirect($r, $back, $msg);
        } catch (\Throwable $e) {
            error_log('[ownswitch] callerid import: ' . $e->getMessage());
            return $this->redirect($r, $back, 'Import failed. Check the file and try again.', 'danger');
        }
    }

    public function exportNumbers(Request $r, array $p): Response
    {
        $group = $this->groups()->find((int) $p['id'], $this->scope($r));
        if ($group === null) {
            return $this->notFound();
        }
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return $this->redirect($r, $this->base . '/' . (int) $group['id'] . '/edit', 'Could not build the export.', 'danger');
        }
        fputcsv($fh, ['cli_number', 'name', 'status']);
        foreach ($this->groups()->exportRows((int) $group['id']) as $row) {
            fputcsv($fh, [
                (string) ($row['cli_number'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['status'] ?? ''),
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($group['name'] ?? 'group')) ?: 'group';
        return new Response((string) $csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="callerid-' . $slug . '.csv"',
        ]);
    }

    /**
     * @return list<array{cli:string,name:string}>
     */
    private function rowsFromText(string $text): array
    {
        $raw = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $name = '';
            $cli = $line;
            if (preg_match('/^(.+?)[,\t;|](.*)$/u', $line, $m)) {
                $cli = trim($m[1]);
                $name = trim($m[2]);
            }
            $raw[] = ['cli' => $cli, 'name' => $name];
        }
        return $raw;
    }

    /**
     * @param array{headers:list<string>,rows:list<list<string>>} $parsed
     * @return list<array{cli:string,name:string}>
     */
    private function rowsFromSpreadsheet(array $parsed): array
    {
        $headers = $parsed['headers'];
        $data = $parsed['rows'];
        $norm = static function (string $h): string {
            return strtolower(preg_replace('/[^a-z0-9]+/i', '', $h) ?? '');
        };
        $cliNames = ['cli', 'clinumber', 'callerid', 'calleridnumber', 'number', 'phone', 'ani', 'from'];
        $nameNames = ['name', 'label', 'note', 'description', 'comment'];
        $cliIdx = -1;
        $nameIdx = -1;
        foreach ($headers as $i => $h) {
            $key = $norm((string) $h);
            if ($cliIdx < 0 && in_array($key, $cliNames, true)) {
                $cliIdx = (int) $i;
            }
            if ($nameIdx < 0 && in_array($key, $nameNames, true)) {
                $nameIdx = (int) $i;
            }
        }
        $headerDigits = preg_replace('/\D+/', '', (string) ($headers[0] ?? '')) ?? '';
        if ($cliIdx < 0 && strlen($headerDigits) >= 3 && strlen($headerDigits) <= 30) {
            array_unshift($data, array_map(static fn ($c) => (string) $c, $headers));
            $cliIdx = 0;
            if ($nameIdx < 0 && isset($headers[1])) {
                $nameIdx = 1;
            }
        }
        if ($cliIdx < 0) {
            $cliIdx = 0;
        }
        $out = [];
        foreach ($data as $row) {
            $out[] = [
                'cli' => (string) ($row[$cliIdx] ?? ''),
                'name' => $nameIdx >= 0 ? (string) ($row[$nameIdx] ?? '') : '',
            ];
        }
        return $out;
    }

    /**
     * @param list<array{cli:string,name:string}> $raw
     * @return array{rows:list<array{cli:string,name:?string}>,invalid:int}
     */
    private function normalizeCliRows(array $raw): array
    {
        $out = [];
        $invalid = 0;
        $seen = [];
        foreach ($raw as $row) {
            $cliRaw = trim($row['cli']);
            $name = trim($row['name']);
            if ($name === '' && preg_match('/^(\+?\d[\d\s().-]{2,40})\s+(\S.*)$/u', $cliRaw, $m)) {
                $cliRaw = $m[1];
                $name = trim($m[2]);
            }
            $cli = preg_replace('/\D+/', '', $cliRaw) ?? '';
            if ($cli === '') {
                continue;
            }
            if (strlen($cli) < 3 || strlen($cli) > 30) {
                $invalid++;
                continue;
            }
            if (isset($seen[$cli])) {
                continue;
            }
            $seen[$cli] = true;
            if (mb_strlen($name) > 64) {
                $name = mb_substr($name, 0, 64);
            }
            $out[] = ['cli' => $cli, 'name' => $name !== '' ? $name : null];
        }
        return ['rows' => $out, 'invalid' => $invalid];
    }
}
