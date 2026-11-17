<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper (single pre-route fork).
 *
 *   php bin/dial-invite.php <sip_username> <destination>
 *
 * exit 0 = ALLOW line on stdout; exit 1 = DENY …
 * Unused features are skipped inside the service when not configured.
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Dial\CallLimitService;
use Ownswitch\Services\Dial\CallerIdPickerService;
use Ownswitch\Services\Dial\DialInviteService;
use Ownswitch\Services\Settings\SettingsService;

$user = isset($argv[1]) ? (string) $argv[1] : '';
$dst = isset($argv[2]) ? (string) $argv[2] : '';

$pdo = App::db();
$svc = new DialInviteService(
    $pdo,
    new RateResolver($pdo, new SettingsService($pdo)),
    new CallLimitService($pdo),
    new CallerIdPickerService($pdo),
);
$res = $svc->prepare($user, $dst);
$f = $res['fields'];

// Semicolon params for OpenSIPS $(var){param.value,key}
$parts = [];
foreach ($f as $k => $v) {
    $v = (string) $v;
    if ($v === '' || str_contains($v, ';') || str_contains($v, ' ')) {
        continue;
    }
    $parts[] = $k . '=' . $v;
}
$payload = implode(';', $parts);

if (!$res['ok']) {
    $deny = (string) ($res['deny'] ?? 'deny');
    $line = 'DENY ' . $deny . ($payload !== '' ? ' ' . $payload : '');
    fwrite(STDOUT, $line . "\n");
    fwrite(STDERR, $line . "\n");
    exit(1);
}

// No "ALLOW " prefix — OpenSIPS $(var){param.value,key} needs bare k=v;k=v
fwrite(STDOUT, $payload . "\n");
exit(0);
