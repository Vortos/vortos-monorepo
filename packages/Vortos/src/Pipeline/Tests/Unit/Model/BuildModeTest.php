<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\BuildMode;

final class BuildModeTest extends TestCase
{
    public function test_native_value(): void
    {
        $this->assertSame('native', BuildMode::Native->value);
    }

    public function test_buildx_qemu_value(): void
    {
        $this->assertSame('buildx-qemu', BuildMode::BuildxQemu->value);
    }

    public function test_cases_are_exactly_two(): void
    {
        $this->assertCount(2, BuildMode::cases());
    }

    public function test_from_string(): void
    {
        $this->assertSame(BuildMode::Native, BuildMode::from('native'));
        $this->assertSame(BuildMode::BuildxQemu, BuildMode::from('buildx-qemu'));
    }

    public function test_try_from_invalid_returns_null(): void
    {
        $this->assertNull(BuildMode::tryFrom('invalid'));
    }
}
