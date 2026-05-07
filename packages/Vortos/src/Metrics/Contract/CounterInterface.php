<?php

declare(strict_types=1);

namespace Vortos\Metrics\Contract;

interface CounterInterface
{
    public function increment(float $by = 1.0): void;
}
