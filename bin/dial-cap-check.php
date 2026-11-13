<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper for Rate CAP.
 * Always exit 0; stdout tells OpenSIPS what to do:
 *   ALLOW ...
 *   DENY_USER ...   → final 403 (do not try next trunk)
 *   DENY_TRUNK ...  → try next gateway in trunk group
 *
 *   php bin/dial-cap-check.php <sip_username> <destination> <trunk_id>
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Billing\RateCapService;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Settings\SettingsService;

$user = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $argv[1]) : '';
$dst = isset($argv[2]) ? preg_replace('/\D+/', '', (string) $argv[2]) : '';
$tid = isset($argv[3]) ? (int) preg_replace('/\D+/', '', (string) $argv[3]) : 0;

if ($dst === '' || $tid <= 0) {
    fwrite(STDOUT, "ALLOW skip\n");
    exit(0);
}

$pdo = App::db();
$svc = new RateCapService($pdo, new RateResolver($pdo, new SettingsService($pdo)));
$res = $svc->authorize($user !== '' ? $user : null, $dst, $tid);

if ($res['allow']) {
    fwrite(STDOUT, 'ALLOW ' . $res['reason'] . "\n");
    exit(0);
}

$reason = (string) ($res['reason'] ?? 'cap');
$isUser = str_starts_with($reason, 'User CAP')
    || str_contains($reason, 'SIP account has no customer')
    || str_contains($reason, 'no customer tariff');

if ($isUser) {
    fwrite(STDOUT, 'DENY_USER ' . $reason . "\n");
    fwrite(STDERR, 'DENY_USER ' . $reason . "\n");
    exit(0);
}

fwrite(STDOUT, 'DENY_TRUNK ' . $reason . "\n");
fwrite(STDERR, 'DENY_TRUNK ' . $reason . "\n");
exit(0);
