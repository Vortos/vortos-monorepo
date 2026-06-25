<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Bridge\AnalyticsExposureObserver;
use Vortos\Analytics\Bridge\FlagExposureSampler;
use Vortos\Analytics\Capability\AnalyticsCapability;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\FeatureFlags\Exposure\ExposureEvent;
use Vortos\FeatureFlags\Exposure\ExposureIngestService;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Metrics\FlagEvaluationMetrics;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Proves the bridge reuses FF's *existing* exposure pipeline (unknown-flag + dedupe
 * guards) rather than opening a parallel path: the observer only ever fires for
 * exposures the real {@see ExposureIngestService} already accepted.
 */
final class FlagExposureBridgeIntegrationTest extends TestCase
{
    public function test_only_accepted_exposures_reach_the_analytics_bridge(): void
    {
        $analytics = $this->spyAnalytics();
        $observer = new AnalyticsExposureObserver($analytics, new FlagExposureSampler(1.0), enabled: true);

        $service = $this->ingestServiceWithObserver(['checkout'], $observer);

        $service->ingest([
            new ExposureEvent('checkout', 'b', 1),
            new ExposureEvent('checkout', 'b', 2), // dedup -> bridge must not fire again
            new ExposureEvent('attacker-made-up-flag', null, 3), // unknown -> bridge must not fire
        ], 'ctx-1');

        $this->assertCount(1, $analytics->captured);
        $this->assertSame('checkout', $analytics->captured[0]->properties['flag']);
    }

    public function test_bridge_off_by_default_means_no_capture_even_with_exposures(): void
    {
        $analytics = $this->spyAnalytics();
        $observer = new AnalyticsExposureObserver($analytics, new FlagExposureSampler(1.0), enabled: false);

        $service = $this->ingestServiceWithObserver(['checkout'], $observer);
        $service->ingest([new ExposureEvent('checkout', 'b', 1)], 'ctx-1');

        $this->assertSame([], $analytics->captured);
    }

    public function test_ingestion_without_observers_is_unaffected(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $now = new \DateTimeImmutable();
        $storage->method('findAll')->willReturn([new FeatureFlag('id-checkout', 'checkout', '', true, [], null, $now, $now)]);

        $service = new ExposureIngestService($storage, new FlagEvaluationMetrics());
        $accepted = $service->ingest([new ExposureEvent('checkout', 'b', 1)], 'ctx-1');

        $this->assertSame(1, $accepted);
    }

    /** @param list<string> $flagNames */
    private function ingestServiceWithObserver(array $flagNames, AnalyticsExposureObserver $observer): ExposureIngestService
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $now = new \DateTimeImmutable();
        $flags = array_map(
            static fn (string $n) => new FeatureFlag('id-' . $n, $n, '', true, [], null, $now, $now),
            $flagNames,
        );
        $storage->method('findAll')->willReturn($flags);

        return new ExposureIngestService($storage, new FlagEvaluationMetrics(), [$observer]);
    }

    private function spyAnalytics(): object
    {
        return new class implements AnalyticsInterface {
            /** @var list<AnalyticsEvent> */
            public array $captured = [];

            public function name(): string { return 'spy'; }
            public function capture(AnalyticsEvent $event): void { $this->captured[] = $event; }
            public function identify(IdentitySet $identity): void {}
            public function group(GroupAssociation $group): void {}
            public function flush(): void {}
            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => false]);
            }
        };
    }
}
