<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: SIP-user concurrent + CPS gate.
 * exit 0 = allow, 1 = deny (Congestion).
 *
 *   php bin/dial-sip-limit-check.php <sip_username>
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Dial\CallLimitService;

$user = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $argv[1]) : '';
if ($user === '') {
    fwrite(STDOUT, "ALLOW skip\n");
    exit(0);
}

$svc = new CallLimitService(App::db());
$res = $svc->authorizeSipUser($user);

if ($res['allow']) {
    fwrite(STDOUT, 'ALLOW ' . $res['reason'] . "\n");
    exit(0);
}

fwrite(STDOUT, 'DENY ' . $res['reason'] . "\n");
fwrite(STDERR, 'DENY sip-limit user=' . $user . ' ' . $res['reason'] . "\n");
exit(1);
