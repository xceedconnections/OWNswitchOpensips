<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Support;

use Ownswitch\Support\NumberSearch;
use Ownswitch\Tests\TestCase;

final class NumberSearchTest extends TestCase
{
    public function testTokensSplitMultipleNumbers(): void
    {
        $this->assertSame(
            ['12138007447', '2138007447', '02138007447'],
            NumberSearch::tokens("12138007447, +2138007447\n02138007447")
        );
    }

    public function testVariantsCoverTrunkAndCountryForms(): void
    {
        $v = NumberSearch::variants('02138007447');
        $this->assertContains('02138007447', $v);
        $this->assertContains('2138007447', $v);
        $this->assertContains('12138007447', $v);
    }

    public function testMatchClauseBuildsOrForFlexibleLookup(): void
    {
        [$sql, $params] = NumberSearch::matchClause(['c.dst_normalized'], '1213800, 2138007447');
        $this->assertNotNull($sql);
        $this->assertStringContains('OR', (string) $sql);
        $this->assertContains('1213800%', $params);
        $this->assertTrue(
            (bool) array_filter($params, static fn ($p) => is_string($p) && str_contains($p, '2138007447')),
            'national form appears in LIKE params'
        );
    }
}
