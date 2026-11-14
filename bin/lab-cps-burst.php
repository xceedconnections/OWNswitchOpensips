<?php

declare(strict_types=1);

/**
 * CLI CPS / concurrent smoke test (no trunk).
 *
 *   php bin/lab-cps-burst.php <sip_username> [attempts=20]
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Dial\CallLimitService;
use Ownswitch\Services\Dial\LabDialogService;

$user = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $argv[1]) : '';
$n = isset($argv[2]) ? max(1, min(200, (int) $argv[2])) : 20;
if ($user === '') {
    fwrite(STDERR, "Usage: php bin/lab-cps-burst.php <sip_username> [attempts]\n");
    exit(2);
}

$pdo = App::db();
$lab = new LabDialogService($pdo);
$svc = new CallLimitService($pdo);
$snap = $svc->snapshotSipUser($user);
echo "SIP={$user} using={$snap['using']} (live={$snap['using_live']} lab={$snap['using_lab']})"
    . " max_ch=" . ($snap['unlimited_channels'] ? 'inf' : $snap['max_channels'])
    . " max_cps=" . ($snap['unlimited_cps'] ? 'inf' : $snap['max_cps'])
    . " cps_now={$snap['cps']}\n";
echo "lab_total=" . $lab->total() . "\n";

$burst = $svc->burstSipUser($user, $n);
echo "burst attempts={$burst['attempts']} allowed={$burst['allowed']} denied={$burst['denied']}";
if ($burst['first_deny']) {
    echo " first_deny={$burst['first_deny']}";
}
echo "\n";
exit($burst['denied'] > 0 ? 1 : 0);
