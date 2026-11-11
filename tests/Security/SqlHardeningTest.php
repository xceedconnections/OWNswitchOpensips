<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Security;

use Ownswitch\Support\Sql;
use Ownswitch\Tests\TestCase;

final class SqlHardeningTest extends TestCase
{
    public function testIdentRejectsInjectionPayloads(): void
    {
        $allowed = ['setup_at', 'id', 'disposition'];
        $this->assertSame('setup_at', Sql::ident('setup_at', $allowed, 'id'));
        $this->assertSame('id', Sql::ident('id; DROP TABLE cdrs;--', $allowed, 'id'));
        $this->assertSame('id', Sql::ident('disposition ASC, (SELECT 1)', $allowed, 'id'));
    }

    public function testDirOnlyAllowsAscDesc(): void
    {
        $this->assertSame('ASC', Sql::dir('asc'));
        $this->assertSame('DESC', Sql::dir('DESC; DROP TABLE users'));
        $this->assertSame('DESC', Sql::dir('anything'));
    }

    public function testLikeEscapeNeutralizesWildcards(): void
    {
        $this->assertSame('5\\%5\\_', Sql::likeEscape('5%5_'));
    }
}
