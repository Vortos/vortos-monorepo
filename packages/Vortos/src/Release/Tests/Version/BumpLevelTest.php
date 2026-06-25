<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Version\BumpLevel;

final class BumpLevelTest extends TestCase
{
    #[DataProvider('maxPairs')]
    public function test_max(BumpLevel $a, BumpLevel $b, BumpLevel $expected): void
    {
        $this->assertSame($expected, BumpLevel::max($a, $b));
    }

    public static function maxPairs(): iterable
    {
        yield 'major wins' => [BumpLevel::Major, BumpLevel::Minor, BumpLevel::Major];
        yield 'minor wins over patch' => [BumpLevel::Patch, BumpLevel::Minor, BumpLevel::Minor];
        yield 'patch wins over none' => [BumpLevel::None, BumpLevel::Patch, BumpLevel::Patch];
        yield 'same' => [BumpLevel::Minor, BumpLevel::Minor, BumpLevel::Minor];
        yield 'none + none' => [BumpLevel::None, BumpLevel::None, BumpLevel::None];
        yield 'commutative major' => [BumpLevel::Minor, BumpLevel::Major, BumpLevel::Major];
    }
}
