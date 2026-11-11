<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Support;

use Ownswitch\Support\DelayedBye;
use Ownswitch\Tests\TestCase;

final class DelayedByeTest extends TestCase
{
    public function testExtensionMatchesMcdTable(): void
    {
        $this->assertSame(20, DelayedBye::extensionSeconds(5, 25, 0));
        $this->assertSame(15, DelayedBye::extensionSeconds(10, 25, 0));
        $this->assertSame(5, DelayedBye::extensionSeconds(20, 25, 0));
        $this->assertSame(0, DelayedBye::extensionSeconds(25, 25, 0));
        $this->assertSame(0, DelayedBye::extensionSeconds(40, 25, 0));
    }

    public function testExtraDelayedByeAddsOnTop(): void
    {
        $this->assertSame(22, DelayedBye::extensionSeconds(5, 25, 2));
        $this->assertSame(2, DelayedBye::extensionSeconds(40, 25, 2));
        $this->assertSame(3, DelayedBye::extensionSeconds(10, 0, 3));
    }

    public function testCarrierDurationHonoursBuyingFlag(): void
    {
        // Actual 8s, MCD 25 → extended connected = 25 when counting buy.
        $this->assertSame(8, DelayedBye::carrierDuration(8, 25, 0, false));
        $this->assertSame(25, DelayedBye::carrierDuration(8, 25, 0, true));
        $this->assertSame(27, DelayedBye::carrierDuration(8, 25, 2, true));
        $this->assertSame(40, DelayedBye::carrierDuration(40, 25, 0, true));
        $this->assertSame(42, DelayedBye::carrierDuration(40, 25, 2, true));
    }

    public function testEnabledDefaultsOff(): void
    {
        $this->assertFalse(DelayedBye::enabled(0, 0));
        $this->assertTrue(DelayedBye::enabled(25, 0));
        $this->assertTrue(DelayedBye::enabled(0, 5));
    }

    public function testClamp(): void
    {
        $this->assertSame(0, DelayedBye::clampSeconds(-3));
        $this->assertSame(25, DelayedBye::clampSeconds(25));
        $this->assertSame(DelayedBye::MAX_SECONDS, DelayedBye::clampSeconds(99999));
    }
}
