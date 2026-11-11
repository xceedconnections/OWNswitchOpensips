<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Access\FeatureVisibilityService;
use Ownswitch\Services\Access\UserAccessService;

/** Admin User Access: create sub-admin users and restrict their menus. */
final class UserAccessController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        if ($deny = $this->denyUnlessAllowed($r)) {
            return $deny;
        }
        $user = $this->user($r);
        $asVendor = FeatureVisibilityService::isVendorUser($user);
        $svc = new UserAccessService($this->db());
        $svc->ensureSchema();
        return $this->view('settings/user_access/index', [
            'title' => 'User Access',
            'users' => $svc->listManagedBy((int) $user['id'], $asVendor),
            'canWrite' => $this->canWrite($r),
            'asVendor' => $asVendor,
        ]);
    }

    public function create(Request $r, array $p = []): Response
    {
        if ($deny = $this->denyUnlessAllowed($r)) {
            return $deny;
        }
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $svc = new UserAccessService($this->db());
        $manager = $this->user($r);
        return $this->view('settings/user_access/form', [
            'title' => 'New sub-admin',
            'existing' => null,
            'errors' => [],
            'data' => [
                'name' => '',
                'email' => '',
                'status' => 'active',
                'password' => '',
            ],
            'featureGroups' => $this->featureGroups($manager, []),
            'asVendor' => FeatureVisibilityService::isVendorUser($manager),
            'showFeatureGrants' => true,
        ]);
    }

    public function store(Request $r, array $p = []): Response
    {
        if ($deny = $this->denyUnlessAllowed($r)) {
            return $deny;
        }
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $manager = $this->user($r);
        $svc = new UserAccessService($this->db());
        $data = $this->formData($r);
        $result = $svc->createSubAdmin(
            $manager,
            $data['email'],
            $data['name'],
            $data['password'],
            $data['status'],
            $data['features']
        );
        if (!$result['ok']) {
            return $this->view('settings/user_access/form', [
                'title' => 'New sub-admin',
                'existing' => null,
                'errors' => ['_form' => $result['error']],
                'data' => $data,
                'featureGroups' => $this->featureGroups($manager, $data['features']),
                'asVendor' => FeatureVisibilityService::isVendorUser($manager),
                'showFeatureGrants' => true,
            ], 422);
        }
        $this->audit($r, 'create', 'user_access', $result['id'], null, [
            'email' => $data['email'],
            'features' => count($data['features']),
        ]);
        return $this->redirect($r, '/settings/user-access/' . $result['id'] . '/edit', 'Sub-admin created.');
    }

    public function edit(Request $r, array $p): Response
    {
        if ($deny = $this->denyUnlessAllowed($r)) {
            return $deny;
        }
        $manager = $this->user($r);
        $asVendor = FeatureVisibilityService::isVendorUser($manager);
        $svc = new UserAccessService($this->db());
        $existing = $svc->findManaged((int) $p['id'], (int) $manager['id'], $asVendor);
        if ($existing === null) {
            return $this->notFound();
        }
        $isManaged = (int) ($existing['managed_by_user_id'] ?? 0) > 0;
        $grants = $isManaged ? array_keys(array_filter($svc->grantsFor((int) $existing['id']))) : [];
        return $this->view('settings/user_access/form', [
            'title' => $isManaged ? 'Edit sub-admin' : 'Edit admin',
            'existing' => $existing,
            'errors' => [],
            'data' => [
                'name' => (string) $existing['name'],
                'email' => (string) $existing['email'],
                'status' => (string) $existing['status'],
                'password' => '',
            ],
            'featureGroups' => $isManaged ? $this->featureGroups($manager, $grants) : [],
            'canWrite' => $this->canWrite($r),
            'asVendor' => $asVendor,
            'showFeatureGrants' => $isManaged,
            'canDelete' => $isManaged,
        ]);
    }

    public function update(Request $r, array $p): Response
    {
        if ($deny = $this->denyUnlessAllowed($r)) {
            return $deny;
        }
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $manager = $this->user($r);
        $asVendor = FeatureVisibilityService::isVendorUser($manager);
        $svc = new UserAccessService($this->db());
        $id = (int) $p['id'];
        $existing = $svc->findManaged($id, (int) $manager['id'], $asVendor);
        if ($existing === null) {
            return $this->notFound();
        }
        $isManaged = (int) ($existing['managed_by_user_id'] ?? 0) > 0;
        $data = $this->formData($r);
        $result = $svc->updateSubAdmin(
            $manager,
            $id,
            $data['email'],
            $data['name'],
            $data['password'] !== '' ? $data['password'] : null,
            $data['status'],
            $data['features'],
            $asVendor
        );
        if (!$result['ok']) {
            return $this->view('settings/user_access/form', [
                'title' => $isManaged ? 'Edit sub-admin' : 'Edit admin',
                'existing' => $existing,
                'errors' => ['_form' => $result['error']],
                'data' => $data,
                'featureGroups' => $isManaged ? $this->featureGroups($manager, $data['features']) : [],
                'canWrite' => true,
                'asVendor' => $asVendor,
                'showFeatureGrants' => $isManaged,
                'canDelete' => $isManaged,
            ], 422);
        }
        $this->audit($r, 'update', 'user_access', $id, [
            'email' => $existing['email'],
        ], [
            'email' => $data['email'],
            'features' => count($data['features']),
        ]);
        return $this->redirect($r, '/settings/user-access/' . $id . '/edit', 'Admin profile saved.');
    }

    public function destroy(Request $r, array $p): Response
    {
        if ($deny = $this->denyUnlessAllowed($r)) {
            return $deny;
        }
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $manager = $this->user($r);
        $asVendor = FeatureVisibilityService::isVendorUser($manager);
        $svc = new UserAccessService($this->db());
        $id = (int) $p['id'];
        $existing = $svc->findManaged($id, (int) $manager['id'], $asVendor);
        if ($existing === null) {
            return $this->notFound();
        }
        if (!$svc->deleteSubAdmin($id, (int) $manager['id'], $asVendor)) {
            return $this->redirect($r, '/settings/user-access/' . $id . '/edit', 'This admin cannot be deleted from User Access.');
        }
        $this->audit($r, 'delete', 'user_access', $id, ['email' => $existing['email']], null);
        return $this->redirect($r, '/settings/user-access', 'Sub-admin deleted.');
    }

    private function denyUnlessAllowed(Request $r): ?Response
    {
        $user = $this->user($r);
        if (FeatureVisibilityService::isVendorUser($user)) {
            return null;
        }
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, ['super_admin', 'admin'], true)) {
            return $this->forbidden();
        }
        $svc = new FeatureVisibilityService($this->db());
        if (!$svc->canSee($user, 'menu.settings.user_access')) {
            return $this->forbidden('403 Forbidden — User Access is disabled for your account.');
        }
        return null;
    }

    private function canWrite(Request $r): bool
    {
        return in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin'], true)
            || FeatureVisibilityService::isVendorUser($this->user($r));
    }

    /**
     * @param list<string> $selectedKeys
     * @return array<string, list<array{key:string,label:string,path:?string,enabled:bool}>>
     */
    private function featureGroups(array $manager, array $selectedKeys): array
    {
        $svc = new UserAccessService($this->db());
        $selected = array_fill_keys($selectedKeys, true);
        $groups = [];
        foreach ($svc->assignableCatalog($manager) as $key => [$label, $audience, $path, $group]) {
            $groups[$group][] = [
                'key' => $key,
                'label' => $label,
                'path' => $path,
                'enabled' => isset($selected[$key]),
            ];
        }
        return $groups;
    }

    /** @return array{name:string,email:string,status:string,password:string,features:list<string>} */
    private function formData(Request $r): array
    {
        $raw = $r->input('features', []);
        $features = [];
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (is_string($k) && !empty($v)) {
                    $features[] = $k;
                }
            }
        }
        return [
            'name' => trim((string) $r->input('name', '')),
            'email' => trim((string) $r->input('email', '')),
            'status' => (string) $r->input('status', 'active') === 'disabled' ? 'disabled' : 'active',
            'password' => (string) $r->input('password', ''),
            'features' => $features,
        ];
    }
}
