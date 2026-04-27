<?php
declare(strict_types=1);

namespace Vortos\Tracing\Sampling;

final class AlwaysOffSampler implements SamplerInterface
{
    public function shouldSample(): bool
    {
        return false;
    }
}
