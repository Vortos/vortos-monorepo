<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Capacity\CapacityReader;

final class InMemoryCapacityReader implements CapacityReaderInterface
{
    public function __construct(
        private ?float $diskUsedPct = 0.0,
        private ?float $memoryUsedPct = 0.0,
        private ?float $cpuLoadPct = 0.0,
    ) {}

    public function withDiskUsedPct(?float $value): self
    {
        $clone = clone $this;
        $clone->diskUsedPct = $value;

        return $clone;
    }

    public function withMemoryUsedPct(?float $value): self
    {
        $clone = clone $this;
        $clone->memoryUsedPct = $value;

        return $clone;
    }

    public function withCpuLoadPct(?float $value): self
    {
        $clone = clone $this;
        $clone->cpuLoadPct = $value;

        return $clone;
    }

    public function diskUsedPct(string $path): ?float
    {
        return $this->diskUsedPct;
    }

    public function memoryUsedPct(): ?float
    {
        return $this->memoryUsedPct;
    }

    public function cpuLoadPct(): ?float
    {
        return $this->cpuLoadPct;
    }
}
