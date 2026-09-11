<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Readout;

/**
 * One arm of an experiment: how many subjects saw this variant, and how many converted.
 */
final readonly class VariantResult
{
    public function __construct(
        public string $variant,
        public int $subjects,
        public int $conversions,
    ) {}

    /** Conversion rate as a proportion, or null when the arm has no subjects to divide by. */
    public function rate(): ?float
    {
        return $this->subjects > 0 ? $this->conversions / $this->subjects : null;
    }
}
