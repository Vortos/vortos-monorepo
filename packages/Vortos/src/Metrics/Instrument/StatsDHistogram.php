<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use Vortos\Metrics\Contract\HistogramInterface;

/**
 * StatsD timing metric — maps histogram observe() to StatsD timer format.
 *
 * StatsD aggregates timings server-side — no client-side bucket calculation needed.
 * The StatsD backend (Telegraf, Graphite, Datadog) handles distribution computation.
 */
final class StatsDHistogram implements HistogramInterface
{
    public function __construct(
        private readonly string $name,
        private readonly array $labels,
        private readonly \Closure $send,
        private readonly float $sampleRate,
    ) {}

    public function observe(float $value): void
    {
        ($this->send)(sprintf('%s:%s|ms|@%s%s', $this->name, $value, $this->sampleRate, $this->tagsString()));
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
