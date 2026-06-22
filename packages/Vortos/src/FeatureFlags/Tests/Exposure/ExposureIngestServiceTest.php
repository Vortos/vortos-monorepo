<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Exposure\ExposureEvent;
use Vortos\FeatureFlags\Exposure\ExposureIngestService;
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

    /**
     * @param list<string> $flagNames
     */
    private function service(array $flagNames, RecordingMetrics $sink): ExposureIngestService
    {
        $now   = new \DateTimeImmutable();
        $flags = array_map(
            static fn(string $n) => new FeatureFlag('id-' . $n, $n, '', true, [], null, $now, $now),
            $flagNames,
        );

        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn($flags);

        return new ExposureIngestService($storage, new FlagEvaluationMetrics($sink));
    }
}
