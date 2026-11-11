<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Access\FeatureVisibilityService;

/** Vendor-only Admin Access: choose which admin / customer pages and dashboard widgets are visible. */
final class FeatureAccessController extends BaseController
{
    public function edit(Request $r, array $p = []): Response
    {
        $user = $this->user($r);
        if (!FeatureVisibilityService::isVendorUser($user)) {
            return $this->forbidden();
        }
        $svc = new FeatureVisibilityService($this->db());
        $svc->ensureSchemaAndVendor();
        $flags = $svc->flags();
        $groups = [];
        foreach (FeatureVisibilityService::catalog() as $key => [$label, $audience, $path, $group]) {
            $groups[$group][] = [
                'key' => $key,
                'label' => $label,
                'audience' => $audience,
                'path' => $path,
                'enabled' => !empty($flags[$key]),
            ];
        }
        return $this->view('settings/feature_access', [
            'title' => 'Admin Access',
            'groups' => $groups,
            'vendorName' => FeatureVisibilityService::VENDOR_USERNAME,
        ]);
    }

    public function update(Request $r, array $p = []): Response
    {
        $user = $this->user($r);
        if (!FeatureVisibilityService::isVendorUser($user)) {
            return $this->forbidden();
        }
        $svc = new FeatureVisibilityService($this->db());
        $raw = $r->input('features', []);
        $map = [];
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (is_string($k)) {
                    $map[$k] = !empty($v) ? 1 : 0;
                }
            }
        }
        // Unchecked checkboxes are absent — treat missing catalog keys as disabled.
        foreach (array_keys(FeatureVisibilityService::catalog()) as $key) {
            if (!array_key_exists($key, $map)) {
                $map[$key] = 0;
            }
        }
        $svc->save($map, isset($user['id']) ? (int) $user['id'] : null);
        $this->audit($r, 'admin_access_update', 'feature_visibility', null, null, ['count' => count($map)]);
        return $this->redirect($r, '/settings/admin-access', 'Admin access visibility saved.', 'success');
    }
}
