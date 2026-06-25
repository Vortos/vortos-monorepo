<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\StageKind;

final class StageKindTest extends TestCase
{
    public function test_all_expected_cases_present(): void
    {
        $expected = [
            'test', 'static-analysis', 'agnosticism', 'security',
            'migration-dry-run', 'build', 'iac-plan', 'deploy', 'split',
        ];

        $actual = array_map(static fn (StageKind $k): string => $k->value, StageKind::cases());

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }
}
