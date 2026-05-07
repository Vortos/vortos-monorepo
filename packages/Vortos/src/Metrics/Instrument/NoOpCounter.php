<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use Vortos\Metrics\Contract\CounterInterface;

final class NoOpCounter implements CounterInterface
{
    public function increment(float $by = 1.0): void {}
}
