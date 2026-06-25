<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Target\ActiveColor;

final class ActiveColorTest extends TestCase
{
    public function test_blue_opposite_is_green(): void
    {
        self::assertSame(ActiveColor::Green, ActiveColor::Blue->opposite());
    }

    public function test_green_opposite_is_blue(): void
    {
        self::assertSame(ActiveColor::Blue, ActiveColor::Green->opposite());
    }

    public function test_none_opposite_is_blue(): void
    {
        self::assertSame(ActiveColor::Blue, ActiveColor::None->opposite());
    }
}
