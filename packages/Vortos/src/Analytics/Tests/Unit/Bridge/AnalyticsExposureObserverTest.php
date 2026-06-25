<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Bridge;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Bridge\AnalyticsExposureObserver;
use Vortos\Analytics\Bridge\FlagExposureSampler;
use Vortos\Analytics\Capability\AnalyticsCapability;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class AnalyticsExposureObserverTest extends TestCase
{
    public function test_disabled_by_default_never_captures(): void
    {
        $analytics = $this->spyAnalytics();
        $observer = new AnalyticsExposureObserver($analytics, new FlagExposureSampler(1.0), enabled: false);

        $observer->onExposure('checkout', 'b', 'ctx-1');

        $this->assertSame([], $analytics->captured);
    }

    public function test_enabled_and_sampled_in_captures_agnostic_event(): void
    {
        $analytics = $this->spyAnalytics();
        $observer = new AnalyticsExposureObserver($analytics, new FlagExposureSampler(1.0), enabled: true);

        $observer->onExposure('checkout', 'b', 'ctx-1');

        $this->assertCount(1, $analytics->captured);
        $event = $analytics->captured[0];
        $this->assertSame(AnalyticsExposureObserver::EVENT_NAME, $event->name);
        $this->assertSame('checkout', $event->properties['flag']);
        $this->assertSame('b', $event->properties['variant']);
        $this->assertSame('ctx-1', $event->distinctId->value);
    }

    public function test_enabled_but_sampled_out_never_captures(): void
    {
        $analytics = $this->spyAnalytics();
        $observer = new AnalyticsExposureObserver($analytics, new FlagExposureSampler(0.0), enabled: true);

        $observer->onExposure('checkout', 'b', 'ctx-1');

        $this->assertSame([], $analytics->captured);
    }

    public function test_null_variant_becomes_empty_string_property(): void
    {
        $analytics = $this->spyAnalytics();
        $observer = new AnalyticsExposureObserver($analytics, new FlagExposureSampler(1.0), enabled: true);

        $observer->onExposure('kill-switch', null, 'ctx-1');

        $this->assertSame('', $analytics->captured[0]->properties['variant']);
    }

    public function test_throwing_analytics_never_propagates(): void
    {
        $analytics = new class implements AnalyticsInterface {
            public function name(): string { return 'throwing'; }
            public function capture(AnalyticsEvent $event): void { throw new RuntimeException('boom'); }
            public function identify(IdentitySet $identity): void {}
            public function group(GroupAssociation $group): void {}
            public function flush(): void {}
            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => false]);
            }
        };

        $observer = new AnalyticsExposureObserver($analytics, new FlagExposureSampler(1.0), enabled: true);
        $observer->onExposure('flag', 'a', 'ctx-1');

        $this->addToAssertionCount(1);
    }

    public function test_empty_context_key_never_propagates(): void
    {
        // DistinctId rejects an empty value; the observer must swallow that, not throw.
        $analytics = $this->spyAnalytics();
        $observer = new AnalyticsExposureObserver($analytics, new FlagExposureSampler(1.0), enabled: true);

        $observer->onExposure('flag', 'a', '');

        $this->assertSame([], $analytics->captured);
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
