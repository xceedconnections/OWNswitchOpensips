<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper (single per-trunk fork): CAP + trunk limits + trunk CallerID pick.
 *
 *   php bin/dial-trunk-gate.php <sip_username> <destination> <trunk_id> [callerid_group_id]
 *
 * Always exit 0. stdout:
 *   ALLOW … trunk_cid=…
 *   DENY_USER …
 *   DENY_TRUNK …
 *   DENY_LIMIT …
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Billing\RateCapService;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Dial\CallLimitService;
use Ownswitch\Services\Dial\CallerIdPickerService;
use Ownswitch\Services\Dial\DialTrunkGateService;
use Ownswitch\Services\Settings\SettingsService;

$user = isset($argv[1]) ? (string) $argv[1] : '';
$dst = isset($argv[2]) ? (string) $argv[2] : '';
$tid = isset($argv[3]) ? (int) preg_replace('/\D+/', '', (string) $argv[3]) : 0;
$cidg = isset($argv[4]) ? (int) preg_replace('/\D+/', '', (string) $argv[4]) : 0;

$pdo = App::db();
$rates = new RateResolver($pdo, new SettingsService($pdo));
$svc = new DialTrunkGateService(
    $pdo,
    new RateCapService($pdo, $rates),
    new CallLimitService($pdo),
    new CallerIdPickerService($pdo),
);
$res = $svc->authorize($user !== '' ? $user : null, $dst, $tid, $cidg);

if (!$res['ok']) {
    $cls = (string) ($res['deny_class'] ?? 'DENY_TRUNK');
    $reason = (string) ($res['deny'] ?? $res['reason'] ?? 'deny');
    $line = $cls . ' ' . $reason;
    fwrite(STDOUT, $line . "\n");
    fwrite(STDERR, $line . "\n");
    exit(0);
}

$cid = preg_replace('/[^0-9+]/', '', (string) ($res['trunk_cid'] ?? '')) ?? '';
$reason = preg_replace('/[^A-Za-z0-9_.\-]/', '_', (string) $res['reason']) ?? 'ok';
fwrite(STDOUT, sprintf(
    "reason=%s;has_trunkcap=%d;has_trklim=%d;has_trunkcidg=%d;trunk_cid=%s\n",
    $reason,
    (int) $res['has_trunkcap'],
    (int) $res['has_trklim'],
    (int) $res['has_trunkcidg'],
    $cid
));
exit(0);
