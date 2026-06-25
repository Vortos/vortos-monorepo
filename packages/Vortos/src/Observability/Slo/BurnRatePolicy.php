<?php

declare(strict_types=1);

namespace Vortos\Observability\Slo;

use InvalidArgumentException;

/**
 * Multi-burn-rate alerting policy (Google-SRE style, Block 16 §3.4): a fast window
 * (short lookback, high threshold) catches fast burns quickly; a slow window (long
 * lookback, lower threshold) catches slow burns without firing on noise. Both must
 * fire together for a page-worthy alert — the combination is what avoids alert
 * fatigue downstream in Block 17.
 */
final readonly class BurnRatePolicy
{
    public function __construct(
        public SloWindow $fastWindow,
        public float $fastThreshold,
        public SloWindow $slowWindow,
        public float $slowThreshold,
    ) {
        if ($fastWindow->seconds >= $slowWindow->seconds) {
            throw new InvalidArgumentException('BurnRatePolicy fastWindow must be shorter than slowWindow.');
        }
        if ($fastThreshold <= 0.0 || $slowThreshold <= 0.0) {
            throw new InvalidArgumentException('BurnRatePolicy thresholds must be > 0.');
        }
    }

    /** The default Google-SRE multi-burn-rate policy: fast 1h/5m-equivalent, slow 6h/30m-equivalent. */
    public static function googleSreDefault(): self
    {
        return new self(
            fastWindow: SloWindow::hours(1),
            fastThreshold: 14.4,
            slowWindow: SloWindow::hours(6),
            slowThreshold: 6.0,
        );
    }

    public function isPageWorthy(float $fastBurnRate, float $slowBurnRate): bool
    {
        return $fastBurnRate >= $this->fastThreshold && $slowBurnRate >= $this->slowThreshold;
    }
}
