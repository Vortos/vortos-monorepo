<?php

declare(strict_types=1);

namespace Vortos\Observability\Query;

final readonly class QuerySeries
{
    /** @param list<QuerySample> $samples */
    public function __construct(
        public array $samples,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->samples === [];
    }

    public function sampleCount(): int
    {
        return count($this->samples);
    }

    public function mean(): float
    {
        if ($this->isEmpty()) {
            throw new \RuntimeException('Cannot compute mean of empty QuerySeries.');
        }

        $sum = array_sum(array_map(static fn (QuerySample $s): float => $s->value, $this->samples));

        return $sum / $this->sampleCount();
    }

    public function quantile(float $q): float
    {
        if ($this->isEmpty()) {
            throw new \RuntimeException('Cannot compute quantile of empty QuerySeries.');
        }

        if ($q < 0.0 || $q > 1.0) {
            throw new \InvalidArgumentException(sprintf('Quantile must be in [0,1], got %s.', $q));
        }

        $values = array_map(static fn (QuerySample $s): float => $s->value, $this->samples);
        sort($values);
        $count = count($values);

        $index = (int) ceil($q * $count) - 1;
        $index = max(0, min($index, $count - 1));

        return $values[$index];
    }
}
