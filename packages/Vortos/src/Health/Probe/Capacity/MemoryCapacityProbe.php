<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Capacity;

final class MemoryCapacityProbe extends AbstractCapacityProbe
{
    public function name(): string
    {
        return 'memory-capacity';
    }

    protected function read(): ?float
    {
        return $this->reader->memoryUsedPct();
    }
}
