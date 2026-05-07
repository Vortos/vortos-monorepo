<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * DBAL Middleware that records per-query persistence metrics.
 *
 * Registered alongside TracingDbalMiddleware in DbalPersistenceExtension
 * when MetricsModule::Persistence is enabled.
 *
 * ## Metrics recorded
 *
 *   vortos_db_queries_total{driver, operation}   — counter
 *   vortos_db_query_duration_ms{driver}          — histogram
 *     operation: query | execute | prepare
 *     driver: dbal
 *
 * Stacks with TracingDbalMiddleware — both decorators wrap the same driver.
 */
final class PersistenceMetricsDecorator implements Middleware
{
    public function __construct(private readonly MetricsInterface $metrics) {}

    public function wrap(Driver $driver): Driver
    {
        return new PersistenceMetricsDriver($driver, $this->metrics);
    }
}
