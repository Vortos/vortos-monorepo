<?php

declare(strict_types=1);

namespace Vortos\Metrics\Decorator;

use Psr\Log\LoggerInterface;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Instrument\NoOpCounter;
use Vortos\Metrics\Instrument\NoOpGauge;
use Vortos\Metrics\Instrument\NoOpHistogram;

/**
 * Makes recording a metric incapable of changing a business outcome.
 *
 * WHY THIS EXISTS
 *
 * `MetricDefinitionRegistry::requireType()` and `validateLabels()` throw when a metric was not
 * declared in `config/metrics.php`, or its labels do not match the declaration. That exception
 * propagated out of whatever business code recorded the metric.
 *
 * In a Kafka consumer that is severe. `NotificationDispatcher::deliver()` records a counter BEFORE
 * persisting the notification, so one undeclared metric made the `registration.notify.*` handlers
 * throw, retry four times, and land in the dead-letter queue. Staff notifications were never
 * delivered — no in-app row, no email — and the only trace was a DLQ entry nobody was looking at.
 * A pure observability misconfiguration silently changed what the product did.
 *
 * vortos-metrics already treats telemetry as non-critical everywhere else: both
 * `ConsumerRunner::flushTelemetry()` and `OpenTelemetryMetrics::flush()` swallow throwables with the
 * comment "telemetry delivery must not be able to crash the consumer loop." The RECORD path was the
 * one place without that guard, which made the module inconsistent with its own stated contract.
 *
 * ## Strict mode
 *
 * Silently degrading everywhere would hide the misconfiguration that caused it. So this fails fast
 * outside production — a missing declaration breaks the test suite and local development loudly,
 * where it is cheap to fix — and degrades in production, where a bad metric name must never take
 * down a request or poison a message. That is the same trade the framework's fail-closed deploy
 * gates make in reverse, and for the same reason: put the pain where it is survivable.
 *
 * ## Logging
 *
 * Log-once per metric name. A counter recorded per message would otherwise turn a misconfiguration
 * into a second incident by flooding the log, which is exactly the failure this class exists to
 * prevent.
 */
final class FailSafeMetrics implements MetricsInterface
{
    /** @var array<string, true> names already reported, so a hot path logs once and not per call */
    private array $reported = [];

    /** Environments where a missing declaration is a bug to fail on rather than survive. */
    private const STRICT_ENVIRONMENTS = ['dev', 'test'];

    private readonly bool $strict;

    /**
     * @param string $environment the kernel environment; drives strict mode. Taken as a container
     *        PARAMETER (%kernel.env%), never an inline env read — the container is compiled
     *        wherever the image is built, and an inline read would freeze the build host's value.
     */
    public function __construct(
        private readonly MetricsInterface $inner,
        private readonly ?LoggerInterface $logger = null,
        string $environment = 'prod',
    ) {
        $this->strict = \in_array($environment, self::STRICT_ENVIRONMENTS, true);
    }

    public function counter(string $name, array $labels = []): CounterInterface
    {
        try {
            return $this->inner->counter($name, $labels);
        } catch (\Throwable $e) {
            $this->degrade($name, 'counter', $e);

            return new NoOpCounter();
        }
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        try {
            return $this->inner->gauge($name, $labels);
        } catch (\Throwable $e) {
            $this->degrade($name, 'gauge', $e);

            return new NoOpGauge();
        }
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        try {
            return $this->inner->histogram($name, $labels);
        } catch (\Throwable $e) {
            $this->degrade($name, 'histogram', $e);

            return new NoOpHistogram();
        }
    }

    private function degrade(string $name, string $type, \Throwable $e): void
    {
        if ($this->strict) {
            throw $e; // dev/test: a missing declaration is a bug, and must be loud where it is cheap
        }

        if (isset($this->reported[$name])) {
            return;
        }

        $this->reported[$name] = true;

        $this->logger?->error(
            'Metric recording degraded to a no-op; the metric is not being collected.',
            [
                'metric' => $name,
                'type' => $type,
                'reason' => $e->getMessage(),
                'remediation' => 'Declare the metric in config/metrics.php. Until then this call '
                    . 'records nothing, but it will not affect the operation that made it.',
            ],
        );
    }
}
