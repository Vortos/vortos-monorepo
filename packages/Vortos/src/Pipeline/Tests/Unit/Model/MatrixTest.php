<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\Matrix;

final class MatrixTest extends TestCase
{
    public function test_valid(): void
    {
        $matrix = new Matrix('environment', [['environment' => 'production']]);

        $this->assertSame('environment', $matrix->axisName);
        $this->assertCount(1, $matrix->values);
        $this->assertFalse($matrix->failFast);
    }

    public function test_to_array(): void
    {
        $matrix = new Matrix('package', [
            ['local_path' => 'a', 'split_repository' => 'b'],
        ], failFast: false);

        $this->assertSame([
            'axis_name' => 'package',
            'values' => [['local_path' => 'a', 'split_repository' => 'b']],
            'fail_fast' => false,
        ], $matrix->toArray());
    }

    public function test_empty_axis_name_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Matrix('', [['a' => 'b']]);
    }

    public function test_empty_values_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Matrix('env', []);
    }
}
