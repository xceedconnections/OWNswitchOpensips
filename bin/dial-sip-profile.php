<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: load SIP-user dial AVPs when digest/load_credentials did not run
 * (IP-auth peers). Always exit 0.
 *
 *   php bin/dial-sip-profile.php <sip_username>
 *
 * stdout (single line):
 *   dncid=12;cid_mode=passthrough;cid=;media=relay;dtmf=1;allow=1;cidmin=0;cidmax=0;dstfmt=as_is
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Support\DstFormat;
use Ownswitch\Support\MediaPolicy;

$user = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $argv[1]) : '';
$empty = 'dncid=0;cid_mode=passthrough;cid=;media=relay;dtmf=1;allow=1;cidmin=0;cidmax=0;dstfmt=as_is';
if ($user === '') {
    fwrite(STDOUT, $empty . "\n");
    exit(0);
}

try {
    $st = App::db()->prepare(
        "SELECT s.dnc_group_id, s.caller_id_mode, s.caller_id, s.media_policy, s.dtmf_pass,
                s.cid_min_len, s.cid_max_len, s.dst_format, s.status, s.customer_id,
                c.status AS customer_status
         FROM sip_accounts s
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE s.username = ? LIMIT 1"
    );
    $st->execute([$user]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
} catch (\Throwable) {
    fwrite(STDOUT, $empty . "\n");
    exit(0);
}

if (!is_array($row) || ($row['status'] ?? '') !== 'active') {
    fwrite(STDOUT, 'dncid=0;cid_mode=passthrough;cid=;media=relay;dtmf=1;allow=0;cidmin=0;cidmax=0;dstfmt=as_is' . "\n");
    exit(0);
}
$allow = 1;
if (($row['customer_status'] ?? '') !== '' && ($row['customer_status'] ?? '') !== 'active') {
    $allow = 0;
}

$dncid = max(0, (int) ($row['dnc_group_id'] ?? 0));
$mode = strtolower(trim((string) ($row['caller_id_mode'] ?? '')));
$cid = trim((string) ($row['caller_id'] ?? ''));
if ($mode === '' || $mode === 'auto') {
    $mode = $cid !== '' ? 'fixed' : 'passthrough';
}
if (!in_array($mode, ['passthrough', 'fixed', 'display_name'], true)) {
    $mode = 'passthrough';
}
try {
    $media = MediaPolicy::resolve(
        isset($row['media_policy']) ? (string) $row['media_policy'] : null,
        App::db()
    );
} catch (\Throwable) {
    $media = 'relay';
}
if (!in_array($media, ['direct', 'relay', 'record'], true)) {
    $media = 'relay';
}
$dtmf = strtolower(trim((string) ($row['dtmf_pass'] ?? 'yes')));
$dtmfBit = in_array($dtmf, ['0', 'no', 'false', 'off'], true) ? 0 : 1;
$cidmin = max(0, (int) ($row['cid_min_len'] ?? 0));
$cidmax = max(0, (int) ($row['cid_max_len'] ?? 0));
$dstfmt = DstFormat::wireCode((string) ($row['dst_format'] ?? 'as_is'));
$cidDigits = preg_replace('/[^0-9+]/', '', $cid) ?? '';

fwrite(STDOUT, sprintf(
    "dncid=%d;cid_mode=%s;cid=%s;media=%s;dtmf=%d;allow=%d;cidmin=%d;cidmax=%d;dstfmt=%s\n",
    $dncid,
    $mode,
    $cidDigits,
    $media,
    $dtmfBit,
    $allow,
    $cidmin,
    $cidmax,
    $dstfmt
));
exit(0);
