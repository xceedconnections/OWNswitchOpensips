<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\PrefixRepository;

/**
 * MagnusBilling-style Prefixes: dial code + destination name only (no rates).
 * Sell/buy prices live under Tariffs / Provider Rates and pick a Prefix as Destination.
 */
final class PrefixController extends CrudController
{
    protected string $base = '/prefixes';
    protected string $singular = 'Prefix';
    protected string $plural = 'Prefixes';
    protected string $emptyHint = 'No prefixes yet. Add dial codes here (e.g. 1 = USA, 44 = UK). Tariffs then pick a Prefix as Destination and set the sell rate — same as MagnusBilling.';
    protected string $defaultSort = 'prefix';

    protected function repo(): BaseRepository
    {
        return new PrefixRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return [];
    }

    protected function fields(Request $r): array
    {
        return [
            [
                'name' => 'prefix',
                'label' => 'Prefix',
                'type' => 'prefix',
                'required' => true,
                'readonly_on_edit' => true,
                'list' => true,
            ],
            [
                'name' => 'destination',
                'label' => 'Destination',
                'required' => true,
                'max' => 120,
                'list' => true,
            ],
        ];
    }

    protected function extra(Request $r): array
    {
        return [
            'bulkDelete' => true,
            'bulkPrefixUpdate' => true,
            'headerLinks' => [
                ['href' => '/prefixes/import', 'label' => 'Import', 'icon' => 'upload', 'class' => 'btn-outline-secondary'],
                ['href' => '/prefixes/export', 'label' => 'Export CSV', 'icon' => 'download', 'class' => 'btn-outline-secondary'],
            ],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $data['destination'] = trim((string) ($data['destination'] ?? ''));
        if ($data['destination'] === '') {
            throw new \DomainException('Destination name is required.');
        }
        if ($existing === null) {
            $data['prefix'] = preg_replace('/\D+/', '', (string) ($data['prefix'] ?? '')) ?? '';
            if ($data['prefix'] === '') {
                throw new \DomainException('Prefix must be digits only.');
            }
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        $row = $this->repo()->findInternal($id);
        if ($row) {
            /** @var PrefixRepository $repo */
            $repo = $this->repo();
            $repo->syncTariffDestinations([(string) $row['prefix']], (string) $row['destination']);
        }
        return null;
    }

    /** Delete selected rows or all rows matching current search. */
    public function bulkDestroy(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        /** @var PrefixRepository $repo */
        $repo = $this->repo();
        $scope = $this->scope($r);
        $mode = (string) $r->input('mode', 'selected');
        $qRaw = $r->input('q');
        if (!is_string($qRaw) || $qRaw === '') {
            $qRaw = $r->query('q');
        }
        $q = is_string($qRaw) ? trim($qRaw) : '';
        $n = 0;

        if ($mode === 'selected') {
            $raw = $r->input('ids', []);
            $ids = is_array($raw) ? $raw : (is_string($raw) ? (preg_split('/[\s,]+/', $raw) ?: []) : []);
            $idList = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
            $n = $repo->deleteByIds($scope, $idList);
        } elseif ($mode === 'filtered') {
            // Empty search = wipe all prefixes (confirm text shows total count).
            $n = $repo->deleteFiltered($scope, [], $q !== '' ? $q : null, true);
        }

        $this->audit($r, 'prefix_bulk_delete', 'prefixes', null, null, [
            'mode' => $mode, 'count' => $n, 'q' => $q,
        ]);
        $qs = $q !== '' ? ('?q=' . rawurlencode($q)) : '';
        return $this->redirect(
            $r,
            $this->base . $qs,
            "Deleted {$n} " . strtolower($this->plural) . '.',
            $n > 0 ? 'success' : 'warning'
        );
    }

    /**
     * Bulk-set destination: selected rows and/or dial codes that match (e.g. 1,1213).
     */
    public function bulkDestinationUpdate(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        /** @var PrefixRepository $repo */
        $repo = $this->repo();
        $destination = trim((string) $r->input('destination', ''));
        if ($destination === '') {
            return $this->redirect($r, $this->base, 'Destination name is required.', 'danger');
        }
        $matchRaw = trim((string) $r->input('prefix_match', ''));
        $matchMode = (string) $r->input('prefix_mode', 'starts') === 'exact' ? 'exact' : 'starts';
        $qRaw = $r->input('q');
        if (!is_string($qRaw) || $qRaw === '') {
            $qRaw = $r->query('q');
        }
        $q = is_string($qRaw) ? trim($qRaw) : '';

        $updated = [];
        if ($matchRaw !== '') {
            $codes = preg_split('/[\s,;]+/', $matchRaw) ?: [];
            $updated = $repo->updateDestinationByPrefixMatch($codes, $destination, $matchMode);
        } else {
            $raw = $r->input('ids', []);
            $ids = is_array($raw) ? $raw : (is_string($raw) ? (preg_split('/[\s,]+/', $raw) ?: []) : []);
            $idList = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
            if ($idList === []) {
                return $this->redirect(
                    $r,
                    $this->base,
                    'Select rows or enter matching prefixes (e.g. 1,1213).',
                    'warning'
                );
            }
            $updated = $repo->updateDestinationByIds($idList, $destination);
        }

        if ($updated !== []) {
            $repo->syncTariffDestinations($updated, $destination);
        }

        $this->audit($r, 'prefix_bulk_destination', 'prefixes', null, null, [
            'destination' => $destination,
            'prefix_match' => $matchRaw,
            'prefix_mode' => $matchMode,
            'updated' => count($updated),
        ]);
        $qs = $q !== '' ? ('?q=' . rawurlencode($q)) : '';
        return $this->redirect(
            $r,
            $this->base . $qs,
            'Updated destination on ' . count($updated) . ' ' . strtolower($this->plural) . '.',
            $updated !== [] ? 'success' : 'warning'
        );
    }
}
