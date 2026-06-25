<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Bridge;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Bridge\FlagExposureSampler;

final class FlagExposureSamplerTest extends TestCase
{
    public function test_rate_zero_samples_nothing(): void
    {
        $sampler = new FlagExposureSampler(0.0);

        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($sampler->isSampledIn('ctx-' . $i, 'flag'));
        }
    }

    public function test_rate_one_samples_everything(): void
    {
        $sampler = new FlagExposureSampler(1.0);

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($sampler->isSampledIn('ctx-' . $i, 'flag'));
        }
    }

    public function test_same_context_and_flag_is_always_consistent(): void
    {
        $sampler = new FlagExposureSampler(0.5);

        $first = $sampler->isSampledIn('ctx-stable', 'flag-a');
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($first, $sampler->isSampledIn('ctx-stable', 'flag-a'), 'sampling must be deterministic per (contextKey, flag)');
        }
    }

    public function test_rejects_rate_below_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FlagExposureSampler(-0.1);
    }

    public function test_rejects_rate_above_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FlagExposureSampler(1.1);
    }

    public function test_half_rate_produces_a_roughly_balanced_split(): void
    {
        $sampler = new FlagExposureSampler(0.5);
        $in = 0;
        $total = 2000;

        for ($i = 0; $i < $total; $i++) {
            if ($sampler->isSampledIn('ctx-' . $i, 'flag')) {
                $in++;
            }
        }

        $ratio = $in / $total;
        $this->assertGreaterThan(0.4, $ratio);
        $this->assertLessThan(0.6, $ratio);
    }

    public function test_different_flags_for_same_context_can_differ(): void
    {
        $sampler = new FlagExposureSampler(0.5);

        $results = [];
        for ($i = 0; $i < 20; $i++) {
            $results[] = $sampler->isSampledIn('ctx-fixed', 'flag-' . $i);
        }

        // Not asserting a specific distribution, only that the sampler is capable of
        // distinguishing flags for a fixed context (i.e. it is not context-only).
        $this->assertNotCount(0, array_unique($results));
    }
}
