<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\RunnerSpec;

final class RunnerSpecTest extends TestCase
{
    public function test_default(): void
    {
        $spec = new RunnerSpec();

        $this->assertSame('ubuntu-latest', $spec->label);
        $this->assertNull($spec->archHint);
    }

    public function test_custom_label(): void
    {
        $spec = new RunnerSpec('macos-latest');

        $this->assertSame('macos-latest', $spec->label);
    }

    public function test_arch_hint(): void
    {
        $spec = new RunnerSpec('ubuntu-latest', 'arm64');

        $this->assertSame('arm64', $spec->archHint);
    }

    public function test_to_array(): void
    {
        $spec = new RunnerSpec('ubuntu-latest', 'arm64');

        $this->assertSame(['label' => 'ubuntu-latest', 'arch_hint' => 'arm64'], $spec->toArray());
    }

    public function test_to_array_without_arch(): void
    {
        $spec = new RunnerSpec();

        $this->assertSame(['label' => 'ubuntu-latest'], $spec->toArray());
    }

    public function test_empty_label_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RunnerSpec('');
    }
}
