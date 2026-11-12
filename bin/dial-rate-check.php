<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: exit 0 = destination has a customer tariff, 1 = unrated (reject).
 *
 *   php bin/dial-rate-check.php <sip_username> <destination>
 *
 * Aligns live INVITE handling with RouteSimulator: no matching plan/tariff → reject.
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
if ($user !== '') {
    $st = $pdo->prepare(
        "SELECT customer_id FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1"
    );
    $st->execute([$user]);
    $cid = $st->fetchColumn();
    if ($cid !== false && $cid !== null) {
        $customerId = (int) $cid;
    }
}

if ($customerId === null || $customerId <= 0) {
    fwrite(STDOUT, "DENY no_customer user={$user}\n");
    fwrite(STDERR, "DENY no_customer user={$user}\n");
    exit(1);
}

$rate = (new RateResolver($pdo, new SettingsService($pdo)))->customer($customerId, $dst);
if ($rate === null) {
    fwrite(STDOUT, "DENY unrated user={$user} dst={$dst}\n");
    fwrite(STDERR, "DENY unrated user={$user} dst={$dst}\n");
    exit(1);
}

$pfx = (string) ($rate['prefix'] ?? '');
fwrite(STDOUT, "ALLOW tariff={$pfx} rate=" . ($rate['rate'] ?? '') . "\n");
exit(0);
