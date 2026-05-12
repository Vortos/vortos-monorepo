<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Query;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Cqrs\Exception\QueryHandlerNotFoundException;
use Vortos\Domain\Query\QueryInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Observability\Telemetry\TelemetryLabels;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Default synchronous query bus implementation.
 *
 * Routes a query to its registered handler and returns the result.
 * No transaction. No event dispatch. No idempotency.
 * Query handlers are pure read operations.
 *
 * ## Handler discovery
 *
 * Handlers are discovered at compile time by QueryHandlerPass.
 * Stored in a ServiceLocator keyed by query class name.
 * The bus looks up the handler by the query's fully qualified class name.
 *
 * ## Caching (future)
 *
 * Query results can be cached by wrapping this bus with a caching decorator.
 * The decorator checks a cache store before calling ask() on the inner bus.
 * This is not implemented yet — add to backlog when needed.
 */
final class QueryBus implements QueryBusInterface
{
    public function __construct(
        private ServiceLocator $handlerLocator,
        private ?LoggerInterface $logger = null,
        private ?FrameworkTelemetry $telemetry = null,
        private ?TracingInterface $tracer = null,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function ask(QueryInterface $query): mixed
    {
        $queryClass = get_class($query);
        $queryName = TelemetryLabels::classShortName($queryClass);
        $start = hrtime(true);

        if (!$this->handlerLocator->has($queryClass)) {
            throw new QueryHandlerNotFoundException($queryClass);
        }

        $span = $this->tracer?->startSpan('cqrs.query.' . $queryName, [
            'vortos.module' => ObservabilityModule::Cqrs,
            'vortos.query.name' => $queryName,
        ]);

        $handler = $this->handlerLocator->get($queryClass);

        $labels = FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Query, $queryName));
        $this->telemetry?->increment(ObservabilityModule::Cqrs, FrameworkMetric::CqrsQueriesTotal, $labels);

        try {
            $result = $handler($query);
            $span?->setStatus('ok');
            $this->logger?->debug('cqrs.query.handled', ['query' => $queryName]);

            return $result;
        } catch (\Throwable $e) {
            $this->telemetry?->increment(ObservabilityModule::Cqrs, FrameworkMetric::CqrsQueryFailuresTotal, $labels);
            $span?->recordException($e);
            $span?->setStatus('error');
            $this->logger?->error('cqrs.query.failed', [
                'query' => $queryName,
                'exception' => TelemetryLabels::exceptionType($e),
            ]);
            throw $e;
        } finally {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->telemetry?->observe(ObservabilityModule::Cqrs, FrameworkMetric::CqrsQueryDurationMs, $labels, $durationMs);
            $span?->end();
        }
    }
}
