<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runtime\ResourceLimits;

/**
 * @see ResourceLimits
 */
final class ResourceLimitsTest extends TestCase
{
    public function test_default_clears_the_stock_daemon_limit_by_a_wide_margin(): void
    {
        $limits = ResourceLimits::defaults();

        // 1024 is what a container inherits from a stock Docker daemon. The whole purpose of this
        // class is to not be anywhere near it.
        $this->assertGreaterThan(1024, $limits->nofileSoft);
        $this->assertGreaterThan(1024, $limits->nofileHard);
    }

    public function test_soft_and_hard_are_equal_by_default(): void
    {
        $limits = ResourceLimits::defaults();

        // Nothing in the app image raises its own limit at runtime, so a hard limit above the soft
        // one would only advertise headroom no process ever claims.
        $this->assertSame($limits->nofileSoft, $limits->nofileHard);
    }

    public function test_nofile_sets_both_bounds_from_one_value(): void
    {
        $limits = ResourceLimits::nofile(20000);

        $this->assertSame(20000, $limits->nofileSoft);
        $this->assertSame(20000, $limits->nofileHard);
    }

    public function test_nofile_accepts_a_distinct_hard_ceiling(): void
    {
        $limits = ResourceLimits::nofile(20000, 40000);

        $this->assertSame(20000, $limits->nofileSoft);
        $this->assertSame(40000, $limits->nofileHard);
    }

    public function test_to_array_emits_the_compose_ulimits_shape(): void
    {
        $this->assertSame(
            ['nofile' => ['soft' => 20000, 'hard' => 40000]],
            ResourceLimits::nofile(20000, 40000)->toArray(),
        );
    }

    /**
     * A value in the stock-default range is the condition this class exists to prevent, so it is far
     * more likely to be a typo than a decision. Failing loudly beats silently shipping the ceiling.
     */
    public function test_rejects_a_limit_at_the_stock_daemon_default(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least/');

        ResourceLimits::nofile(1024);
    }

    /**
     * The kernel refuses a soft limit above the hard ceiling, so this would not be a bad limit — it
     * would be a container that never starts. Caught at construction rather than at deploy time.
     */
    public function test_rejects_a_soft_limit_above_the_hard_ceiling(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not exceed/');

        new ResourceLimits(nofileSoft: 40000, nofileHard: 20000);
    }
}
