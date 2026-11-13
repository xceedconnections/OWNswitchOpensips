<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: trunk concurrent + CPS gate.
 * exit 0 = allow this trunk, 1 = try next gateway (capacity exceeded).
 *
 *   php bin/dial-trunk-limit-check.php <trunk_id>
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Dial\CallLimitService;

$tid = isset($argv[1]) ? (int) preg_replace('/\D+/', '', (string) $argv[1]) : 0;
if ($tid <= 0) {
    fwrite(STDOUT, "ALLOW skip\n");
    exit(0);
}

$svc = new CallLimitService(App::db());
$res = $svc->authorizeTrunk($tid);

if ($res['allow']) {
    fwrite(STDOUT, 'ALLOW ' . $res['reason'] . "\n");
    exit(0);
}

fwrite(STDOUT, 'DENY ' . $res['reason'] . "\n");
fwrite(STDERR, 'DENY trunk-limit tid=' . $tid . ' ' . $res['reason'] . "\n");
exit(1);
