<?php

declare(strict_types=1);

namespace Vortos\Metrics\Contract;

interface GaugeInterface
{
    public function set(float $value): void;
    public function increment(float $by = 1.0): void;
    public function decrement(float $by = 1.0): void;
}
