<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CarrierRepository;
use Ownswitch\Services\Routing\OpensipsRoutingSync;
use Ownswitch\Services\Sip\SipOptionsProbe;
use Ownswitch\Services\Dnc\DncService;
use Ownswitch\Support\Crypto;
use Ownswitch\Support\DelayedBye;
use Ownswitch\Support\DstFormat;
use Ownswitch\Support\SipCodecs;
use Ownswitch\Support\SipPeerOptions;

/**
 * Providers + Trunks — OpenSIPS gateway fields (no Asterisk peer junk).
 */
final class CarrierController extends CrudController
{
    public function __construct(private string $kind = 'carrier')
    {
        $trunk = $kind === 'trunk';
        $this->base = $trunk ? '/trunks' : '/providers';
        $this->singular = $trunk ? 'Trunk' : 'Provider';
        $this->plural = $trunk ? 'Trunks' : 'Providers';
        $this->emptyHint = $trunk
            ? 'No trunks. OpenSIPS: Host/port, optional digest REGISTER, strip/prefix, From rewrite, OPTIONS ping.'
            : 'No providers yet. Add the upstream carrier, then add Trunks.';
    }

    protected function repo(): BaseRepository
    {
        return $this->kind === 'trunk' ? CarrierRepository::trunks($this->db()) : CarrierRepository::carriers($this->db());
    }

    protected function filterColumns(): array
    {
        return $this->kind === 'trunk' ? ['carrier_id', 'status', 'auth_mode'] : ['status'];
    }

    protected function fields(Request $r): array
    {
        DstFormat::ensureColumns($this->db());
        DelayedBye::ensureColumns($this->db());
        $status = [
            'name' => 'status',
            'label' => 'Status',
            'type' => 'select',
            'required' => true,
            'options' => ['active' => 'Active', 'disabled' => 'Disabled'],
            'list' => true,
            'help' => 'Active trunks can be selected by routing. Disabled trunks are skipped for new calls.',
        ];
        if ($this->kind !== 'trunk') {
            return [
                ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true, 'help' => 'Friendly provider name shown in trunks, CDRs, and reports.'],
                ['name' => 'notes', 'label' => 'Description', 'type' => 'textarea', 'max' => 2000, 'help' => 'Optional internal notes about this provider (contracts, NOC contacts, etc.).'],
                $status,
            ];
        }
        $carriers = [];
        foreach ($this->db()->query('SELECT id, name FROM carriers ORDER BY name LIMIT 500')->fetchAll() as $c) {
            $carriers[$c['id']] = $c['name'];
        }
        $status['section'] = 'General';
        return [
            ['name' => 'carrier_id', 'label' => 'Provider', 'type' => 'select', 'required' => true, 'options' => $carriers, 'list' => true, 'help' => 'Parent provider this trunk belongs to. Used for reporting and carrier cost grouping.', 'section' => 'General'],
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true, 'help' => 'Trunk label in the portal, routing simulator, and CDR trunk column.', 'section' => 'General'],
            ['name' => 'host', 'label' => 'Host', 'required' => true, 'max' => 190, 'pattern' => '/^[A-Za-z0-9.\-:\[\]]{1,190}$/', 'list' => true, 'help' => 'Vendor SIP IP or hostname OpenSIPS dials as OpenSIPS drouting gateway.', 'section' => 'General'],
            ['name' => 'port', 'label' => 'Port', 'type' => 'int', 'min' => 1, 'max' => 65535, 'required' => true, 'default' => '5060', 'list' => true, 'help' => 'Vendor SIP port (usually 5060 UDP/TCP, or 5061 for TLS).', 'section' => 'General'],
            ['name' => 'transport', 'label' => 'Transport', 'type' => 'select', 'required' => true, 'options' => ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS'], 'default' => 'udp', 'help' => 'SIP transport used toward this vendor. Must match what the carrier expects.', 'section' => 'General'],
            $status,
            ['name' => 'auth_mode', 'label' => 'Auth', 'type' => 'select', 'required' => true, 'list' => true, 'default' => 'ip', 'options' => [
                'ip' => 'IP peer (vendor allowlists us)',
                'register' => 'Outbound REGISTER (user/pass)',
                'invite' => 'Invite digest only',
            ], 'list_options' => [
                'ip' => 'IP peer',
                'register' => 'REGISTER',
                'invite' => 'Invite digest',
            ], 'help' => 'OpenSIPS: IP = no auth (username/password hidden); Register = outbound REGISTER; Invite = digest on outbound only', 'section' => 'General'],
            ['name' => 'auth_username', 'label' => 'Username', 'max' => 120, 'help' => 'Required for Register / Invite. Hidden when Auth is IP peer.', 'section' => 'General'],
            ['name' => 'auth_secret', 'label' => 'Password', 'type' => 'password', 'max' => 190, 'help' => 'Required for Register / Invite. Leave blank on edit to keep current. Hidden when Auth is IP peer.', 'section' => 'General'],
            ['name' => 'add_prefix', 'label' => 'Add prefix', 'max' => 32, 'help' => 'Prepended to the dialled number toward the vendor (e.g. 9 → 912138007447)', 'section' => 'Dial & CLI'],
            ['name' => 'remove_prefix', 'label' => 'Remove prefixes', 'max' => 120, 'help' => 'Comma-separated tech prefixes to strip before send (e.g. 22,011,00). First match wins. Then Add prefix is applied.', 'section' => 'Dial & CLI'],
            ['name' => 'dst_format', 'label' => 'Destination format', 'type' => 'select', 'options' => DstFormat::OPTIONS, 'default' => DstFormat::AS_IS, 'list' => true, 'help' => 'How the dialled number is sent to this vendor after strip/add prefix. Applied after the SIP user’s Destination format. Example: E.164 with + turns 12135550123 into +12135550123.', 'section' => 'Dial & CLI'],
            ['name' => 'from_user', 'label' => 'From user', 'max' => 64, 'help' => 'Forces outbound CallerID / From user to vendors. Overrides SIP user CallerID and anything the PBX (e.g. ViciDial) sends.', 'section' => 'Dial & CLI'],
            ['name' => 'from_domain', 'label' => 'From domain', 'max' => 128, 'help' => 'Domain part of SIP From / RPID / PAI when rewriting CLI toward this vendor. Blank uses a safe default.', 'section' => 'Dial & CLI'],
            ['name' => 'cid_add_prefix', 'label' => 'CID add prefix', 'max' => 32, 'help' => 'Prepended to outbound CallerID digits after CID remove (OpenSIPS origination_caller_id_*).', 'section' => 'Dial & CLI'],
            ['name' => 'cid_remove_prefix', 'label' => 'CID remove prefix', 'max' => 32, 'help' => 'Strip this digit prefix from CallerID before CID add / From user.', 'section' => 'Dial & CLI'],
            ['name' => 'cid_min_len', 'label' => 'Min CallerID length (digits)', 'type' => 'number', 'min' => 0, 'max' => 32, 'help' => 'After trunk CID rewrite (and From user if set). Leave blank to disable. Too short → 484.', 'section' => 'Dial & CLI'],
            ['name' => 'cid_max_len', 'label' => 'Max CallerID length (digits)', 'type' => 'number', 'min' => 0, 'max' => 32, 'help' => 'After trunk CID rewrite. Leave blank to disable. Too long → 484.', 'section' => 'Dial & CLI'],
            ['name' => 'qualify', 'label' => 'Probe gateway', 'type' => 'select', 'options' => SipPeerOptions::YES_NO, 'default' => 'yes', 'help' => 'OpenSIPS gateway ping=30s (OPTIONS). No = ping disabled.', 'section' => 'Limits'],
            ['name' => 'max_channels', 'label' => 'Channels', 'type' => 'int', 'min' => 0, 'max' => 1000000, 'required' => true, 'default' => '100', 'list' => true, 'help' => 'Capacity hint for portal/reporting (not a hard OpenSIPS gateway cap).', 'section' => 'Limits'],
            ['name' => 'max_call_duration', 'label' => 'Max call duration (s)', 'type' => 'default_or_seconds', 'min' => 60, 'max' => 604800, 'default' => '0', 'list' => true, 'system_seconds' => (new \Ownswitch\Services\Settings\MaxCallDuration($this->db()))->systemDefault(), 'help' => 'Default uses system max call duration. Custom sets OpenSIPS max call duration on this gateway. SIP user / prepaid caps still apply.', 'section' => 'Limits'],
            ['name' => 'rate_cap', 'label' => 'CAP (max buy $/min)', 'type' => 'number', 'min' => 0, 'placeholder' => '0.008', 'list' => true, 'help' => 'Compared to this provider’s buy rate for the dialled prefix — independent of SIP user CAP. Example: CAP 0.008 allows buy 1213@0.005, skips this trunk for 1456@0.009 (try next trunk). Blank = no CAP.', 'section' => 'Limits'],
            ['name' => 'dnc_group_id', 'label' => 'DNC group', 'type' => 'select', 'options' => $this->dncGroupOptions(), 'list' => true, 'help' => 'Instant 403 before the vendor is INVITEd when the dialled number is on this list. Prefer also assigning the same group on the SIP user for a pre-routing block. Leave blank for no trunk DNC.', 'section' => 'Limits'],
            [
                'name' => 'delayed_bye',
                'label' => 'Delayed Bye (s)',
                'type' => 'int',
                'min' => 0,
                'max' => DelayedBye::MAX_SECONDS,
                'default' => '0',
                'list' => true,
                'help' => 'Extra seconds OpenSIPS holds BYE toward this vendor after the customer hangs up. 0 = off (unless Delayed Bye MCD is set). Customer charges stay on actual talk time.',
                'section' => 'Delayed Bye',
            ],
            [
                'name' => 'delayed_bye_mcd',
                'label' => 'Delayed Bye MCD (s)',
                'type' => 'int',
                'min' => 0,
                'max' => DelayedBye::MAX_SECONDS,
                'default' => '0',
                'list' => true,
                'help' => 'Minimum connected duration target. Example: MCD 25 with an 8s call holds ~17s so the carrier records ~25s. Hold = max(0, MCD − actual) + Delayed Bye. 0 = off unless Delayed Bye > 0.',
                'section' => 'Delayed Bye',
            ],
            [
                'name' => 'delayed_bye_count_buying',
                'label' => 'Count Delayed duration in Buying',
                'type' => 'checkbox',
                'default' => 0,
                'list' => true,
                'help' => 'When checked, CDR carrier cost / profit uses the extended duration (what the vendor is expected to bill). When unchecked, buy cost uses actual talk time only. Customer sell charge is never extended.',
                'section' => 'Delayed Bye',
            ],
            [
                'name' => 'codecs',
                'label' => 'Codecs',
                'type' => 'multiselect',
                'options' => SipCodecs::formOptions(),
                'add_label' => '+ Add codec…',
                'wide' => true,
                'default' => SipCodecs::defaultStorage($this->db()),
                'help' => 'Gateway codec preference (G729, G711/ulaw/alaw, GSM, …). Empty keeps Settings default on create. Changing codecs rewrites gateway XML only when the list actually changes.',
                'section' => 'Media',
            ],
            ['name' => 'latency_ms', 'label' => 'Reg / Latency', 'list' => true, 'list_only' => true, 'section' => 'General'],
        ];
    }

    protected function extra(Request $r): array
    {
        return ['enhancedForm' => true];
    }

    /** @return array<string,string> */
    private function dncGroupOptions(): array
    {
        try {
            return (new DncService($this->db()))->groupOptions(false);
        } catch (\Throwable) {
            return ['' => '— None —'];
        }
    }

    protected function enrichRows(Request $r, array $rows): array
    {
        if ($this->kind !== 'trunk' || $rows === []) {
            return $rows;
        }
        $prov = OpensipsProvisioner::fromConfig();
        $fsOk = $prov->enabled();
        foreach ($rows as &$row) {
            if (($row['dnc_group_id'] ?? null) === null || $row['dnc_group_id'] === '') {
                $row['dnc_group_id'] = '';
            } else {
                $row['dnc_group_id'] = (string) $row['dnc_group_id'];
            }
            $host = (string) ($row['host'] ?? '');
            $port = (int) ($row['port'] ?? 5060);
            $active = ($row['status'] ?? '') === 'active';
            $mode = strtolower((string) ($row['auth_mode'] ?? 'ip'));
            if (!$active || $host === '') {
                $row['latency_ms'] = '—';
                continue;
            }
            // REGISTER trunks: drouting / OPTIONS PingTime (ms).
            if ($mode === 'register' && $fsOk) {
                $st = $prov->gatewayStatus((int) ($row['id'] ?? 0));
                $state = strtoupper((string) ($st['state'] ?? ''));
                $ms = (int) ($st['latency_ms'] ?? 0);
                $online = in_array($state, ['REGED', 'NOREG'], true)
                    || strtoupper((string) ($st['status'] ?? '')) === 'UP';
                // Presence-style cell: green/red dot + latency ms (falls back to state label).
                $row['latency_ms'] = [
                    'online' => $online,
                    'ms' => $ms > 0 ? $ms : null,
                    'state' => $st['label'] !== '' ? (string) $st['label'] : $state,
                ];
                $row['register_status'] = $state;
                continue;
            }
            $qualify = strtolower((string) ($row['qualify'] ?? 'yes')) === 'yes';
            if (!$qualify) {
                $row['latency_ms'] = '—';
                continue;
            }
            $ms = SipOptionsProbe::rttMs($host, $port);
            $row['latency_ms'] = ($ms !== null) ? $ms : 'n/a';
        }
        unset($row);
        foreach ($rows as &$row) {
            if (array_key_exists('delayed_bye_count_buying', $row)) {
                $row['delayed_bye_count_buying'] = ((int) $row['delayed_bye_count_buying'] === 1) ? 'Yes' : 'No';
            }
        }
        unset($row);
        return $rows;
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        if ($this->kind === 'trunk') {
            $secret = $data['auth_secret'] ?? null;
            unset($data['auth_secret']);
            if ($secret !== null && $secret !== '') {
                try {
                    $data['auth_secret_enc'] = Crypto::encrypt((string) $secret);
                } catch (\RuntimeException $e) {
                    throw new \DomainException('Cannot store the secret: ' . $e->getMessage());
                }
            }
            if ($existing === null) {
                $data['priority'] = $data['priority'] ?? 100;
                $data['connect_fee'] = $data['connect_fee'] ?? '0';
                $data['initial_increment'] = $data['initial_increment'] ?? 6;
                $data['subsequent_increment'] = $data['subsequent_increment'] ?? 6;
                $data['media_policy'] = null;
            }
            if (array_key_exists('codecs', $data)) {
                $stored = SipCodecs::toStorage($data['codecs'] ?? null);
                if ($stored === null) {
                    $data['codecs'] = $existing === null
                        ? SipCodecs::defaultStorage($this->db())
                        : ($existing['codecs'] ?? null);
                } else {
                    $data['codecs'] = $stored;
                }
            } elseif ($existing === null) {
                $data['codecs'] = SipCodecs::defaultStorage($this->db());
            }
            foreach (['add_prefix', 'from_user', 'from_domain', 'cid_add_prefix', 'cid_remove_prefix'] as $k) {
                if (array_key_exists($k, $data) && ($data[$k] === null || $data[$k] === '')) {
                    $data[$k] = null;
                }
            }
            foreach (['cid_min_len', 'cid_max_len'] as $k) {
                if (!array_key_exists($k, $data) || $data[$k] === '' || $data[$k] === null) {
                    $data[$k] = null;
                } else {
                    $data[$k] = max(0, (int) $data[$k]);
                    if ($data[$k] === 0) {
                        $data[$k] = null;
                    }
                }
            }
            if (!array_key_exists('rate_cap', $data) || $data['rate_cap'] === '' || $data['rate_cap'] === null) {
                $data['rate_cap'] = null;
            } else {
                $cap = (float) $data['rate_cap'];
                $data['rate_cap'] = $cap > 0 ? number_format($cap, 8, '.', '') : null;
            }
            if (array_key_exists('max_call_duration', $data)) {
                $mcd = (int) $data['max_call_duration'];
                if ($mcd < 0) {
                    $mcd = 0;
                }
                if ($mcd > 604800) {
                    $mcd = 604800;
                }
                if ($mcd > 0 && $mcd < 60) {
                    $mcd = 60;
                }
                $data['max_call_duration'] = $mcd;
            }
            if (array_key_exists('delayed_bye', $data)) {
                $data['delayed_bye'] = DelayedBye::clampSeconds($data['delayed_bye'] ?? 0);
            } elseif ($existing === null) {
                $data['delayed_bye'] = 0;
            }
            if (array_key_exists('delayed_bye_mcd', $data)) {
                $data['delayed_bye_mcd'] = DelayedBye::clampSeconds($data['delayed_bye_mcd'] ?? 0);
            } elseif ($existing === null) {
                $data['delayed_bye_mcd'] = 0;
            }
            if (array_key_exists('delayed_bye_count_buying', $data)) {
                $data['delayed_bye_count_buying'] = !empty($data['delayed_bye_count_buying']) ? 1 : 0;
            } elseif ($existing === null) {
                $data['delayed_bye_count_buying'] = 0;
            }
            if (array_key_exists('remove_prefix', $data)) {
                $data['remove_prefix'] = self::normalizeRemovePrefixes((string) ($data['remove_prefix'] ?? ''));
            }
            if (!array_key_exists('dnc_group_id', $data) || $data['dnc_group_id'] === '' || $data['dnc_group_id'] === null) {
                $data['dnc_group_id'] = null;
            } else {
                $data['dnc_group_id'] = (int) $data['dnc_group_id'];
                if ($data['dnc_group_id'] <= 0) {
                    $data['dnc_group_id'] = null;
                } else {
                    $opts = (new DncService($this->db()))->groupOptions(false);
                    if (!isset($opts[(string) $data['dnc_group_id']])) {
                        throw new \DomainException('Select a valid DNC group.');
                    }
                }
            }
            $data['dst_format'] = DstFormat::normalize(isset($data['dst_format']) ? (string) $data['dst_format'] : null);
            $mode = (string) ($data['auth_mode'] ?? 'ip');
            if ($mode === 'ip') {
                $data['auth_username'] = null;
                $data['auth_secret_enc'] = null;
            } elseif (in_array($mode, ['register', 'invite'], true)) {
                $user = trim((string) ($data['auth_username'] ?? ($existing['auth_username'] ?? '')));
                $hasSecret = !empty($data['auth_secret_enc']) || !empty($existing['auth_secret_enc'] ?? null);
                if ($user === '' || (!$hasSecret && $existing === null)) {
                    throw new \DomainException('Register / Invite digest modes require Username and Password.');
                }
            }
        } else {
            $data = $this->autoCode($data, $existing, 'carriers');
            $data['is_demo'] = $existing['is_demo'] ?? 0;
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        if ($this->kind !== 'trunk') {
            return null;
        }
        $prov = OpensipsProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return 'Trunk saved in portal only — OpenSIPS is not configured.';
        }
        try {
            $row = $this->repo()->findInternal($id);
            if ($row) {
                $prov->syncTrunk($row);
            }
            $sync = (new OpensipsRoutingSync($this->db(), $prov))->syncAll();
            if ($sync['warnings'] !== []) {
                App::session()->flash('warning', 'Trunk saved with warnings: ' . mb_substr(implode(' | ', $sync['warnings']), 0, 300));
            }
            $mode = strtolower((string) ((is_array($row) ? ($row['auth_mode'] ?? null) : null) ?? ($data['auth_mode'] ?? 'ip')));
            if ($mode === 'register') {
                $st = $prov->gatewayStatus($id);
                return 'Trunk pushed to OpenSIPS. REGISTER state: '
                    . ($st['label'] !== '' ? $st['label'] : 'pending')
                    . ' (REGED = registered to vendor).';
            }
            return 'Trunk pushed to OpenSIPS drouting. Routes stay in the portal.';
        } catch (\Throwable $e) {
            error_log('[ownswitch] OpenSIPS trunk sync: ' . $e->getMessage());
            App::session()->flash('warning', 'Saved, but OpenSIPS sync failed: ' . mb_substr($e->getMessage(), 0, 280));
            return null;
        }
    }

    protected function afterDelete(Request $r, array $row): void
    {
        if ($this->kind !== 'trunk') {
            return;
        }
        $prov = OpensipsProvisioner::fromConfig();
        if ($prov->enabled()) {
            try {
                $prov->removeTrunk((int) $row['id']);
                (new OpensipsRoutingSync($this->db(), $prov))->syncAll();
            } catch (\Throwable) {
                App::session()->flash('warning', 'Deleted locally, but OpenSIPS deprovisioning failed.');
            }
        }
    }

    /** Comma/space/semicolon separated digit prefixes → "22,011,00" or null. */
    public static function normalizeRemovePrefixes(string $raw): ?string
    {
        $parts = [];
        foreach (preg_split('/[\s,;|]+/', $raw) ?: [] as $p) {
            $p = preg_replace('/\D+/', '', $p) ?? '';
            if ($p === '' || in_array($p, $parts, true)) {
                continue;
            }
            $parts[] = $p;
        }
        if ($parts === []) {
            return null;
        }
        // Longest first so "011" wins over "0" when both listed
        usort($parts, static fn ($a, $b) => strlen($b) <=> strlen($a));
        return implode(',', $parts);
    }
}
