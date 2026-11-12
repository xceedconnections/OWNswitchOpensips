<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: rewrite dialled destination using sip_accounts.dst_prefix_map.
 * Always exit 0; stdout = digits only (rewritten or original).
 *
 *   php bin/dial-dst-rewrite.php <sip_username> <destination>
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Support\DstPrefixMap;

$user = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $argv[1]) : '';
$dst = isset($argv[2]) ? preg_replace('/\D+/', '', (string) $argv[2]) : '';

if ($dst === '') {
    fwrite(STDOUT, "");
    exit(0);
}

$map = '';
if ($user !== '') {
    try {
        $st = App::db()->prepare(
            "SELECT dst_prefix_map FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1"
        );
        $st->execute([$user]);
        $map = (string) ($st->fetchColumn() ?: '');
    } catch (\Throwable) {
        $map = '';
    }
}

$out = DstPrefixMap::apply($map, $dst);
fwrite(STDOUT, $out);
exit(0);
