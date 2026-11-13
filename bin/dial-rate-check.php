<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: exit 0 = destination has a customer tariff, 1 = unrated (reject).
 *
 *   php bin/dial-rate-check.php <sip_username> <destination>
 *
 * On ALLOW, stdout includes:
 *   ALLOW rplan=<trunk_group_id> route_dst=<digits_for_routing>
 * so OpenSIPS can do_routing(rplan) and optionally rewrite RURI after plan-prefix strip.
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Settings\SettingsService;

$user = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $argv[1]) : '';
$dst = isset($argv[2]) ? preg_replace('/\D+/', '', (string) $argv[2]) : '';

if ($dst === '' || strlen($dst) < 3) {
    fwrite(STDOUT, "DENY empty_destination\n");
    fwrite(STDERR, "DENY empty_destination\n");
    exit(1);
}

$pdo = App::db();
$customerId = null;
$sipRoutingPlanId = null;
if ($user !== '') {
    $st = $pdo->prepare(
        "SELECT customer_id, routing_plan_id FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1"
    );
    $st->execute([$user]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $customerId = (int) ($row['customer_id'] ?? 0);
        if (!empty($row['routing_plan_id'])) {
            $sipRoutingPlanId = (int) $row['routing_plan_id'];
        }
    }
}

if ($customerId === null || $customerId <= 0) {
    fwrite(STDOUT, "DENY no_customer user={$user}\n");
    fwrite(STDERR, "DENY no_customer user={$user}\n");
    exit(1);
}

$resolver = new RateResolver($pdo, new SettingsService($pdo));
$rate = $resolver->customer($customerId, $dst);
if ($rate === null) {
    fwrite(STDOUT, "DENY unrated user={$user} dst={$dst}\n");
    fwrite(STDERR, "DENY unrated user={$user} dst={$dst}\n");
    exit(1);
}

// SIP-user trunk group overrides tariff (same as RouteSimulator)
$rplan = 0;
if ($sipRoutingPlanId !== null && $sipRoutingPlanId > 0) {
    $rplan = $sipRoutingPlanId;
} elseif (!empty($rate['routing_plan_id'])) {
    $rplan = (int) $rate['routing_plan_id'];
}

$routeDst = $resolver->destinationAfterPlanPrefix($customerId, $dst);
if ($routeDst === '' || strlen($routeDst) < 3) {
    $routeDst = $dst;
}

$pfx = (string) ($rate['prefix'] ?? '');
fwrite(STDOUT, "ALLOW tariff={$pfx} rplan={$rplan} route_dst={$routeDst} rate=" . ($rate['rate'] ?? '') . "\n");
exit(0);
