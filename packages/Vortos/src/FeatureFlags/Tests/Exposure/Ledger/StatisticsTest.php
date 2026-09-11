<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure\Ledger;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Exposure\Readout\Statistics;

/**
 * The test that replaced the analytics backend's significance engine.
 *
 * Pinned against values a statistics table gives, because the failure mode of a hand-rolled
 * test is not a crash — it is a plausible p-value that is simply wrong, believed, and shipped.
 */
final class StatisticsTest extends TestCase
{
    public function test_the_normal_cdf_matches_known_values(): void
    {
        self::assertEqualsWithDelta(0.5,     Statistics::normalCdf(0.0),  1e-6);
        self::assertEqualsWithDelta(0.84134, Statistics::normalCdf(1.0),  1e-4);
        self::assertEqualsWithDelta(0.97725, Statistics::normalCdf(2.0),  1e-4);
        self::assertEqualsWithDelta(0.99865, Statistics::normalCdf(3.0),  1e-4);
        self::assertEqualsWithDelta(0.15866, Statistics::normalCdf(-1.0), 1e-4);
    }

    public function test_a_large_clear_difference_is_significant(): void
    {
        // 100/1000 against 150/1000. Pooled rate 0.125, standard error 0.0147902,
        // z = 3.380617, and a two-sided p of 0.00072323 — computed independently rather than
        // read back from this implementation, which would only pin the bug in place.
        $p = Statistics::twoProportionPValue(100, 1000, 150, 1000);

        self::assertNotNull($p);
        self::assertEqualsWithDelta(0.00072323, $p, 1e-6);
        self::assertLessThan(0.05, $p);
    }

    public function test_identical_rates_are_not_significant(): void
    {
        $p = Statistics::twoProportionPValue(100, 1000, 100, 1000);

        self::assertNotNull($p);
        // Delta rather than identity: the erf approximation carries up to 1.5e-7 of absolute
        // error, so an exact 1.0 is not something this method promises — and tightening this
        // assertion would be pinning the approximation, not the statistics.
        self::assertEqualsWithDelta(1.0, $p, 1e-6);
    }

    public function test_a_tiny_sample_is_reported_as_unreliable(): void
    {
        // The case that matters most at small scale: this computes a p-value, and the p-value
        // is meaningless. Reliability is a separate question from significance, and a caller
        // that only consults the p-value would ship a decision on four subjects.
        self::assertFalse(Statistics::isReliable(1, 4, 3, 4));
    }

    public function test_a_large_sample_is_reported_as_reliable(): void
    {
        self::assertTrue(Statistics::isReliable(100, 1000, 150, 1000));
    }

    public function test_an_arm_at_the_expected_count_boundary_is_reliable(): void
    {
        // Pooled rate 10/100 = 0.1; expected successes 5 per arm, expected failures 45.
        // Exactly on the conventional floor, and therefore acceptable.
        self::assertTrue(Statistics::isReliable(5, 50, 5, 50));
    }

    public function test_an_empty_arm_yields_no_p_value(): void
    {
        self::assertNull(Statistics::twoProportionPValue(0, 0, 5, 10));
        self::assertFalse(Statistics::isReliable(0, 0, 5, 10));
    }

    public function test_zero_variance_yields_no_p_value(): void
    {
        // Both arms at 0%. There is nothing to test against, and returning a number here
        // would mean dividing by zero and calling the result significance.
        self::assertNull(Statistics::twoProportionPValue(0, 100, 0, 100));
    }

    public function test_the_confidence_interval_brackets_the_observed_difference(): void
    {
        $interval = Statistics::differenceInterval(100, 1000, 150, 1000);

        self::assertNotNull($interval);

        $observed = 0.15 - 0.10;
        self::assertLessThan($observed, $interval[0]);
        self::assertGreaterThan($observed, $interval[1]);
    }

    public function test_a_significant_interval_excludes_zero(): void
    {
        // The interval and the p-value must agree. If they ever disagreed, one of them would
        // be reported alongside the other and quietly contradict it.
        $interval = Statistics::differenceInterval(100, 1000, 150, 1000);

        self::assertNotNull($interval);
        self::assertGreaterThan(0.0, $interval[0]);
    }

    public function test_a_non_significant_interval_includes_zero(): void
    {
        $interval = Statistics::differenceInterval(100, 1000, 104, 1000);

        self::assertNotNull($interval);
        self::assertLessThan(0.0, $interval[0]);
        self::assertGreaterThan(0.0, $interval[1]);
    }

    public function test_the_test_is_symmetric_in_its_arms(): void
    {
        // Swapping control and treatment flips the sign of the difference but must not change
        // how surprising it is.
        self::assertEqualsWithDelta(
            (float) Statistics::twoProportionPValue(100, 1000, 150, 1000),
            (float) Statistics::twoProportionPValue(150, 1000, 100, 1000),
            1e-12,
        );
    }
}
