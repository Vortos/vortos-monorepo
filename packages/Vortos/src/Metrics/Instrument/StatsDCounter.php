<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use Vortos\Metrics\Contract\CounterInterface;

final class StatsDCounter implements CounterInterface
{
    public function __construct(
        private readonly string $name,
        private readonly array $labels,
        private readonly \Closure $send,
        private readonly float $sampleRate,
    ) {}

    public function increment(float $by = 1.0): void
    {
        ($this->send)(sprintf('%s:%s|c|@%s%s', $this->name, $by, $this->sampleRate, $this->tagsString()));
    }

    private function tagsString(): string
    {
        if (empty($this->labels)) {
            return '';
        }

        $pairs = [];
        foreach ($this->labels as $k => $v) {
            $pairs[] = $k . ':' . $v;
        }

        return '|#' . implode(',', $pairs);
    }
}
