<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Support;

use Ownswitch\Support\FailoverSipCodes;
use Ownswitch\Tests\TestCase;

final class FailoverSipCodesTest extends TestCase
{
    public function test_accepts_ucap_and_tcap_with_sip_codes(): void
    {
        $norm = FailoverSipCodes::normalize('408, 480, TCAP, ucap, 503');
        $this->assertSame('408,480,TCAP,UCAP,503', $norm);
        $this->assertSame(['408', '480', '503'], FailoverSipCodes::numeric($norm));
    }

    public function test_tcap_enables_trunk_cap_failover_ucap_never_user_failover(): void
    {
        $this->assertTrue(FailoverSipCodes::allowsTrunkCap('408,480,486,503,TCAP'));
        $this->assertTrue(FailoverSipCodes::allowsTrunkCap('408,CAP'));
        $this->assertTrue(FailoverSipCodes::allowsTrunkCap('408,480,486,503')); // legacy
        $this->assertFalse(FailoverSipCodes::allowsTrunkCap('408,UCAP'));
        $this->assertFalse(FailoverSipCodes::allowsUserCapFailover('408,UCAP,TCAP'));
    }
}
