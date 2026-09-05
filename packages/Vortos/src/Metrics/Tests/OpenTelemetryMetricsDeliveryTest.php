<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests;

use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Vortos\Metrics\Adapter\OpenTelemetryMetrics;
use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\OpenTelemetry\OpenTelemetryMetricsFactory;

/**
 * Regression coverage for the FrankenPHP worker-mode metrics outage:
 * once the MeterProvider is shut down the ExportingReader latches closed and every
 * subsequent forceFlush() silently exports nothing. The adapter must therefore NOT
 * self-close, so that a long-lived worker keeps delivering on every request.
 */
final class OpenTelemetryMetricsDeliveryTest extends TestCase
{
    public function test_metrics_are_delivered_on_every_flush_across_many_requests(): void
    {
        $this->requireSdk();

        $transport = $this->fakeTransport();
        [$metrics] = $this->buildAdapter($transport, Temporality::CUMULATIVE);

        // Simulate a long-lived worker: many request cycles, each records then flushes.
        $requests = 25;
        for ($i = 0; $i < $requests; $i++) {
            $metrics->counter('app_http_requests_total', ['route' => '/me', 'status' => '401'])->increment();
            $metrics->histogram('app_http_request_duration_ms', ['route' => '/me', 'status' => '401'])->observe(12.0);
            $metrics->flush();
        }

        // The pre-fix bug delivered exactly one flush then went silent forever.
        $this->assertCount($requests, $transport->payloads, 'every request flush must reach the transport');
        foreach ($transport->payloads as $payload) {
            $this->assertNotSame('', $payload, 'cumulative flush payload must never be empty');
        }
    }

    public function test_exported_data_points_use_cumulative_temporality(): void
    {
        $this->requireSdk();

        if (!class_exists(ExportMetricsServiceRequest::class)) {
            $this->markTestSkipped('OTLP protobuf classes are not installed.');
        }

        $transport = $this->fakeTransport();
        [$metrics] = $this->buildAdapter($transport, Temporality::CUMULATIVE);

        $metrics->counter('app_http_requests_total', ['route' => '/me', 'status' => '200'])->increment();
        $metrics->flush();

        $this->assertNotEmpty($transport->payloads);
        $this->assertSame(
            Temporality::CUMULATIVE,
            self::wireTemporality($transport->payloads[0]),
            'Grafana Cloud / Mimir only accept cumulative; the wire must carry cumulative sums',
        );
    }

    public function test_flush_after_provider_close_is_surfaced_not_silent(): void
    {
        $this->requireSdk();

        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $transport = $this->fakeTransport();
        $exporter = new MetricExporter($transport, Temporality::CUMULATIVE);
        $provider = MeterProvider::builder()
            ->setResource(ResourceInfoFactory::emptyResource())
            ->addReader(new ExportingReader($exporter))
            ->build();
        $observed = new OpenTelemetryMetrics($provider, $provider->getMeter('t'), $this->registry(), 'vortos', $logger);

        // Emulate the fatal condition (provider closed) and prove the next flush is loud, not silent.
        $observed->shutdown();
        $before = count($transport->payloads);
        $observed->flush();

        $this->assertSame($before, count($transport->payloads), 'a closed provider must not deliver');
        $errors = array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'error');
        $this->assertNotEmpty($errors, 'a suppressed (black-holed) flush must be logged at error level');
        $this->assertStringContainsString('flush_suppressed', $errors[array_key_first($errors)]['message']);
    }

    public function test_a_zero_increment_still_produces_a_data_point(): void
    {
        $this->requireSdk();

        if (!class_exists(ExportMetricsServiceRequest::class)) {
            $this->markTestSkipped('OTLP protobuf classes are not installed.');
        }

        $transport = $this->fakeTransport();
        [$metrics] = $this->buildAdapter($transport, Temporality::CUMULATIVE);

        // A caller recording "the sweep ran and found nothing". The instrument is created by the
        // counter() call itself, so swallowing the zero here left it in the export with an EMPTY
        // dataPoints list -- and Grafana Cloud / Mimir answers that with
        // `400 otlp parse error: empty data points`, discarding the whole request. On one
        // deployment that cost ~9% of all metrics for months while every health signal stayed green.
        $metrics->counter('app_http_requests_total', ['route' => '/sweep', 'status' => '200'])->increment(0.0);
        $metrics->flush();

        $this->assertNotEmpty($transport->payloads);
        $this->assertSame(
            1,
            self::countDataPoints($transport->payloads[0]),
            'a zero increment must record a real data point, never an empty instrument',
        );
    }

    public function test_no_exported_instrument_is_ever_empty(): void
    {
        $this->requireSdk();

        if (!class_exists(ExportMetricsServiceRequest::class)) {
            $this->markTestSkipped('OTLP protobuf classes are not installed.');
        }

        $transport = $this->fakeTransport();
        [$metrics] = $this->buildAdapter($transport, Temporality::CUMULATIVE);

        // Mixed batch: a healthy metric alongside one whose ONLY activity is a zero. The zero
        // metric is what turns into an empty instrument, and rejection is per REQUEST — so it
        // discards the healthy neighbour with it. That is the whole cost of this bug: the damage
        // is never limited to the metric that caused it.
        $metrics->counter('app_http_requests_total', ['route' => '/a', 'status' => '200'])->increment(3.0);
        $metrics->counter('app_sweep_findings_total', ['outcome' => 'detected'])->increment(0.0);
        $metrics->flush();

        $this->assertNotEmpty($transport->payloads);
        $this->assertSame(
            0,
            self::countEmptyInstruments($transport->payloads[0]),
            'an instrument with no data points invalidates the entire OTLP push',
        );
    }

    public function test_a_negative_increment_is_still_refused(): void
    {
        $this->requireSdk();

        if (!class_exists(ExportMetricsServiceRequest::class)) {
            $this->markTestSkipped('OTLP protobuf classes are not installed.');
        }

        $transport = $this->fakeTransport();
        [$metrics] = $this->buildAdapter($transport, Temporality::CUMULATIVE);

        // Counters are monotonic; OTLP has no representation for a decrease. Allowing zero must
        // not have widened the guard to allow negatives.
        $metrics->counter('app_http_requests_total', ['route' => '/x', 'status' => '200'])->increment(-5.0);
        $metrics->flush();

        $this->assertSame(
            0,
            $transport->payloads === [] ? 0 : self::countDataPoints($transport->payloads[0]),
            'a negative delta must record nothing',
        );
    }

    public function test_factory_maps_temporality_tokens_with_safe_default(): void
    {
        $this->requireSdk();

        $this->assertSame(Temporality::DELTA, OpenTelemetryMetricsFactory::temporalityToken('delta'));
        $this->assertSame(Temporality::CUMULATIVE, OpenTelemetryMetricsFactory::temporalityToken('cumulative'));
        // An unknown value must fall back to cumulative, never disable delivery.
        $this->assertSame(Temporality::CUMULATIVE, OpenTelemetryMetricsFactory::temporalityToken('nonsense'));
    }

    private function requireSdk(): void
    {
        if (!class_exists(MeterProvider::class) || !class_exists(MetricExporter::class)) {
            $this->markTestSkipped('OpenTelemetry SDK / OTLP exporter is not installed.');
        }
    }

    /** @return array{0: OpenTelemetryMetrics, 1: MeterProvider} */
    private function buildAdapter(object $transport, string $temporality): array
    {
        /** @var TransportInterface<string> $transport */
        $exporter = new MetricExporter($transport, $temporality);
        $provider = MeterProvider::builder()
            ->setResource(ResourceInfoFactory::emptyResource())
            ->addReader(new ExportingReader($exporter))
            ->build();

        $metrics = new OpenTelemetryMetrics($provider, $provider->getMeter('test'), $this->registry());

        return [$metrics, $provider];
    }

    private function registry(): MetricDefinitionRegistry
    {
        return new MetricDefinitionRegistry([
            MetricDefinition::counter('app_http_requests_total', 'HTTP requests.', ['route', 'status']),
            // A SEPARATE metric name is required to reach the empty-instrument case at all: an
            // instrument is only exported empty when no label set of that name recorded anything.
            MetricDefinition::counter('app_sweep_findings_total', 'Sweep findings.', ['outcome']),
            MetricDefinition::histogram('app_http_request_duration_ms', 'HTTP duration.', ['route', 'status'], [10, 100]),
        ]);
    }

    private function fakeTransport(): object
    {
        return new class implements TransportInterface {
            /** @var list<string> */
            public array $payloads = [];
            public function contentType(): string
            {
                return 'application/x-protobuf';
            }
            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                $this->payloads[] = $payload;
                return new CompletedFuture(null);
            }
            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
    }

    /** Total data points across every exported sum instrument. */
    private static function countDataPoints(string $payload): int
    {
        $request = new ExportMetricsServiceRequest();
        $request->mergeFromString($payload);

        $count = 0;
        foreach ($request->getResourceMetrics() as $resourceMetrics) {
            foreach ($resourceMetrics->getScopeMetrics() as $scopeMetrics) {
                foreach ($scopeMetrics->getMetrics() as $metric) {
                    $sum = $metric->getSum();
                    if ($sum !== null) {
                        $count += \count(iterator_to_array($sum->getDataPoints()));
                    }
                }
            }
        }

        return $count;
    }

    /** Instruments exported with no data points at all — the shape Mimir rejects. */
    private static function countEmptyInstruments(string $payload): int
    {
        $request = new ExportMetricsServiceRequest();
        $request->mergeFromString($payload);

        $empty = 0;
        foreach ($request->getResourceMetrics() as $resourceMetrics) {
            foreach ($resourceMetrics->getScopeMetrics() as $scopeMetrics) {
                foreach ($scopeMetrics->getMetrics() as $metric) {
                    $sum = $metric->getSum();
                    if ($sum !== null && iterator_to_array($sum->getDataPoints()) === []) {
                        ++$empty;
                    }
                }
            }
        }

        return $empty;
    }

    private static function wireTemporality(string $payload): ?string
    {
        $request = new ExportMetricsServiceRequest();
        $request->mergeFromString($payload);
        foreach ($request->getResourceMetrics() as $resourceMetrics) {
            foreach ($resourceMetrics->getScopeMetrics() as $scopeMetrics) {
                foreach ($scopeMetrics->getMetrics() as $metric) {
                    $sum = $metric->getSum();
                    if ($sum !== null) {
                        // OTLP AggregationTemporality: 1 = DELTA, 2 = CUMULATIVE.
                        return $sum->getAggregationTemporality() === 2
                            ? Temporality::CUMULATIVE
                            : Temporality::DELTA;
                    }
                }
            }
        }

        return null;
    }
}
