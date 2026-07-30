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
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Adapter\OpenTelemetryMetrics;
use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\OpenTelemetry\OpenTelemetryMetricsFactory;
use Vortos\Metrics\OpenTelemetry\ServiceInstanceId;

/**
 * Regression coverage for a silent metrics-correctness bug found in production.
 *
 * Every FrankenPHP worker thread holds its own MeterProvider and therefore its own cumulative counter
 * counted from its own start. Exported under one identical resource identity, those become a single
 * series that keeps jumping backwards, which a Prometheus-compatible backend reads as repeated counter
 * resets and re-adds in full. Observed before the fix:
 *
 *     app_http_requests_total   sum() = 4,467   increase(…[15m]) = 123,592
 *
 * Nothing errored, nothing was dropped, and every rate-based panel and alert was simply wrong. The only
 * thing that separates those runtimes is `service.instance.id`, so these tests assert it is present and
 * distinct — on the wire, not merely in a config array.
 *
 * @see ServiceInstanceId
 * @see OpenTelemetryMetricsFactory::resource()
 */
final class OpenTelemetryResourceIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        ServiceInstanceId::reset();
        unset($_SERVER[ServiceInstanceId::ENV_VAR], $_ENV[ServiceInstanceId::ENV_VAR]);
    }

    protected function tearDown(): void
    {
        ServiceInstanceId::reset();
        unset($_SERVER[ServiceInstanceId::ENV_VAR], $_ENV[ServiceInstanceId::ENV_VAR]);
    }

    /** @return array<string, string> */
    private static function config(string $instanceId = ''): array
    {
        return [
            'service_name' => 'sqoura-backend',
            'service_version' => '1.0.0',
            'deployment_environment' => 'prod',
            'service_instance_id' => $instanceId,
        ];
    }

    private function requireSdk(): void
    {
        if (!class_exists(MeterProvider::class) || !class_exists(MetricExporter::class)) {
            $this->markTestSkipped('OpenTelemetry SDK / OTLP exporter is not installed.');
        }
    }

    public function test_resource_carries_a_service_instance_id(): void
    {
        $this->requireSdk();

        $attributes = OpenTelemetryMetricsFactory::resource(self::config())->getAttributes()->toArray();

        self::assertArrayHasKey('service.instance.id', $attributes);
        self::assertNotSame('', $attributes['service.instance.id']);
    }

    public function test_resource_still_carries_the_identifying_service_attributes(): void
    {
        $this->requireSdk();

        $attributes = OpenTelemetryMetricsFactory::resource(self::config())->getAttributes()->toArray();

        self::assertSame('sqoura-backend', $attributes['service.name']);
        self::assertSame('1.0.0', $attributes['service.version']);
        self::assertSame('prod', $attributes['deployment.environment.name']);
    }

    /**
     * Two runtimes of the same service, version and environment must still be distinguishable in the
     * resource — otherwise their cumulative counters collide into one series.
     *
     * This asserts the resource genuinely *plumbs* the instance id rather than deriving or hardcoding
     * one: same service attributes in, different instance ids out. That per-thread ids actually differ
     * is asserted directly in {@see ServiceInstanceIdTest::test_worker_threads_get_distinct_ids()},
     * which is where it can be checked deterministically — this suite runs under CLI, where the
     * resolver correctly returns a stable PID-derived id.
     */
    public function test_the_resource_distinguishes_two_runtimes_of_the_same_service(): void
    {
        $this->requireSdk();

        $first = OpenTelemetryMetricsFactory::resource(self::config('thread-a'))->getAttributes()->toArray();
        ServiceInstanceId::reset();
        $second = OpenTelemetryMetricsFactory::resource(self::config('thread-b'))->getAttributes()->toArray();

        self::assertSame($first['service.name'], $second['service.name']);
        self::assertNotSame(
            $first['service.instance.id'],
            $second['service.instance.id'],
            'runtimes sharing an instance id is exactly the collision this prevents',
        );
    }

    public function test_configured_instance_id_is_used_verbatim(): void
    {
        $this->requireSdk();

        $attributes = OpenTelemetryMetricsFactory::resource(self::config('replica-3'))->getAttributes()->toArray();

        self::assertSame('replica-3', $attributes['service.instance.id']);
    }

    /**
     * Asserted on the encoded OTLP payload rather than the resource object, because the resource being
     * correct in memory is not the property that matters — the backend only ever sees the wire.
     */
    public function test_service_instance_id_reaches_the_exported_payload(): void
    {
        $this->requireSdk();

        if (!class_exists(ExportMetricsServiceRequest::class)) {
            $this->markTestSkipped('OTLP protobuf classes are not installed.');
        }

        $transport = $this->fakeTransport();
        /** @var TransportInterface<string> $transport */
        $provider = MeterProvider::builder()
            ->setResource(OpenTelemetryMetricsFactory::resource(self::config('wire-check-instance')))
            ->addReader(new ExportingReader(new MetricExporter($transport, Temporality::CUMULATIVE)))
            ->build();

        $metrics = new OpenTelemetryMetrics(
            $provider,
            $provider->getMeter('test'),
            new MetricDefinitionRegistry([
                MetricDefinition::counter('app_http_requests_total', 'HTTP requests.', []),
            ]),
        );

        $metrics->counter('app_http_requests_total')->increment();
        $metrics->flush();

        self::assertNotEmpty($transport->payloads, 'nothing was exported');
        self::assertSame(
            'wire-check-instance',
            self::wireAttribute($transport->payloads[0], 'service.instance.id'),
        );
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

    /** Reads a resource attribute back off an encoded OTLP export request. */
    private static function wireAttribute(string $payload, string $key): ?string
    {
        $request = new ExportMetricsServiceRequest();
        $request->mergeFromString($payload);

        foreach ($request->getResourceMetrics() as $resourceMetrics) {
            $resource = $resourceMetrics->getResource();
            if ($resource === null) {
                continue;
            }

            foreach ($resource->getAttributes() as $attribute) {
                if ($attribute->getKey() === $key) {
                    return $attribute->getValue()?->getStringValue();
                }
            }
        }

        return null;
    }
}
