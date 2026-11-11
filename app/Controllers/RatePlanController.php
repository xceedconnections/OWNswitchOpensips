<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\RatePlanRepository;

/** Magnus Rates → Plans: name only (billing blocks live on Tariffs). */
final class RatePlanController extends CrudController
{
    protected string $base = '/rate-plans';
    protected string $singular = 'Plan';
    protected string $plural = 'Plans';
    protected string $emptyHint = 'No plans yet. Magnus: create a Plan (name), then Prefixes + Tariffs. Assign the Plan on the User.';

    protected function repo(): BaseRepository
    {
        return new RatePlanRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['status'];
    }

    protected function fields(Request $r): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true, 'help' => 'e.g. Golden, Retail, Wholesale'],
            ['name' => 'status', 'label' => 'Active', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Inactive'], 'list' => true, 'default' => 'active'],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $data = $this->autoCode($data, $existing, 'rate_plans');
        // Hidden Magnus/system defaults (not shown on the form — same as creating “Golden” with defaults).
        if ($existing === null) {
            $data['currency'] = $data['currency'] ?? 'USD';
            $data['initial_increment'] = $data['initial_increment'] ?? 6;
            $data['subsequent_increment'] = $data['subsequent_increment'] ?? 6;
            $data['connect_fee'] = $data['connect_fee'] ?? '0';
            $data['min_billable_seconds'] = $data['min_billable_seconds'] ?? 0;
            $data['charge_connect_on_zero'] = $data['charge_connect_on_zero'] ?? 0;
        }
        $data['is_demo'] = $existing['is_demo'] ?? 0;
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        (new RatePlanRepository($this->db()))->createVersion($id, (int) ($this->user($r)['id'] ?? 0) ?: null);
        return null;
    }

    protected function beforeDelete(Request $r, array $row): ?string
    {
        $id = (int) $row['id'];
        $pdo = $this->db();
        try {
            // Unassign from users first (RESTRICT FK).
            try {
                $pdo->prepare('UPDATE customers SET rate_plan_id = NULL WHERE rate_plan_id = ?')->execute([$id]);
            } catch (\PDOException) {
            }
            foreach (['rate_plan_tariffs', 'plan_prefixes', 'rate_plan_versions'] as $table) {
                try {
                    $pdo->prepare("DELETE FROM `{$table}` WHERE rate_plan_id = ?")->execute([$id]);
                } catch (\PDOException) {
                }
            }
        } catch (\Throwable $e) {
            return 'Cannot delete Plan: ' . mb_substr($e->getMessage(), 0, 200);
        }
        return null;
    }
}
