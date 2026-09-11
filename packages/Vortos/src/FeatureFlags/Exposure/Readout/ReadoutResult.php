<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Readout;

/**
 * A finished experiment readout: every arm, plus the comparison against the control arm.
 *
 * Carries its own caveats rather than leaving them to the caller. A readout that can be
 * rendered without its reliability warning will eventually be rendered without it.
 */
final readonly class ReadoutResult
{
    /**
     * @param list<VariantResult>              $variants
     * @param array{0:float,1:float}|null      $interval 95% CI on the absolute difference
     * @param list<string>                     $warnings human-readable reasons to distrust this
     */
    public function __construct(
        public string $flag,
        public string $metric,
        public string $from,
        public string $to,
        public array $variants,
        public ?VariantResult $control,
        public ?VariantResult $treatment,
        public ?float $pValue,
        public ?array $interval,
        public bool $reliable,
        public array $warnings = [],
    ) {}

    /**
     * Whether the difference clears the conventional 5% threshold.
     *
     * Returns false when the sample is too small, regardless of the p-value. A small sample
     * can produce a p-value below 0.05 by chance far more often than the number implies, and
     * reporting that as "significant" is the single most common way an experiment platform
     * misleads the person reading it.
     */
    public function isSignificant(): bool
    {
        return $this->reliable && $this->pValue !== null && $this->pValue < 0.05;
    }

    /**
     * Relative change in conversion rate, treatment against control — the "lift" a stakeholder
     * asks for. Null unless both arms have a rate and the control's is non-zero.
     */
    public function relativeLift(): ?float
    {
        $controlRate   = $this->control?->rate();
        $treatmentRate = $this->treatment?->rate();

        if ($controlRate === null || $treatmentRate === null || $controlRate <= 0.0) {
            return null;
        }

        return ($treatmentRate - $controlRate) / $controlRate;
    }
}
