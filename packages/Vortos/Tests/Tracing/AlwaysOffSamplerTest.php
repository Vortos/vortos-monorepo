<?php
declare(strict_types=1);

namespace Vortos\Tests\Tracing;

use PHPUnit\Framework\TestCase;
use Vortos\Tracing\Sampling\AlwaysOffSampler;

final class AlwaysOffSamplerTest extends TestCase
{
    public function test_always_returns_false(): void
    {
        $sampler = new AlwaysOffSampler();
        for ($i = 0; $i < 100; $i++) {
            $this->assertFalse($sampler->shouldSample());
        }
    }
}
