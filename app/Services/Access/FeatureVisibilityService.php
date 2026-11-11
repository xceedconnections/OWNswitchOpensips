<?php

declare(strict_types=1);

namespace Ownswitch\Services\Access;

use Ownswitch\Repositories\UserRepository;
use PDO;

/**
 * Admin Access (vendor): global toggles for which portal pages/widgets admins and customers see.
 * User Access (admin): per sub-admin grants on top of those global toggles.
 * Vendor users always bypass gates and keep full access.
 *
 * Convention: every new Menu.php path must be registered in catalog() + menuPathMap().
 */
final class FeatureVisibilityService
{
    public const VENDOR_USERNAME = 'xceedconnections';
    public const VENDOR_EMAIL = 'xceedconnections@localhost';
    /** Initial vendor password (trailing period included). Hashed at ensure-time only. */
    public const VENDOR_PASSWORD = 'Openaccount@123.';

    public const DEFAULT_ADMIN_USERNAME = 'admin';
    public const DEFAULT_ADMIN_EMAIL = 'admin@localhost';
    /** Initial platform admin password (trailing period included). Hashed at ensure-time only. */
    public const DEFAULT_ADMIN_PASSWORD = 'Openaccount@123.';

    /** @var array<string,bool>|null */
    private ?array $cache = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Catalog of toggleable features.
     * Each entry: key => [label, audience(admin|customer), path_prefix|null, group]
     *
     * @return array<string, array{0:string,1:string,2:?string,3:string}>
     */
    public static function catalog(): array
    {
        return [
            // Admin / staff menu pages
            'menu.clients.users' => ['Users', 'admin', '/users', 'Clients'],
            'menu.clients.sip_users' => ['SIP Users', 'admin', '/sip-users', 'Clients'],
            'menu.clients.web_dialer' => ['Web-Dialer', 'admin', '/web-dialer', 'Clients'],
            'menu.clients.calls_online' => ['Calls Online', 'admin', '/calls-online', 'Clients'],
            'menu.clients.ip_endpoints' => ['IP Endpoints', 'admin', '/ip-endpoints', 'Clients'],
            'menu.dids.providers' => ['DID Providers', 'admin', '/did-providers', 'DIDs'],
            'menu.dids.dids' => ['DIDs', 'admin', '/dids', 'DIDs'],
            'menu.dids.assignments' => ['My DIDs', 'admin', '/did-assignments', 'DIDs'],
            'menu.billing.refills' => ['Refills', 'admin', '/billing/refill', 'Billing'],
            'menu.billing.payments' => ['Payments', 'admin', '/payments', 'Billing'],
            'menu.billing.vouchers' => ['Vouchers', 'admin', '/vouchers', 'Billing'],
            'menu.billing.preview' => ['Billing Preview', 'admin', '/billing/preview', 'Billing'],
            'menu.rates.plans' => ['Plans', 'admin', '/rate-plans', 'Rates'],
            'menu.rates.plan_prefixes' => ['Plan Prefixes', 'admin', '/plan-prefixes', 'Rates'],
            'menu.rates.tariffs' => ['Tariffs', 'admin', '/tariffs', 'Rates'],
            'menu.rates.prefixes' => ['Prefixes', 'admin', '/prefixes', 'Rates'],
            'menu.rates.area_codes' => ['Area Codes', 'admin', '/area-codes', 'Rates'],
            'menu.rates.custom' => ['User Custom Rates', 'admin', '/custom-rates', 'Rates'],
            'menu.rates.import_tariffs' => ['Import Tariffs', 'admin', '/tariffs/import', 'Rates'],
            'menu.rates.import_prefixes' => ['Import Prefixes', 'admin', '/prefixes/import', 'Rates'],
            'menu.rates.import_areas' => ['Import Area Codes', 'admin', '/area-codes/import', 'Rates'],
            'menu.reports.cdr' => ['CDR', 'admin', '/cdrs', 'Reports'],
            'menu.reports.recon' => ['CDR Reconciliation', 'admin', '/reports/cdr-reconciliation', 'Reports'],
            'menu.reports.summary' => ['Call summaries', 'admin', '/reports/summary-', 'Reports'],
            'menu.reports.pdd' => ['PDD', 'admin', '/reports/pdd', 'Reports'],
            'menu.reports.sip_response' => ['SIP Response', 'admin', '/reports/sip-response', 'Reports'],
            'menu.reports.financial' => ['Financial', 'admin', '/reports/financial', 'Reports'],
            'menu.reports.recordings' => ['Recordings', 'admin', '/recordings', 'Reports'],
            'menu.reports.fraud' => ['Fraud Alerts', 'admin', '/fraud', 'Reports'],
            'menu.reports.audit' => ['Audit Log', 'admin', '/audit-logs', 'Reports'],
            'menu.routes.providers' => ['Providers', 'admin', '/providers', 'Routes'],
            'menu.routes.trunks' => ['Trunks', 'admin', '/trunks', 'Routes'],
            'menu.routes.trunk_groups' => ['Trunk Groups', 'admin', '/trunk-groups', 'Routes'],
            'menu.routes.provider_rates' => ['Provider Rates', 'admin', '/provider-rates', 'Routes'],
            'menu.routes.dest_policies' => ['Destination Policies', 'admin', '/destination-policies', 'Routes'],
            'menu.routes.simulator' => ['Simulator', 'admin', '/routing/simulate', 'Routes'],
            'menu.stats.users' => ['Stats Users', 'admin', '/stats/users', 'Stats'],
            'menu.stats.carriers' => ['Stats Carriers', 'admin', '/stats/carriers', 'Stats'],
            'menu.settings.general' => ['Settings', 'admin', '/settings', 'Settings'],
            'menu.settings.docs' => ['Documentation', 'admin', '/docs', 'Settings'],
            'menu.settings.ssl' => ['SSL', 'admin', '/settings/ssl', 'Settings'],
            'menu.settings.stir' => ['STIR/SHAKEN', 'admin', '/settings/stir-shaken', 'Settings'],
            'menu.settings.webrtc' => ['WebRTC', 'admin', '/settings/webrtc', 'Settings'],
            'menu.settings.cli' => ['CLI Restriction', 'admin', '/cli-restrictions', 'Settings'],
            'menu.settings.callerid' => ['CallerID', 'admin', '/callerid-groups', 'Settings'],
            'menu.settings.dnc' => ['DNC', 'admin', '/dnc', 'Settings'],
            'menu.settings.firewall' => ['Firewall', 'admin', '/settings/firewall', 'Settings'],
            'menu.settings.version' => ['Update', 'admin', '/settings/version', 'Settings'],
            'menu.settings.system_status' => ['System Status', 'admin', '/system/status', 'Settings'],
            'menu.settings.user_access' => ['User Access', 'admin', '/settings/user-access', 'Settings'],

            // Admin dashboard widgets
            'dash.admin.host' => ['Host metrics (CPU/RAM/Disk)', 'admin', null, 'Admin dashboard'],
            'dash.admin.kpis' => ['Traffic KPI cards', 'admin', null, 'Admin dashboard'],
            'dash.admin.money' => ['Buying / selling / profit', 'admin', null, 'Admin dashboard'],
            'dash.admin.traffic' => ['Traffic charts', 'admin', null, 'Admin dashboard'],
            'dash.admin.routing' => ['Routing / failures panel', 'admin', null, 'Admin dashboard'],
            'dash.admin.shortcuts' => ['Quick links', 'admin', null, 'Admin dashboard'],

            // Customer menu / pages
            'menu.customer.sip_users' => ['SIP Users', 'customer', '/sip-users', 'Customer menu'],
            'menu.customer.web_dialer' => ['Web-Dialer', 'customer', '/web-dialer', 'Customer menu'],
            'menu.customer.calls_online' => ['Calls Online', 'customer', '/calls-online', 'Customer menu'],
            'menu.customer.ip_endpoints' => ['IP Endpoints', 'customer', '/ip-endpoints', 'Customer menu'],
            'menu.customer.dids' => ['My DIDs', 'customer', '/did-assignments', 'Customer menu'],
            'menu.customer.payments' => ['Payments', 'customer', '/payments', 'Customer menu'],
            'menu.customer.redeem' => ['Redeem voucher', 'customer', '/billing/redeem-voucher', 'Customer menu'],
            'menu.customer.my_rates' => ['My Tariff', 'customer', '/my-rates', 'Customer menu'],
            'menu.customer.cdr' => ['CDR', 'customer', '/cdrs', 'Customer menu'],
            'menu.customer.reports' => ['Call summaries / reports', 'customer', '/reports/', 'Customer menu'],
            'menu.customer.recordings' => ['Recordings', 'customer', '/recordings', 'Customer menu'],

            // Customer dashboard widgets
            'dash.customer.balance' => ['Balance / runway cards', 'customer', null, 'Customer dashboard'],
            'dash.customer.attention' => ['Attention alerts', 'customer', null, 'Customer dashboard'],
            'dash.customer.traffic' => ['Traffic KPIs & charts', 'customer', null, 'Customer dashboard'],
            'dash.customer.sip_limits' => ['SIP limits panel', 'customer', null, 'Customer dashboard'],
            'dash.customer.payments' => ['Payments shortcut', 'customer', null, 'Customer dashboard'],
        ];
    }

    /** @return array<string,string> path => feature key for menu items */
    public static function menuPathMap(): array
    {
        return [
            '/users' => 'menu.clients.users',
            '/sip-users' => 'menu.clients.sip_users',
            '/web-dialer' => 'menu.clients.web_dialer',
            '/calls-online' => 'menu.clients.calls_online',
            '/ip-endpoints' => 'menu.clients.ip_endpoints',
            '/did-providers' => 'menu.dids.providers',
            '/dids' => 'menu.dids.dids',
            '/did-assignments' => 'menu.dids.assignments',
            '/billing/refill' => 'menu.billing.refills',
            '/payments' => 'menu.billing.payments',
            '/vouchers' => 'menu.billing.vouchers',
            '/billing/preview' => 'menu.billing.preview',
            '/billing/redeem-voucher' => 'menu.customer.redeem',
            '/rate-plans' => 'menu.rates.plans',
            '/plan-prefixes' => 'menu.rates.plan_prefixes',
            '/tariffs' => 'menu.rates.tariffs',
            '/prefixes' => 'menu.rates.prefixes',
            '/area-codes' => 'menu.rates.area_codes',
            '/custom-rates' => 'menu.rates.custom',
            '/tariffs/import' => 'menu.rates.import_tariffs',
            '/prefixes/import' => 'menu.rates.import_prefixes',
            '/area-codes/import' => 'menu.rates.import_areas',
            '/cdrs' => 'menu.reports.cdr',
            '/reports/cdr-reconciliation' => 'menu.reports.recon',
            '/reports/pdd' => 'menu.reports.pdd',
            '/reports/sip-response' => 'menu.reports.sip_response',
            '/reports/financial' => 'menu.reports.financial',
            '/recordings' => 'menu.reports.recordings',
            '/fraud' => 'menu.reports.fraud',
            '/audit-logs' => 'menu.reports.audit',
            '/my-rates' => 'menu.customer.my_rates',
            '/providers' => 'menu.routes.providers',
            '/trunks' => 'menu.routes.trunks',
            '/trunk-groups' => 'menu.routes.trunk_groups',
            '/provider-rates' => 'menu.routes.provider_rates',
            '/destination-policies' => 'menu.routes.dest_policies',
            '/routing/simulate' => 'menu.routes.simulator',
            '/stats/users' => 'menu.stats.users',
            '/stats/carriers' => 'menu.stats.carriers',
            '/settings' => 'menu.settings.general',
            '/docs' => 'menu.settings.docs',
            '/settings/ssl' => 'menu.settings.ssl',
            '/settings/stir-shaken' => 'menu.settings.stir',
            '/settings/webrtc' => 'menu.settings.webrtc',
            '/cli-restrictions' => 'menu.settings.cli',
            '/callerid-groups' => 'menu.settings.callerid',
            '/dnc' => 'menu.settings.dnc',
            '/settings/firewall' => 'menu.settings.firewall',
            '/settings/version' => 'menu.settings.version',
            '/system/status' => 'menu.settings.system_status',
            '/settings/user-access' => 'menu.settings.user_access',
        ];
    }

    /** Pages only the vendor operator may open (Admin Access matrix). Not assignable to admins. */
    public static function isVendorOnlyPath(string $path): bool
    {
        $path = '/' . ltrim((string) strtok($path, '?'), '/');
        foreach (['/settings/admin-access', '/settings/feature-access'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    public static function isVendorUser(?array $user): bool
    {
        // Privilege comes only from the protected DB flag — never from a matching display name/email.
        return $user !== null && !empty($user['is_vendor']);
    }

    public function ensureSchemaAndVendor(): void
    {
        try {
            $st = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_vendor'"
            );
            if ((int) $st->fetchColumn() === 0) {
                $this->pdo->exec('ALTER TABLE users ADD COLUMN is_vendor TINYINT(1) NOT NULL DEFAULT 0');
            }
        } catch (\Throwable) {
        }
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS feature_visibility (
                    feature_key VARCHAR(96) NOT NULL PRIMARY KEY,
                    audience VARCHAR(16) NOT NULL,
                    enabled TINYINT(1) NOT NULL DEFAULT 1,
                    updated_by BIGINT UNSIGNED NULL,
                    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable) {
        }
        try {
            (new UserAccessService($this->pdo))->ensureSchema();
        } catch (\Throwable) {
        }
        $this->seedDefaults();
        $this->ensureVendorUser(false);
        $this->ensureDefaultAdminUser(false);
    }

    public function seedDefaults(): void
    {
        try {
            $ins = $this->pdo->prepare(
                'INSERT IGNORE INTO feature_visibility (feature_key, audience, enabled) VALUES (?,?,?)'
            );
            // Formerly vendor-only pages stay off until xceedconnections enables them in Admin Access.
            $defaultOff = [
                'menu.settings.version' => true,
                'menu.settings.system_status' => true,
            ];
            foreach (self::catalog() as $key => [$label, $audience]) {
                $on = isset($defaultOff[$key]) ? 0 : 1;
                $ins->execute([$key, $audience, $on]);
            }
            // DNC / CallerID moved into Settings; carry prior toggles if present.
            $this->pdo->exec(
                "UPDATE feature_visibility fv
                 INNER JOIN feature_visibility old ON old.feature_key = 'menu.routes.dnc'
                 SET fv.enabled = old.enabled
                 WHERE fv.feature_key = 'menu.settings.dnc'"
            );
            $this->pdo->exec(
                "UPDATE feature_visibility fv
                 INNER JOIN feature_visibility old ON old.feature_key = 'menu.clients.callerid'
                 SET fv.enabled = old.enabled
                 WHERE fv.feature_key = 'menu.settings.callerid'"
            );
        } catch (\Throwable $e) {
            error_log('[ownswitch] feature seed: ' . $e->getMessage());
        }
    }

    public function ensureVendorUser(bool $resetPassword = false): void
    {
        try {
            $users = new UserRepository($this->pdo);
            $roleId = $users->roleId('super_admin');
            if ($roleId === null) {
                return;
            }
            $existing = $users->findByLogin(self::VENDOR_USERNAME) ?? $users->findByEmail(self::VENDOR_EMAIL);
            $hash = password_hash(self::VENDOR_PASSWORD, PASSWORD_DEFAULT);
            if ($existing === null) {
                $id = $users->insert([
                    'email' => self::VENDOR_EMAIL,
                    'name' => self::VENDOR_USERNAME,
                    'password_hash' => $hash,
                    'role_id' => $roleId,
                    'reseller_id' => null,
                    'customer_id' => null,
                    'status' => 'active',
                    'is_vendor' => 1,
                ]);
                $this->pdo->prepare('UPDATE users SET is_vendor = 1, failed_logins = 0, locked_until = NULL WHERE id = ?')
                    ->execute([(int) $id]);
                return;
            }
            // Keep is_vendor / role / status; do not overwrite name/email — the vendor may change them on My profile.
            $this->pdo->prepare(
                'UPDATE users SET is_vendor = 1, role_id = ?, status = ?, failed_logins = 0, locked_until = NULL WHERE id = ?'
            )->execute([$roleId, 'active', (int) $existing['id']]);
            if ($resetPassword) {
                $users->setPasswordHash((int) $existing['id'], $hash);
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] ensure vendor: ' . $e->getMessage());
        }
    }

    /** Platform admin login created on every fresh install (role=admin). */
    public function ensureDefaultAdminUser(bool $resetPassword = false): void
    {
        try {
            $users = new UserRepository($this->pdo);
            $roleId = $users->roleId('admin');
            if ($roleId === null) {
                return;
            }
            $existing = $users->findByLogin(self::DEFAULT_ADMIN_USERNAME)
                ?? $users->findByEmail(self::DEFAULT_ADMIN_EMAIL);
            $hash = password_hash(self::DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT);
            if ($existing === null) {
                $id = $users->insert([
                    'email' => self::DEFAULT_ADMIN_EMAIL,
                    'name' => self::DEFAULT_ADMIN_USERNAME,
                    'password_hash' => $hash,
                    'role_id' => $roleId,
                    'reseller_id' => null,
                    'customer_id' => null,
                    'status' => 'active',
                    'is_vendor' => 0,
                ]);
                $this->pdo->prepare(
                    'UPDATE users SET is_vendor = 0, managed_by_user_id = NULL, failed_logins = 0, locked_until = NULL WHERE id = ?'
                )->execute([(int) $id]);
                return;
            }
            // Never promote/demote vendor rows; keep stock admin as role=admin + active.
            if (!empty($existing['is_vendor'])) {
                return;
            }
            $this->pdo->prepare(
                'UPDATE users SET role_id = ?, status = ?, is_vendor = 0, failed_logins = 0, locked_until = NULL WHERE id = ?'
            )->execute([$roleId, 'active', (int) $existing['id']]);
            if ($resetPassword) {
                $users->setPasswordHash((int) $existing['id'], $hash);
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] ensure default admin: ' . $e->getMessage());
        }
    }

    /** @return array<string,bool> */
    public function flags(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        foreach (self::catalog() as $key => $meta) {
            $out[$key] = true;
        }
        try {
            $rows = $this->pdo->query('SELECT feature_key, enabled FROM feature_visibility')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $out[(string) $row['feature_key']] = !empty($row['enabled']);
            }
        } catch (\Throwable) {
        }
        return $this->cache = $out;
    }

    public function enabled(string $key): bool
    {
        $flags = $this->flags();
        return array_key_exists($key, $flags) ? $flags[$key] : true;
    }

    public function canSee(?array $user, string $key): bool
    {
        if (self::isVendorUser($user)) {
            return true;
        }
        if (!$this->enabled($key)) {
            return false;
        }
        // Sub-admins only get keys explicitly granted by their managing admin.
        if (UserAccessService::isManagedUser($user)) {
            return $this->userGrantEnabled((int) $user['id'], $key);
        }
        return true;
    }

    /** @var array<int, array<string,bool>> */
    private array $userGrantCache = [];

    private function userGrantEnabled(int $userId, string $key): bool
    {
        if (!isset($this->userGrantCache[$userId])) {
            $this->userGrantCache[$userId] = (new UserAccessService($this->pdo))->grantsFor($userId);
        }
        return !empty($this->userGrantCache[$userId][$key]);
    }

    /**
     * Whether the current user may open this request path.
     * Vendor always allowed. Account/password always allowed.
     */
    public function pathAllowed(?array $user, string $path): bool
    {
        if (self::isVendorUser($user)) {
            return true;
        }
        if (self::isVendorOnlyPath($path)) {
            return false;
        }
        $path = '/' . ltrim($path, '/');
        if ($path === '/' || $path === '') {
            return true;
        }
        if (str_starts_with($path, '/account')
            || str_starts_with($path, '/logout')
            || str_starts_with($path, '/api/')
            || str_starts_with($path, '/branding/')) {
            return true;
        }

        $role = (string) ($user['role'] ?? '');
        $isCustomer = in_array($role, ['customer_owner', 'customer_tech'], true);
        $isAdminLike = in_array($role, ['super_admin', 'admin', 'reseller', 'reporter'], true);

        if (str_starts_with($path, '/reports/summary-')) {
            $key = $isCustomer ? 'menu.customer.reports' : 'menu.reports.summary';
            return $this->canSee($user, $key);
        }
        if (str_starts_with($path, '/reports/') && $isCustomer) {
            return $this->canSee($user, 'menu.customer.reports');
        }

        // Prefer exact / longest path map match
        $map = self::menuPathMap();
        $best = null;
        $bestLen = -1;
        foreach ($map as $prefix => $key) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/') || str_starts_with($path, $prefix . '?')) {
                $len = strlen($prefix);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $key;
                }
            }
        }
        if ($best === null) {
            return true;
        }

        // Remap shared paths for customer audience
        if ($isCustomer) {
            $best = match ($best) {
                'menu.clients.sip_users' => 'menu.customer.sip_users',
                'menu.clients.web_dialer' => 'menu.customer.web_dialer',
                'menu.clients.calls_online' => 'menu.customer.calls_online',
                'menu.clients.ip_endpoints' => 'menu.customer.ip_endpoints',
                'menu.dids.assignments' => 'menu.customer.dids',
                'menu.billing.payments' => 'menu.customer.payments',
                'menu.reports.cdr' => 'menu.customer.cdr',
                'menu.reports.recordings' => 'menu.customer.recordings',
                default => $best,
            };
            $meta = self::catalog()[$best] ?? null;
            if ($meta !== null && $meta[1] !== 'customer') {
                // Admin-only feature path: customers shouldn't hit it via role middleware anyway
                return true;
            }
        } elseif ($isAdminLike) {
            $meta = self::catalog()[$best] ?? null;
            if ($meta !== null && $meta[1] === 'customer') {
                return true;
            }
        }

        return $this->canSee($user, $best);
    }

    /** Menu path visible for this role/user. */
    public function menuPathVisible(?array $user, string $path, string $role): bool
    {
        if (self::isVendorUser($user)) {
            return true;
        }
        return $this->pathAllowed($user, $path);
    }

    /**
     * @param array<string,int> $enabledMap feature_key => 0|1
     */
    public function save(array $enabledMap, ?int $updatedBy): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO feature_visibility (feature_key, audience, enabled, updated_by)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP(3)'
        );
        foreach (self::catalog() as $key => [$label, $audience]) {
            $on = !empty($enabledMap[$key]) ? 1 : 0;
            $st->execute([$key, $audience, $on, $updatedBy]);
        }
        $this->cache = null;
    }
}
