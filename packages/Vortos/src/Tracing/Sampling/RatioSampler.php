<?php
declare(strict_types=1);

namespace Vortos\Tracing\Sampling;

final class RatioSampler implements SamplerInterface
{
    public function __construct(
        private readonly float $ratio
    ) {
        if ($ratio < 0.0 || $ratio > 1.0) {
            throw new \InvalidArgumentException('Ratio must be between 0.0 and 1.0');
        }
    }

    public function shouldSample(): bool
    {
        return (mt_rand() / mt_getrandmax()) <= $this->ratio;
    }
}
