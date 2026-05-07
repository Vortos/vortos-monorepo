<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use Vortos\Metrics\Contract\GaugeInterface;

final class StatsDGauge implements GaugeInterface
{
    public function __construct(
        private readonly string $name,
        private readonly array $labels,
        private readonly \Closure $send,
    ) {}

    public function set(float $value): void
    {
        ($this->send)(sprintf('%s:%s|g%s', $this->name, $value, $this->tagsString()));
    }

    public function increment(float $by = 1.0): void
    {
        ($this->send)(sprintf('%s:+%s|g%s', $this->name, $by, $this->tagsString()));
    }

    public function decrement(float $by = 1.0): void
    {
        ($this->send)(sprintf('%s:-%s|g%s', $this->name, $by, $this->tagsString()));
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
