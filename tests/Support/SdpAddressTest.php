<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Support;

use Ownswitch\Support\SdpAddress;
use Ownswitch\Tests\TestCase;

final class SdpAddressTest extends TestCase
{
    public function test_srflx_wins_over_the_placeholder_and_a_lan_host(): void
    {
        $sdp = "v=0\r\n"
            . "c=IN IP4 0.0.0.0\r\n"
            . "a=candidate:1 1 UDP 2122252543 192.168.1.20 54000 typ host\r\n"
            . "a=candidate:2 1 UDP 1686052607 203.0.113.44 54000 typ srflx raddr 192.168.1.20 rport 54000\r\n";
        $this->assertSame('203.0.113.44', SdpAddress::publicIp($sdp));
    }

    public function test_loopback_and_unspecified_are_ignored(): void
    {
        $sdp = "c=IN IP4 127.0.0.1\r\na=candidate:1 1 UDP 1 0.0.0.0 9 typ host\r\n";
        $this->assertSame('', SdpAddress::publicIp($sdp));
    }
}
