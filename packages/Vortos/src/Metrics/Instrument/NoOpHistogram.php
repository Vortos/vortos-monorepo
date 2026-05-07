<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use Vortos\Metrics\Contract\HistogramInterface;

final class NoOpHistogram implements HistogramInterface
{
    public function observe(float $value): void {}
}
