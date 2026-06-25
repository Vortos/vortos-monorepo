<?php

declare(strict_types=1);

namespace Vortos\Observability\Query;

use Vortos\OpsKit\Driver\DriverInterface;

/**
 * Read-side metrics port — the §12.8 canary query seam.
 *
 * Separate from {@see \Vortos\Observability\Sink\MetricsSinkInterface} (write/export)
 * because read and write are different trust/performance profiles: querying is on the
 * hot deploy path with tight timeouts; export is async/best-effort. A valid mix is
 * Datadog-export but Prometheus-query (different backends), which a merged interface
 * would forbid.
 *
 * Implementations must be fail-closed: any error (network, timeout, parse) MUST
 * return an empty result — never a fabricated zero that reads as "healthy".
 */
interface MetricsQueryInterface extends DriverInterface
{
    /** Instant scalar query (single value at 'now'). */
    public function instant(MetricQuery $q): QueryResult;

    /** Range query over the given window, returning a time-series. */
    public function range(MetricQuery $q, QueryWindow $w): QuerySeries;
}
