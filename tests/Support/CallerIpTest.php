<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Support;

use Ownswitch\Support\CallerIp;
use Ownswitch\Tests\TestCase;

final class CallerIpTest extends TestCase
{
    public function test_loopback_is_not_a_user_address(): void
    {
        $this->assertSame('', CallerIp::usable('127.0.0.1'));
        $this->assertSame('', CallerIp::usable('::1'));
        $this->assertTrue(CallerIp::isLocalProxy('127.0.0.1'));
        $this->assertTrue(CallerIp::isLocalProxy(''));
    }

    public function test_public_and_lan_addresses_are_kept(): void
    {
        $this->assertSame('203.0.113.8', CallerIp::usable('203.0.113.8'));
        $this->assertSame('10.1.2.3', CallerIp::usable(' 10.1.2.3 '));
        $this->assertFalse(CallerIp::isLocalProxy('203.0.113.8'));
        $this->assertSame('', CallerIp::usable('not-an-ip'));
    }
}
