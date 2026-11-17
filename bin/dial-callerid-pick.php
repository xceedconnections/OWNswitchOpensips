<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: pick a CallerID-group CLI.
 * Always exit 0. stdout = digits only when a CLI was chosen; empty = keep existing CLI.
 *
 *   php bin/dial-callerid-pick.php <sip_username> <destination>
 *   php bin/dial-callerid-pick.php group <group_id> <destination>
 *
 * `group` is the trunk CallerID group and wins over the SIP user when the trunk has one.
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Dial\CallerIdPickerService;

$picker = new CallerIdPickerService(App::db());

if (($argv[1] ?? '') === 'group') {
    $gid = (int) preg_replace('/\D+/', '', (string) ($argv[2] ?? ''));
    $dst = isset($argv[3]) ? (preg_replace('/\D+/', '', (string) $argv[3]) ?? '') : '';
    $pick = $gid > 0 ? $picker->pickFromGroup($gid, $dst) : null;
} else {
    $user = isset($argv[1]) ? (preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $argv[1]) ?? '') : '';
    $dst = isset($argv[2]) ? (preg_replace('/\D+/', '', (string) $argv[2]) ?? '') : '';
    if ($user === '' || $dst === '') {
        fwrite(STDOUT, '');
        exit(0);
    }
    $pick = $picker->pickForSipUser($user, $dst);
}

if ($pick === null || $pick['cli'] === '') {
    fwrite(STDOUT, '');
    exit(0);
}

fwrite(STDOUT, $pick['cli']);
exit(0);
