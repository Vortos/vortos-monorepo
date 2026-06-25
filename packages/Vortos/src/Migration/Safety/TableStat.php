<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

final readonly class TableStat
{
    public function __construct(
        public int $estimatedRows,
        public int $totalBytes,
        public bool $hasData,
    ) {}

    public function isHot(int $rowThreshold, int $bytesThreshold): bool
    {
        return $this->estimatedRows >= $rowThreshold || $this->totalBytes >= $bytesThreshold;
    }
}
