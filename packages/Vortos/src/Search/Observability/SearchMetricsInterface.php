<?php

declare(strict_types=1);

namespace Vortos\Search\Observability;

/**
 * Thin metrics seam so the engine emits counters/timings without hard-depending on any metrics
 * backend. The app wires this to vortos-observability; {@see NullSearchMetrics} is the default.
 * The metric NAMES the app must declare live in {@see SearchMetricDefinitions}.
 */
interface SearchMetricsInterface
{
    public function indexUpserted(string $type, int $count = 1): void;

    public function indexDeleted(string $type, int $count = 1): void;

    /** @param float $seconds wall time of the query */
    public function queryObserved(bool $hit, bool $fromCache, float $seconds): void;
}
