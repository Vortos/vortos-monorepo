<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Exposure\ExposureEvent;
use Vortos\FeatureFlags\Exposure\ExposureIngestService;
use Vortos\FeatureFlags\Exposure\ExposureObserverInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Metrics\FlagEvaluationMetrics;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Tests\Support\RecordingMetrics;

final class ExposureIngestServiceTest extends TestCase
{
    public function test_known_flag_exposure_is_forwarded_to_metrics(): void
    {
        $sink    = new RecordingMetrics();
        $service = $this->service(['checkout'], $sink);

        $accepted = $service->ingest([new ExposureEvent('checkout', 'b', 123)], 'ctx-1');

        $this->assertSame(1, $accepted);
        $this->assertCount(1, $sink->counters);
        $this->assertSame(FlagEvaluationMetrics::EXPOSURES, $sink->counters[0]['name']);
        $this->assertSame(['flag' => 'checkout', 'variant' => 'b'], $sink->counters[0]['labels']);
    }

    public function test_unknown_flag_is_dropped_no_series_created(): void
    {
        $sink    = new RecordingMetrics();
        $service = $this->service(['known'], $sink);

        $accepted = $service->ingest([
            new ExposureEvent('attacker-made-up-flag', null, 1),
            new ExposureEvent('another-bogus-one', null, 1),
        ], 'ctx-1');

        $this->assertSame(0, $accepted);
        $this->assertSame([], $sink->counters, 'unknown flags must never create a metric series');
    }

    public function test_duplicate_exposures_in_one_batch_are_deduped(): void
    {
        $sink    = new RecordingMetrics();
        $service = $this->service(['f'], $sink);

        $accepted = $service->ingest([
            new ExposureEvent('f', 'a', 1),
            new ExposureEvent('f', 'a', 2), // same context+flag+variant → dedup
            new ExposureEvent('f', 'b', 3), // different variant → counted
        ], 'ctx-1');

        $this->assertSame(2, $accepted);
        $this->assertCount(2, $sink->counters);
    }

    public function test_same_exposure_different_context_not_deduped(): void
    {
        $sink    = new RecordingMetrics();
        $service = $this->service(['f'], $sink);

        $this->assertSame(1, $service->ingest([new ExposureEvent('f', 'a', 1)], 'ctx-A'));
        $this->assertSame(1, $service->ingest([new ExposureEvent('f', 'a', 1)], 'ctx-B'));
    }

    public function test_observer_is_notified_only_for_accepted_exposures(): void
    {
        $sink = new RecordingMetrics();
        $observer = new class implements ExposureObserverInterface {
            public array $calls = [];

            public function onExposure(string $flag, ?string $variant, string $contextKey): void
            {
                $this->calls[] = [$flag, $variant, $contextKey];
            }
        };

        $service = $this->service(['known'], $sink, [$observer]);

        $service->ingest([
            new ExposureEvent('known', 'a', 1),
            new ExposureEvent('known', 'a', 2), // dedup -> observer must NOT fire again
            new ExposureEvent('unknown-flag', null, 3), // unknown -> observer must NOT fire
        ], 'ctx-1');

        $this->assertSame([['known', 'a', 'ctx-1']], $observer->calls);
    }

    public function test_throwing_observer_never_breaks_ingestion(): void
    {
        $sink = new RecordingMetrics();
        $observer = new class implements ExposureObserverInterface {
            public function onExposure(string $flag, ?string $variant, string $contextKey): void
            {
                throw new \RuntimeException('boom');
            }
        };

        $service = $this->service(['known'], $sink, [$observer]);

        $accepted = $service->ingest([new ExposureEvent('known', 'a', 1)], 'ctx-1');

        $this->assertSame(1, $accepted, 'a throwing observer must not break ingestion or the accepted count');
    }

    public function test_no_observers_is_fully_backward_compatible(): void
    {
        $sink    = new RecordingMetrics();
        $service = $this->service(['known'], $sink);

        $accepted = $service->ingest([new ExposureEvent('known', 'a', 1)], 'ctx-1');

        $this->assertSame(1, $accepted);
    }

    /**
     * @param list<string>                       $flagNames
     * @param iterable<ExposureObserverInterface> $observers
     */
    private function service(array $flagNames, RecordingMetrics $sink, iterable $observers = []): ExposureIngestService
    {
        $now   = new \DateTimeImmutable();
        $flags = array_map(
            static fn(string $n) => new FeatureFlag('id-' . $n, $n, '', true, [], null, $now, $now),
            $flagNames,
        );

        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn($flags);

        return new ExposureIngestService($storage, new FlagEvaluationMetrics($sink), $observers);
    }
}
