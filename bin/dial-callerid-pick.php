<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: pick CallerID-group CLI for this SIP user + dialled destination.
 * Always exit 0. stdout = digits only when a CLI was chosen; empty = keep existing CLI.
 *
 *   php bin/dial-callerid-pick.php <sip_username> <destination>
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Dial\CallerIdPickerService;

$user = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $argv[1]) : '';
$dst = isset($argv[2]) ? preg_replace('/\D+/', '', (string) $argv[2]) : '';

if ($user === '' || $dst === '') {
    fwrite(STDOUT, '');
    exit(0);
}

$pick = (new CallerIdPickerService(App::db()))->pickForSipUser($user, $dst);
if ($pick === null || $pick['cli'] === '') {
    fwrite(STDOUT, '');
    exit(0);
}

fwrite(STDOUT, $pick['cli']);
exit(0);
