<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests\Unit\Decorator;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Contract\ShutdownMetricsInterface;
use Vortos\Metrics\Decorator\FailSafeMetrics;
use Vortos\Metrics\Decorator\ModuleAwareMetrics;

/**
 * A metrics decorator must carry every capability of what it stands in front of.
 *
 * MetricsInterface is only the recording contract. Consumers of metrics do not all depend on that
 * narrow contract: KafkaConsumerFactory takes `?FlushableMetricsInterface $metricsFlusher` and is
 * wired with a reference to MetricsInterface, resolved through whatever the alias points at. So the
 * alias target must satisfy the WIDER set, not just MetricsInterface.
 *
 * When FailSafeMetrics was introduced and MetricsInterface was aliased to it, it implemented only
 * MetricsInterface. Every Kafka consumer in production then fatalled at construction on the
 * $metricsFlusher type declaration, supervisord respawned all forty of them in a loop, the box sat
 * at ~390% CPU, and the CPU probe failed the app's readiness — a metrics decorator took the site
 * down. Nothing in the type system objected, because the mismatch only exists once the container
 * resolves the alias.
 *
 * This test states the invariant directly: a decorator sitting on the MetricsInterface alias is
 * capability-equivalent to the default implementation it fronts. Adding a capability interface to
 * ModuleAwareMetrics without adding it to FailSafeMetrics fails here rather than in production.
 */
final class MetricsDecoratorCapabilityParityTest extends TestCase
{
    /**
     * Decorators that can occupy the MetricsInterface alias, and the implementation they front.
     *
     * @return array<string, array{class-string, class-string}>
     */
    public static function decorators(): array
    {
        return [
            'fail-safe fronts module-aware' => [FailSafeMetrics::class, ModuleAwareMetrics::class],
        ];
    }

    /**
     * @param class-string $decorator
     * @param class-string $fronted
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('decorators')]
    public function test_a_decorator_implements_every_interface_of_what_it_fronts(string $decorator, string $fronted): void
    {
        $missing = array_diff(
            class_implements($fronted) ?: [],
            class_implements($decorator) ?: [],
        );

        self::assertSame(
            [],
            array_values($missing),
            sprintf(
                "%s is wired in front of %s but does not implement: %s\n"
                . 'Any consumer type-hinting one of those receives the decorator and fatals at construction.',
                $decorator,
                $fronted,
                implode(', ', $missing),
            ),
        );
    }

    /**
     * The capabilities that are known to be type-hinted by consumers, asserted by name.
     *
     * The parity test above tracks ModuleAwareMetrics automatically. This one holds even if that
     * class is replaced, because these are the contracts other packages actually declare arguments
     * against — KafkaConsumerFactory for flush, the CLI shutdown path for shutdown.
     */
    public function test_the_alias_target_satisfies_the_contracts_consumers_type_hint(): void
    {
        foreach ([MetricsInterface::class, FlushableMetricsInterface::class, ShutdownMetricsInterface::class] as $contract) {
            self::assertTrue(
                is_a(FailSafeMetrics::class, $contract, true),
                FailSafeMetrics::class . ' must satisfy ' . $contract . ' — it is the MetricsInterface alias target.',
            );
        }
    }

    /**
     * Degrading must extend to flushing. A telemetry flush that throws must not kill the consumer
     * loop that called it, for the same reason a failed counter increment must not.
     */
    public function test_flush_and_shutdown_degrade_instead_of_throwing(): void
    {
        $exploding = new class implements MetricsInterface, FlushableMetricsInterface, ShutdownMetricsInterface {
            public function counter(string $name, array $labels = []): CounterInterface
            {
                throw new \RuntimeException('backend down');
            }

            public function gauge(string $name, array $labels = []): GaugeInterface
            {
                throw new \RuntimeException('backend down');
            }

            public function histogram(string $name, array $labels = []): HistogramInterface
            {
                throw new \RuntimeException('backend down');
            }

            public function flush(): void
            {
                throw new \RuntimeException('backend down');
            }

            public function shutdown(): void
            {
                throw new \RuntimeException('backend down');
            }
        };

        $metrics = new FailSafeMetrics($exploding, new \Psr\Log\NullLogger(), 'prod');

        $metrics->flush();
        $metrics->shutdown();

        $this->addToAssertionCount(2); // reaching here without an exception is the assertion
    }
}
