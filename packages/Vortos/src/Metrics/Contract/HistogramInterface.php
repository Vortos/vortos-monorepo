<?php

declare(strict_types=1);

namespace Vortos\Metrics\Contract;

interface HistogramInterface
{
    public function observe(float $value): void;
}
