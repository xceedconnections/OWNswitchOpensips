<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: exit 0 = allow CLI, 1 = deny (CLI restricted).
 *
 *   php bin/cli-restrict-check.php <sip_username> <cli_digits>
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;

$user = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $argv[1]) : '';
$cli = isset($argv[2]) ? preg_replace('/\D+/', '', (string) $argv[2]) : '';

if ($user === '' || $cli === '' || strlen($cli) < 3) {
    fwrite(STDOUT, "ALLOW skip\n");
    exit(0);
}

$pdo = App::db();
$st = $pdo->prepare('SELECT id FROM sip_accounts WHERE username = ? LIMIT 1');
$st->execute([$user]);
$sipId = (int) ($st->fetchColumn() ?: 0);
if ($sipId <= 0) {
    fwrite(STDOUT, "ALLOW no-sip\n");
    exit(0);
}

$st = $pdo->prepare(
    'SELECT 1
     FROM sip_account_cli_restrictions a
     INNER JOIN cli_restriction_numbers n ON n.cli_restriction_id = a.cli_restriction_id
     INNER JOIN cli_restrictions g ON g.id = a.cli_restriction_id AND g.status = \'active\'
     WHERE a.sip_account_id = ?
       AND n.cli_digits = ?
     LIMIT 1'
);
$st->execute([$sipId, $cli]);
if ($st->fetchColumn()) {
    fwrite(STDOUT, "DENY CLI restricted\n");
    fwrite(STDERR, "DENY CLI restricted user={$user} cli={$cli}\n");
    exit(1);
}

fwrite(STDOUT, "ALLOW ok\n");
exit(0);
