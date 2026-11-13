<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: DNC lookup (Redis → OpenSIPS dnc_cache → portal numbers).
 * exit 0 = allow, 1 = blocked.
 *
 *   php bin/dial-dnc-check.php <group_id> <destination_digits>
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Dnc\DncService;

$gid = isset($argv[1]) ? (int) preg_replace('/\D+/', '', (string) $argv[1]) : 0;
$dst = isset($argv[2]) ? preg_replace('/\D+/', '', (string) $argv[2]) : '';

if ($gid <= 0 || $dst === '' || strlen($dst) < 3) {
    fwrite(STDOUT, "ALLOW skip\n");
    exit(0);
}

$blocked = (new DncService(App::db()))->isBlocked($gid, $dst);

if ($blocked) {
    fwrite(STDOUT, "DENY dnc group={$gid} dst={$dst}\n");
    fwrite(STDERR, "DENY dnc group={$gid} dst={$dst}\n");
    exit(1);
}

fwrite(STDOUT, "ALLOW\n");
exit(0);
