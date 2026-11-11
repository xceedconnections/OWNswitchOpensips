<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Support;

use Ownswitch\Support\CapBlockNote;
use Ownswitch\Tests\TestCase;

final class CapBlockNoteTest extends TestCase
{
    public function test_trunk_line_names_buy_and_cap(): void
    {
        $line = CapBlockNote::trunkLine('Peer A', '0.009', '0.008');
        $this->assertSame('Blocked by trunk CAP (Peer A, buy 0.009 > cap 0.008)', $line);
        $this->assertTrue(CapBlockNote::isTrunk($line));
        $this->assertTrue(CapBlockNote::isCap($line));
    }

    public function test_missing_buy_rate_is_still_a_cap_block(): void
    {
        $line = CapBlockNote::trunkLine('Peer A', '', '0.008');
        $this->assertStringContains('no provider rate', $line);
        $this->assertTrue(CapBlockNote::isTrunk($line));
    }

    public function test_encode_roundtrip_and_summary(): void
    {
        $skips = [
            ['id' => 4, 'name' => 'Peer A', 'buy' => '0.009', 'cap' => '0.008'],
            ['id' => 9, 'name' => 'Peer B', 'buy' => '', 'cap' => '0.005'],
        ];
        $decoded = CapBlockNote::decode(CapBlockNote::encode($skips));
        $this->assertSame($skips, $decoded);
        $summary = CapBlockNote::summary($skips);
        $this->assertTrue(CapBlockNote::isTrunk($summary));
        $this->assertStringContains('Peer A buy 0.009 > cap 0.008', $summary);
        $this->assertStringContains('Peer B no provider rate', $summary);
    }

    public function test_user_cap_is_not_a_vendor_error(): void
    {
        $reason = 'Blocked by user CAP (tariff 0.01 > cap 0.006)';
        $this->assertTrue(CapBlockNote::isUser($reason));
        $this->assertFalse(CapBlockNote::isTrunk($reason));
        $this->assertTrue(CapBlockNote::isCap($reason));
        $this->assertSame('UCAP', CapBlockNote::code($reason));
        $this->assertSame('CAP', CapBlockNote::resultLabel($reason));
    }

    public function test_trunk_cap_code_is_tcap(): void
    {
        $reason = CapBlockNote::trunkLine('Peer A', '0.009', '0.008');
        $this->assertSame('TCAP', CapBlockNote::code($reason));
        $this->assertSame('CAP', CapBlockNote::resultLabel($reason));
    }
}
