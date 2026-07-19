<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Capacity;

use InvalidArgumentException;
use Vortos\Health\Probe\Capacity\CapacityReader\CapacityReaderInterface;
use Vortos\Health\Probe\ProbeKind;

final class DiskCapacityProbe extends AbstractCapacityProbe
{
    public function __construct(
        CapacityReaderInterface $reader,
        private readonly string $path = '/',
        float $warnPct = 85.0,
        float $criticalPct = 95.0,
        ProbeKind $kind = ProbeKind::Readiness,
    ) {
        if ($path === '') {
            throw new InvalidArgumentException('DiskCapacityProbe path must not be empty.');
        }

        parent::__construct($reader, $warnPct, $criticalPct, $kind);
    }

    public function name(): string
    {
        return 'disk-capacity';
    }

    protected function read(): ?float
    {
        return $this->reader->diskUsedPct($this->path);
    }
}
