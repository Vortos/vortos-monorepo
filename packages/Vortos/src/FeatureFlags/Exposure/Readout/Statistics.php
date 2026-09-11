<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Readout;

/**
 * The frequentist two-proportion test, and nothing else.
 *
 * ## Why this exists after all
 *
 * The exposure pipeline was built on the principle that we never build a stats engine — the
 * analytics backend has one, and reimplementing it is how you end up with two numbers that
 * disagree. That principle held while the backend could see every exposure. It cannot: its
 * stream is consent-gated and sampled, so its significance test runs over a self-selected
 * subset and answers a question nobody asked.
 *
 * So the test moved to where the complete data lives. This is deliberately the *same* test a
 * frequentist A/B tool runs — a pooled two-proportion z-test — so that the numbers here are
 * comparable to the ones a reader already knows how to interpret, rather than novel.
 *
 * ## What it does not do
 *
 * No sequential testing, no Bayesian posteriors, no multiple-comparison correction. Each is a
 * real improvement and each changes how a result must be read; shipping them silently inside
 * a function called "significance" would be worse than not shipping them. The one guard that
 * *is* here is the sample-size check, because without it this returns confident-looking
 * p-values for samples far too small to support them.
 */
final class Statistics
{
    /**
     * Minimum expected successes and failures per arm for the normal approximation to hold.
     *
     * The z-test approximates a binomial with a normal distribution, and that approximation
     * degrades badly in the tail when counts are small — producing p-values that look precise
     * and are simply wrong. The conventional floor is five in each cell; below it the only
     * honest output is "not enough data", which is why {@see self::isReliable()} exists and
     * why every caller is expected to surface it.
     */
    public const MIN_EXPECTED_PER_CELL = 5;

    /** Two-sided critical value for a 95% confidence interval. */
    public const Z_95 = 1.959963984540054;

    /**
     * Two-sided p-value for the difference between two conversion rates.
     *
     * Pooled variance: under the null hypothesis the two arms share one true rate, so the
     * best estimate of it uses both samples. Using separate variances here instead would test
     * a subtly different hypothesis and report slightly optimistic significance.
     *
     * @param int $convertedA conversions in the control arm
     * @param int $totalA     subjects exposed to the control arm
     * @param int $convertedB conversions in the treatment arm
     * @param int $totalB     subjects exposed to the treatment arm
     */
    public static function twoProportionPValue(int $convertedA, int $totalA, int $convertedB, int $totalB): ?float
    {
        if ($totalA < 1 || $totalB < 1) {
            return null;
        }

        $pA     = $convertedA / $totalA;
        $pB     = $convertedB / $totalB;
        $pooled = ($convertedA + $convertedB) / ($totalA + $totalB);

        $standardError = sqrt($pooled * (1 - $pooled) * (1 / $totalA + 1 / $totalB));

        // Zero standard error means both arms converted at exactly 0% or exactly 100%. There
        // is no variance to test against, so no difference can be called significant — which
        // is the correct answer, not a division by zero.
        if ($standardError <= 0.0) {
            return null;
        }

        $z = ($pB - $pA) / $standardError;

        return 2 * (1 - self::normalCdf(abs($z)));
    }

    /**
     * 95% confidence interval for the absolute difference in rates (treatment minus control).
     *
     * Unpooled here, unlike the p-value, and that asymmetry is intentional rather than an
     * oversight: the p-value tests the null hypothesis that the rates are equal, so it pools;
     * the interval estimates the difference without assuming it is zero, so it must not.
     *
     * @return array{0: float, 1: float}|null [lower, upper] as absolute rate differences
     */
    public static function differenceInterval(int $convertedA, int $totalA, int $convertedB, int $totalB): ?array
    {
        if ($totalA < 1 || $totalB < 1) {
            return null;
        }

        $pA = $convertedA / $totalA;
        $pB = $convertedB / $totalB;

        $standardError = sqrt(($pA * (1 - $pA) / $totalA) + ($pB * (1 - $pB) / $totalB));
        $margin        = self::Z_95 * $standardError;

        return [($pB - $pA) - $margin, ($pB - $pA) + $margin];
    }

    /**
     * Whether both arms are large enough for the normal approximation to mean anything.
     *
     * Checks expected counts under the pooled rate, in both cells of both arms. A result that
     * fails this is not "nearly significant" — it is untestable by this method, and must be
     * reported as such rather than as a p-value with a caveat attached.
     */
    public static function isReliable(int $convertedA, int $totalA, int $convertedB, int $totalB): bool
    {
        if ($totalA < 1 || $totalB < 1) {
            return false;
        }

        $pooled = ($convertedA + $convertedB) / ($totalA + $totalB);

        foreach ([$totalA, $totalB] as $n) {
            if ($n * $pooled < self::MIN_EXPECTED_PER_CELL) {
                return false;
            }

            if ($n * (1 - $pooled) < self::MIN_EXPECTED_PER_CELL) {
                return false;
            }
        }

        return true;
    }

    /**
     * Standard normal CDF.
     *
     * PHP ships no `erf()` outside the (unbundled, rarely installed) stats extension, so this
     * uses Abramowitz & Stegun 7.1.26 — absolute error below 1.5e-7, which is several orders
     * of magnitude finer than any decision made from a p-value. Depending on an optional
     * extension for this would mean the readout silently fails on exactly the machines that
     * did not install it.
     */
    public static function normalCdf(float $z): float
    {
        return 0.5 * (1.0 + self::erf($z / M_SQRT2));
    }

    /** Abramowitz & Stegun 7.1.26. */
    private static function erf(float $x): float
    {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x    = abs($x);

        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $y = 1.0 - ((((
            1.061405429 * $t
            - 1.453152027) * $t
            + 1.421413741) * $t
            - 0.284496736) * $t
            + 0.254829592) * $t * exp(-$x * $x);

        return $sign * $y;
    }
}
