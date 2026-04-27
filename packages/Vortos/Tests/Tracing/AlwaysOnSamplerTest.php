<?php
declare(strict_types=1);

namespace Vortos\Tests\Tracing;

use PHPUnit\Framework\TestCase;
use Vortos\Tracing\Sampling\AlwaysOnSampler;

final class AlwaysOnSamplerTest extends TestCase
{
    public function test_always_returns_true(): void
    {
        $sampler = new AlwaysOnSampler();
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($sampler->shouldSample());
        }
    }
}
