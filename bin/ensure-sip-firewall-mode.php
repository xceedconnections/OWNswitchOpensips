<?php

declare(strict_types=1);

/**
 * Cron/CLI: lock SIP:5060 to allow-listed peers when only IP-auth users exist;
 * open world SIP when any password/digest SIP user is active.
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\Services\System\SipFirewallPolicyService;

$res = (new SipFirewallPolicyService())->ensure();
fwrite(STDOUT, ($res['ok'] ? 'OK' : 'ERR') . ' mode=' . $res['mode'] . ' ' . $res['detail'] . "\n");
exit($res['ok'] ? 0 : 1);
