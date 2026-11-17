<?php

declare(strict_types=1);

namespace Ownswitch\Services\Dial;

use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Support\DstFormat;
use Ownswitch\Support\DstPrefixMap;
use Ownswitch\Support\MediaPolicy;
use PDO;

/**
 * Single pre-route dial helper: profile + optional rewrite + rate + optional SIP limits + optional SIP CallerID.
 * Unused features are skipped (no extra work) but remain available when configured.
 */
final class DialInviteService
{
    public function __construct(
        private PDO $pdo,
        private RateResolver $rates,
        private CallLimitService $limits,
        private CallerIdPickerService $callerIds,
    ) {
    }

    /**
     * @return array{ok:bool,deny:?string,fields:array<string,string>}
     */
    public function prepare(string $username, string $destination): array
    {
        $user = preg_replace('/[^A-Za-z0-9_.\-]/', '', $username) ?? '';
        $dst = preg_replace('/\D+/', '', $destination) ?? '';
        $fields = [
            'dncid' => '0',
            'cid_mode' => 'passthrough',
            'cid' => '',
            'media' => 'relay',
            'dtmf' => '1',
            'allow' => '1',
            'cidmin' => '0',
            'cidmax' => '0',
            'dstfmt' => 'as_is',
            'dst' => $dst,
            'route_dst' => $dst,
            'rplan' => '0',
            'rate' => '',
            'dial_prefix' => '',
            'sip_cid' => '',
            'has_dnc' => '0',
            'has_cli' => '0',
            'has_usercidg' => '0',
            'has_usercap' => '0',
            'has_siplim' => '0',
            'has_dstmap' => '0',
        ];

        if ($dst === '' || strlen($dst) < 3) {
            return ['ok' => false, 'deny' => 'empty_destination', 'fields' => $fields];
        }
        if ($user === '') {
            return ['ok' => false, 'deny' => 'no_user', 'fields' => $fields];
        }

        $acct = $this->loadAccount($user);
        if ($acct === null) {
            return ['ok' => false, 'deny' => 'no_customer', 'fields' => $fields];
        }

        $fields = array_merge($fields, $this->profileFields($acct));
        if (($fields['allow'] ?? '1') === '0') {
            return ['ok' => false, 'deny' => 'payment_required', 'fields' => $fields];
        }

        // Feature flags first so DENY responses still tell OpenSIPS what to skip.
        $map = (string) ($acct['dst_prefix_map'] ?? '');
        $hasMap = DstPrefixMap::parse($map) !== [];
        $fields['has_dstmap'] = $hasMap ? '1' : '0';
        $gid = (int) ($acct['callerid_group_id'] ?? 0);
        $fields['has_usercidg'] = $gid > 0 ? '1' : '0';
        $cap = $acct['rate_cap'] ?? null;
        $fields['has_usercap'] = ($cap !== null && $cap !== '' && is_numeric($cap) && (float) $cap > 0) ? '1' : '0';
        $fields['has_dnc'] = ((int) ($acct['dnc_group_id'] ?? 0) > 0) ? '1' : '0';
        $fields['has_cli'] = $this->hasCliRestriction((int) ($acct['id'] ?? 0)) ? '1' : '0';
        $maxCh = (int) ($acct['max_channels'] ?? -1);
        $maxCps = (int) ($acct['max_cps'] ?? -1);
        if ($maxCps === 0) {
            $maxCps = 1;
        }
        $needsSipLim = ($maxCh >= 0) || ($maxCps >= 0);
        $fields['has_siplim'] = $needsSipLim ? '1' : '0';

        if ($hasMap) {
            $dst = DstPrefixMap::apply($map, $dst);
        }
        $fields['dst'] = $dst;

        $customerId = (int) ($acct['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return ['ok' => false, 'deny' => 'no_customer', 'fields' => $fields];
        }

        $rate = $this->rates->customer($customerId, $dst);
        if ($rate === null) {
            return ['ok' => false, 'deny' => 'unrated', 'fields' => $fields];
        }

        $rplan = 0;
        if (!empty($acct['routing_plan_id'])) {
            $rplan = (int) $acct['routing_plan_id'];
        } elseif (!empty($rate['routing_plan_id'])) {
            $rplan = (int) $rate['routing_plan_id'];
        }
        $routeDst = $this->rates->destinationAfterPlanPrefix($customerId, $dst);
        if ($routeDst === '' || strlen($routeDst) < 3) {
            $routeDst = $dst;
        }
        $dialPfx = preg_replace('/\D+/', '', (string) ($rate['dial_prefix'] ?? '')) ?? '';

        $fields['rplan'] = (string) $rplan;
        $fields['route_dst'] = $routeDst;
        $fields['rate'] = (string) ($rate['rate'] ?? '');
        $fields['dial_prefix'] = $dialPfx;
        $fields['tariff'] = (string) ($rate['prefix'] ?? '');

        if ($needsSipLim) {
            $lim = $this->limits->authorizeSipUser($user);
            if (!$lim['allow']) {
                return ['ok' => false, 'deny' => 'congestion', 'fields' => $fields];
            }
        }

        if ($gid > 0) {
            $pick = $this->callerIds->pickFromGroup($gid, $routeDst !== '' ? $routeDst : $dst);
            if ($pick !== null && $pick['cli'] !== '') {
                $fields['sip_cid'] = $pick['cli'];
            }
        }

        return ['ok' => true, 'deny' => null, 'fields' => $fields];
    }

    /** @return array<string,mixed>|null */
    private function loadAccount(string $user): ?array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT s.id, s.customer_id, s.routing_plan_id, s.dnc_group_id, s.caller_id_mode, s.caller_id,
                        s.media_policy, s.dtmf_pass, s.cid_min_len, s.cid_max_len, s.dst_format, s.dst_prefix_map,
                        s.callerid_group_id, s.max_channels, s.max_cps, s.rate_cap, s.status,
                        c.status AS customer_status
                 FROM sip_accounts s
                 LEFT JOIN customers c ON c.id = s.customer_id
                 WHERE s.username = ? LIMIT 1"
            );
            $st->execute([$user]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($row) || ($row['status'] ?? '') !== 'active') {
            return null;
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $acct
     * @return array<string,string>
     */
    private function profileFields(array $acct): array
    {
        $allow = 1;
        if (($acct['customer_status'] ?? '') !== '' && ($acct['customer_status'] ?? '') !== 'active') {
            $allow = 0;
        }
        $mode = strtolower(trim((string) ($acct['caller_id_mode'] ?? '')));
        $cid = trim((string) ($acct['caller_id'] ?? ''));
        if ($mode === '' || $mode === 'auto') {
            $mode = $cid !== '' ? 'fixed' : 'passthrough';
        }
        if (!in_array($mode, ['passthrough', 'fixed', 'display_name'], true)) {
            $mode = 'passthrough';
        }
        try {
            $media = MediaPolicy::resolve(
                isset($acct['media_policy']) ? (string) $acct['media_policy'] : null,
                $this->pdo
            );
        } catch (\Throwable) {
            $media = 'relay';
        }
        if (!in_array($media, ['direct', 'relay', 'record'], true)) {
            $media = 'relay';
        }
        $dtmf = strtolower(trim((string) ($acct['dtmf_pass'] ?? 'yes')));
        $dtmfBit = in_array($dtmf, ['0', 'no', 'false', 'off'], true) ? 0 : 1;
        $cidDigits = preg_replace('/[^0-9+]/', '', $cid) ?? '';

        return [
            'dncid' => (string) max(0, (int) ($acct['dnc_group_id'] ?? 0)),
            'cid_mode' => $mode,
            'cid' => $cidDigits,
            'media' => $media,
            'dtmf' => (string) $dtmfBit,
            'allow' => (string) $allow,
            'cidmin' => (string) max(0, (int) ($acct['cid_min_len'] ?? 0)),
            'cidmax' => (string) max(0, (int) ($acct['cid_max_len'] ?? 0)),
            'dstfmt' => DstFormat::wireCode((string) ($acct['dst_format'] ?? 'as_is')),
        ];
    }

    private function hasCliRestriction(int $sipId): bool
    {
        if ($sipId <= 0) {
            return false;
        }
        try {
            $st = $this->pdo->prepare(
                'SELECT 1 FROM sip_account_cli_restrictions a
                 INNER JOIN cli_restrictions g ON g.id = a.cli_restriction_id AND g.status = \'active\'
                 WHERE a.sip_account_id = ? LIMIT 1'
            );
            $st->execute([$sipId]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }
}
