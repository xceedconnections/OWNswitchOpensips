<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Navigation — OpenSIPS-backed portal features (no duplicate paths).
 */
final class Menu
{
    public const STAFF = ['super_admin', 'admin'];
    public const ALL = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech', 'reporter'];
    public const RESELLER_UP = ['super_admin', 'admin', 'reseller'];
    public const SIP_USERS = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech'];
    public const FINANCE = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech', 'reporter'];
    public const LEDGER_READ = ['super_admin', 'admin', 'reseller', 'customer_owner', 'reporter'];
    public const DID_STAFF = ['super_admin', 'admin', 'reseller'];
    public const DID_VIEW = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech'];
    public const CUSTOMER_RATES = ['customer_owner', 'customer_tech'];
    /** Customer-login roles (self-service billing such as voucher redemption). */
    public const CUSTOMER = ['customer_owner', 'customer_tech'];

    /**
     * @param array<string,mixed>|null $user current user (for vendor / feature gates)
     * @return list<array{label:string,items:list<array{label:string,path:string}>}>
     */
    public static function for(string $role, ?array $user = null): array
    {
        $sections = [
            ['Clients', [
                ['Users', '/users', self::RESELLER_UP],
                ['SIP Users', '/sip-users', self::SIP_USERS],
                ['Web-Dialer', '/web-dialer', self::SIP_USERS],
                ['Calls Online', '/calls-online', self::SIP_USERS],
                ['IP Endpoints', '/ip-endpoints', self::SIP_USERS],
            ]],
            ['DIDs', [
                ['DID Providers', '/did-providers', self::DID_STAFF],
                ['DIDs', '/dids', self::DID_STAFF],
                ['My DIDs', '/did-assignments', self::DID_VIEW],
            ]],
            ['Billing', [
                ['Refills', '/billing/refill', self::STAFF],
                ['Payments', '/payments', self::FINANCE],
                ['Vouchers', '/vouchers', self::STAFF],
                ['Redeem voucher', '/billing/redeem-voucher', self::CUSTOMER],
                ['Billing Preview', '/billing/preview', self::STAFF],
            ]],
            ['Rates', [
                ['Plans', '/rate-plans', self::STAFF],
                ['Plan Prefixes', '/plan-prefixes', self::STAFF],
                ['Tariffs', '/tariffs', self::STAFF],
                ['Prefixes', '/prefixes', self::STAFF],
                ['Area Codes', '/area-codes', self::STAFF],
                ['User Custom Rates', '/custom-rates', self::STAFF],
                ['Import Tariffs', '/tariffs/import', self::STAFF],
                ['Import Prefixes', '/prefixes/import', self::STAFF],
                ['Import Area Codes', '/area-codes/import', self::STAFF],
            ]],
            ['Reports', [
                ['CDR', '/cdrs', self::ALL],
                ['CDR Reconciliation', '/reports/cdr-reconciliation', self::FINANCE],
                ['Summary per Day', '/reports/summary-day', self::ALL],
                ['Summary Day User', '/reports/summary-day-user', self::ALL],
                ['Summary Day Trunk', '/reports/summary-day-trunk', self::STAFF],
                ['Summary per Month', '/reports/summary-month', self::ALL],
                ['Summary Month User', '/reports/summary-month-user', self::ALL],
                ['Summary Month Trunk', '/reports/summary-month-trunk', self::STAFF],
                ['Summary per User', '/reports/summary-user', self::ALL],
                ['Summary per Trunk', '/reports/summary-trunk', self::STAFF],
                ['Summary per Destination', '/reports/summary-destination', self::ALL],
                ['Summary Month Destination', '/reports/summary-month-destination', self::ALL],
                ['PDD', '/reports/pdd', self::ALL],
                ['SIP Response Breakdown', '/reports/sip-response', self::ALL],
                ['Financial', '/reports/financial', self::FINANCE],
                ['Recordings', '/recordings', ['super_admin', 'admin', 'reseller', 'customer_owner']],
                ['Fraud Alerts', '/fraud', self::RESELLER_UP],
                ['Audit Log', '/audit-logs', self::STAFF],
            ]],
            ['Routes', [
                ['My Tariff', '/my-rates', self::CUSTOMER_RATES],
                ['Providers', '/providers', self::STAFF],
                ['Trunks', '/trunks', self::STAFF],
                ['Trunk Groups', '/trunk-groups', self::STAFF],
                ['Provider Rates', '/provider-rates', self::STAFF],
                ['Destination Policies', '/destination-policies', self::STAFF],
                ['Simulator', '/routing/simulate', self::STAFF],
            ]],
            ['Stats', [
                ['Users', '/stats/users', self::STAFF],
                ['Carriers', '/stats/carriers', self::STAFF],
            ]],
            ['Settings', [
                ['Settings', '/settings', self::STAFF],
                ['Documentation', '/docs', self::STAFF],
                ['SSL', '/settings/ssl', self::STAFF],
                ['STIR/SHAKEN', '/settings/stir-shaken', self::STAFF],
                ['WebRTC', '/settings/webrtc', self::STAFF],
                ['CLI Restriction', '/cli-restrictions', self::STAFF],
                ['CallerID', '/callerid-groups', self::STAFF],
                ['DNC', '/dnc', self::STAFF],
                ['Firewall', '/settings/firewall', self::STAFF],
                ['Update', '/settings/version', self::STAFF],
                ['System Status', '/system/status', self::STAFF],
                ['User Access', '/settings/user-access', self::STAFF],
                ['Admin Access', '/settings/admin-access', self::STAFF],
            ]],
        ];
        $features = null;
        try {
            $features = new \Ownswitch\Services\Access\FeatureVisibilityService(\Ownswitch\App::db());
        } catch (\Throwable) {
            $features = null;
        }
        $isVendor = \Ownswitch\Services\Access\FeatureVisibilityService::isVendorUser($user);
        $out = [];
        foreach ($sections as [$label, $items]) {
            $visible = [];
            foreach ($items as [$l, $p, $roles]) {
                // Admin Access matrix stays vendor-only (not assignable via toggles).
                if (in_array($p, ['/settings/admin-access', '/settings/feature-access'], true) && !$isVendor) {
                    continue;
                }
                if (!in_array($role, $roles, true)) {
                    continue;
                }
                if ($features !== null && !$features->menuPathVisible($user, $p, $role)) {
                    continue;
                }
                $visible[] = ['label' => $l, 'path' => $p];
            }
            if ($visible) {
                $out[] = ['label' => $label, 'items' => $visible];
            }
        }
        return $out;
    }

    /** @return array<string,array{title:string,blurb:string,columns:list<string>}> */
    public static function scaffolds(): array
    {
        return [];
    }
}
