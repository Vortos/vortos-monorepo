<?php
declare(strict_types=1);

namespace Vortos\Tests\Tracing;

use PHPUnit\Framework\TestCase;
use Vortos\Tracing\Sampling\RatioSampler;

final class RatioSamplerTest extends TestCase
{
    public function test_zero_ratio_never_samples(): void
    {
        $sampler = new RatioSampler(0.0);
        for ($i = 0; $i < 100; $i++) {
            $this->assertFalse($sampler->shouldSample());
        }
    }

    public function test_full_ratio_always_samples(): void
    {
        $sampler = new RatioSampler(1.0);
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($sampler->shouldSample());
        }
    }

    public function test_ratio_samples_approximately_correct_percentage(): void
    {
        $sampler = new RatioSampler(0.5);
        $samples = 0;
        $total = 10000;
        for ($i = 0; $i < $total; $i++) {
            if ($sampler->shouldSample()) {
                $samples++;
            }
        }
        $rate = $samples / $total;
        $this->assertGreaterThan(0.4, $rate);
        $this->assertLessThan(0.6, $rate);
    }

    public function test_throws_on_invalid_ratio_below_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RatioSampler(-0.1);
    }

    public function test_throws_on_invalid_ratio_above_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RatioSampler(1.1);
    }
}
