<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Capacity;

final class CpuLoadProbe extends AbstractCapacityProbe
{
    public function name(): string
    {
        return 'cpu-load';
    }

    protected function read(): ?float
    {
        return $this->reader->cpuLoadPct();
    }
}
