<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

use Vortos\Deploy\Target\ActiveColor;

final readonly class CanaryAnalysisRequest
{
    /** @param list<CanaryMetricSpec> $specs */
    public function __construct(
        public string $env,
        public ActiveColor $staged,
        public ActiveColor $stable,
        public int $weight,
        public array $specs,
        public CanaryWindow $window,
        public string $buildId,
        public \DateTimeImmutable $at,
    ) {
        if ($weight < 0 || $weight > 100) {
            throw new \InvalidArgumentException(sprintf('weight must be 0-100, got %d.', $weight));
        }
        if ($specs === []) {
            throw new \InvalidArgumentException('CanaryAnalysisRequest requires at least one CanaryMetricSpec.');
        }
    }
}
