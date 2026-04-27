<?php
declare(strict_types=1);

namespace Vortos\Tracing\Sampling;

interface SamplerInterface
{
    public function shouldSample(): bool;
}
