<?php

declare(strict_types=1);

namespace Vortos\Tracing\Sampling;

final class ParentBasedSampler implements SamplerInterface
{
    public function __construct(private readonly SamplerInterface $rootSampler) {}

    public function shouldSample(): bool
    {
        return $this->rootSampler->shouldSample();
    }
}
