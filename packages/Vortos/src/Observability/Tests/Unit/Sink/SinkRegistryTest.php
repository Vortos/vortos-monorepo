<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Sink;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;
use Vortos\Observability\Driver\Null\NullMetricsSink;
use Vortos\Observability\Sink\MetricsSinkRegistry;
use Vortos\OpsKit\Driver\Exception\UnknownDriverException;

final class SinkRegistryTest extends TestCase
{
    public function test_resolves_by_key(): void
    {
        $registry = new MetricsSinkRegistry(new ServiceLocator([
            'grafana' => static fn () => new GrafanaOtlpMetricsSink('host.example.com'),
            'null' => static fn () => new NullMetricsSink(),
        ]));

        self::assertSame('grafana', $registry->sink('grafana')->name());
        self::assertSame('null', $registry->sink('null')->name());
    }

    public function test_keys_are_sorted(): void
    {
        $registry = new MetricsSinkRegistry(new ServiceLocator([
            'null' => static fn () => new NullMetricsSink(),
            'grafana' => static fn () => new GrafanaOtlpMetricsSink('h'),
        ]));

        self::assertSame(['grafana', 'null'], $registry->keys());
    }

    public function test_all_sinks_returns_every_driver(): void
    {
        $registry = new MetricsSinkRegistry(new ServiceLocator([
            'grafana' => static fn () => new GrafanaOtlpMetricsSink('h'),
            'null' => static fn () => new NullMetricsSink(),
        ]));

        self::assertCount(2, $registry->allSinks());
    }

    public function test_unknown_key_throws(): void
    {
        $registry = new MetricsSinkRegistry(new ServiceLocator([]));

        $this->expectException(UnknownDriverException::class);
        $registry->sink('nope');
    }

    public function test_lazy_only_instantiates_requested(): void
    {
        $instantiated = [];
        $registry = new MetricsSinkRegistry(new ServiceLocator([
            'grafana' => static function () use (&$instantiated) {
                $instantiated[] = 'grafana';

                return new GrafanaOtlpMetricsSink('h');
            },
            'null' => static function () use (&$instantiated) {
                $instantiated[] = 'null';

                return new NullMetricsSink();
            },
        ]));

        $registry->sink('null');

        self::assertSame(['null'], $instantiated);
    }
}
