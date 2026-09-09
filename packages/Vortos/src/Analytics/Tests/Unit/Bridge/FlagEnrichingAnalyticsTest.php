<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Bridge;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Bridge\FlagEnrichingAnalytics;
use Vortos\Analytics\Capability\AnalyticsCapability;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Privacy\ReservedProperties;
use Vortos\FeatureFlags\Exposure\ActiveFlagCollector;
use Vortos\FeatureFlags\Exposure\ActiveFlagSourceInterface;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Flag attribution on every event is what makes "does this flag move the metric" answerable:
 * the metric lives on a different event from the exposure, so without this the two can never
 * be joined.
 */
final class FlagEnrichingAnalyticsTest extends TestCase
{
    public function test_stamps_the_requests_flags_onto_an_event(): void
    {
        $inner = $this->spy();
        $collector = new ActiveFlagCollector();
        $collector->record('checkout', 'blue');

        $this->enricher($inner, $collector)->capture($this->event(['amount' => 100]));

        $this->assertSame(['checkout' => 'blue'], $inner->captured[0]->properties[ReservedProperties::ACTIVE_FLAGS]);
        $this->assertSame(100, $inner->captured[0]->properties['amount']);
    }

    public function test_attribution_is_prepended_so_bounding_drops_it_last(): void
    {
        // AnalyticsEvent bounds oversized property bags from the tail. Appending would make
        // attribution the first thing lost on exactly the events carrying the most context.
        $inner = $this->spy();
        $collector = new ActiveFlagCollector();
        $collector->record('checkout', 'blue');

        $this->enricher($inner, $collector)->capture($this->event(['a' => 1, 'b' => 2]));

        $this->assertSame(ReservedProperties::ACTIVE_FLAGS, array_key_first($inner->captured[0]->properties));
    }

    public function test_an_explicit_value_from_the_caller_wins(): void
    {
        $inner = $this->spy();
        $collector = new ActiveFlagCollector();
        $collector->record('checkout', 'blue');

        $this->enricher($inner, $collector)->capture($this->event([
            ReservedProperties::ACTIVE_FLAGS => ['historic' => 'green'],
        ]));

        $this->assertSame(['historic' => 'green'], $inner->captured[0]->properties[ReservedProperties::ACTIVE_FLAGS]);
    }

    public function test_no_flags_evaluated_means_the_event_is_untouched(): void
    {
        $inner = $this->spy();

        $original = $this->event(['amount' => 100]);
        $this->enricher($inner, new ActiveFlagCollector())->capture($original);

        $this->assertSame($original, $inner->captured[0]);
    }

    public function test_disabled_never_enriches(): void
    {
        $inner = $this->spy();
        $collector = new ActiveFlagCollector();
        $collector->record('checkout', 'blue');

        (new FlagEnrichingAnalytics($inner, $collector, enabled: false))->capture($this->event([]));

        $this->assertArrayNotHasKey(ReservedProperties::ACTIVE_FLAGS, $inner->captured[0]->properties);
    }

    public function test_identify_carries_attribution_for_person_level_cohorts(): void
    {
        $inner = $this->spy();
        $collector = new ActiveFlagCollector();
        $collector->record('checkout', 'blue');

        $this->enricher($inner, $collector)->identify(new IdentitySet(new DistinctId('u1'), ['plan' => 'pro']));

        $this->assertSame(['checkout' => 'blue'], $inner->identified[0]->traits[ReservedProperties::ACTIVE_FLAGS]);
        $this->assertSame('pro', $inner->identified[0]->traits['plan']);
    }

    public function test_a_failure_forwards_the_original_event_rather_than_dropping_it(): void
    {
        // Losing attribution is acceptable. Losing the event is not.
        $inner = $this->spy();
        $collector = new class implements ActiveFlagSourceInterface {
            public function all(): array { throw new RuntimeException('boom'); }
        };

        $original = $this->event(['amount' => 100]);
        $this->enricher($inner, $collector)->capture($original);

        $this->assertSame($original, $inner->captured[0]);
    }

    public function test_group_and_flush_pass_straight_through(): void
    {
        $inner = $this->spy();
        $enricher = $this->enricher($inner, new ActiveFlagCollector());

        $enricher->group(new GroupAssociation(new DistinctId('u1'), 'organization', 'org-7'));
        $enricher->flush();

        $this->assertCount(1, $inner->grouped);
        $this->assertSame(1, $inner->flushes);
    }

    public function test_name_and_capabilities_are_delegated(): void
    {
        $inner = $this->spy();
        $enricher = $this->enricher($inner, new ActiveFlagCollector());

        $this->assertSame('spy', $enricher->name());
        $this->assertSame($inner->capabilities()->toArray(), $enricher->capabilities()->toArray());
    }

    private function enricher(AnalyticsInterface $inner, ActiveFlagSourceInterface $collector): FlagEnrichingAnalytics
    {
        return new FlagEnrichingAnalytics($inner, $collector, enabled: true);
    }

    /** @param array<string,mixed> $properties */
    private function event(array $properties): AnalyticsEvent
    {
        return new AnalyticsEvent(new DistinctId('u1'), 'payment_succeeded', $properties);
    }

    private function spy(): object
    {
        return new class implements AnalyticsInterface {
            /** @var list<AnalyticsEvent> */
            public array $captured = [];
            /** @var list<IdentitySet> */
            public array $identified = [];
            /** @var list<GroupAssociation> */
            public array $grouped = [];
            public int $flushes = 0;

            public function name(): string { return 'spy'; }
            public function capture(AnalyticsEvent $event): void { $this->captured[] = $event; }
            public function identify(IdentitySet $identity): void { $this->identified[] = $identity; }
            public function group(GroupAssociation $group): void { $this->grouped[] = $group; }
            public function flush(): void { $this->flushes++; }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => true]);
            }
        };
    }
}
