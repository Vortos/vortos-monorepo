<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Capability\AnalyticsCapability;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Runtime\AnalyticsSpool;
use Vortos\Analytics\Runtime\BatchingAnalytics;
use Vortos\Analytics\Runtime\IdentityDedupeCache;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Observability\Buffer\BoundedSpool;

final class BatchingAnalyticsTest extends TestCase
{
    public function test_capture_buffers_and_does_not_forward_immediately(): void
    {
        $inner = $this->spyDriver();
        $batching = new BatchingAnalytics($inner, new IdentityDedupeCache(), bufferMax: 500, flushAt: 100);

        $batching->capture($this->event('a'));

        $this->assertSame([], $inner->captured, 'must not forward before threshold/explicit flush');
        $this->assertSame(1, $batching->bufferedCount());
    }

    public function test_flush_forwards_buffered_events_and_calls_inner_flush(): void
    {
        $inner = $this->spyDriver();
        $batching = new BatchingAnalytics($inner, new IdentityDedupeCache(), bufferMax: 500, flushAt: 100);

        $batching->capture($this->event('a'));
        $batching->capture($this->event('b'));
        $batching->flush();

        $this->assertCount(2, $inner->captured);
        $this->assertSame(1, $inner->flushCount);
        $this->assertSame(0, $batching->bufferedCount());
    }

    public function test_threshold_triggers_automatic_flush(): void
    {
        $inner = $this->spyDriver();
        $batching = new BatchingAnalytics($inner, new IdentityDedupeCache(), bufferMax: 500, flushAt: 2);

        $batching->capture($this->event('a'));
        $this->assertCount(0, $inner->captured);
        $batching->capture($this->event('b'));

        $this->assertCount(2, $inner->captured, 'reaching flushAt must trigger an automatic flush');
    }

    public function test_overflow_drops_oldest_and_increments_counter(): void
    {
        $inner = $this->spyDriver();
        // flushAt higher than bufferMax so the buffer never auto-flushes during this test.
        $batching = new BatchingAnalytics($inner, new IdentityDedupeCache(), bufferMax: 2, flushAt: 1000);

        $batching->capture($this->event('a'));
        $batching->capture($this->event('b'));
        $batching->capture($this->event('c')); // overflow -> drops 'a'

        $this->assertSame(1, $batching->droppedTotal());
        $this->assertSame(2, $batching->bufferedCount());

        $batching->flush();
        $names = array_map(static fn (AnalyticsEvent $e) => $e->properties['tag'], $inner->captured);
        $this->assertSame(['b', 'c'], $names, 'oldest must be dropped, never the newest');
    }

    public function test_flush_never_throws_when_inner_throws(): void
    {
        $batching = new BatchingAnalytics($this->throwingDriver(), new IdentityDedupeCache());
        $batching->capture($this->event('a'));
        $batching->flush();
        $this->addToAssertionCount(1);
    }

    public function test_flush_empty_buffer_is_a_noop_but_still_calls_inner_flush(): void
    {
        $inner = $this->spyDriver();
        $batching = new BatchingAnalytics($inner, new IdentityDedupeCache());

        $batching->flush();

        $this->assertSame([], $inner->captured);
        $this->assertSame(1, $inner->flushCount);
    }

    public function test_duplicate_identify_within_window_forwards_once(): void
    {
        $inner = $this->spyDriver();
        $batching = new BatchingAnalytics($inner, new IdentityDedupeCache());
        $identity = new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']);

        $batching->identify($identity);
        $batching->identify($identity);

        $this->assertCount(1, $inner->identified);
    }

    public function test_distinct_identify_content_both_forward(): void
    {
        $inner = $this->spyDriver();
        $batching = new BatchingAnalytics($inner, new IdentityDedupeCache());

        $batching->identify(new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']));
        $batching->identify(new IdentitySet(new DistinctId('user-1'), ['plan' => 'free']));

        $this->assertCount(2, $inner->identified);
    }

    public function test_duplicate_group_within_window_forwards_once(): void
    {
        $inner = $this->spyDriver();
        $batching = new BatchingAnalytics($inner, new IdentityDedupeCache());
        $group = new GroupAssociation(new DistinctId('user-1'), 'org', 'acme');

        $batching->group($group);
        $batching->group($group);

        $this->assertCount(1, $inner->grouped);
    }

    public function test_identify_never_throws_when_inner_throws(): void
    {
        $batching = new BatchingAnalytics($this->throwingDriver(), new IdentityDedupeCache());
        $batching->identify(new IdentitySet(new DistinctId('user-1')));
        $this->addToAssertionCount(1);
    }

    public function test_with_durable_spool_flush_writes_to_spool_instead_of_inner(): void
    {
        $inner = $this->spyDriver();
        $spool = new AnalyticsSpool(new BoundedSpool($this->tempSpoolPath(), 1024 * 1024));
        $batching = new BatchingAnalytics($inner, new IdentityDedupeCache(), spool: $spool);

        $batching->capture($this->event('a'));
        $batching->flush();

        $this->assertSame([], $inner->captured, 'request path must not call the inner driver when spooling');
        $this->assertFalse($spool->isEmpty());

        $drained = $spool->drain(10);
        $this->assertCount(1, $drained);
        $this->assertSame('a', $drained[0]->properties['tag']);
    }

    public function test_name_and_capabilities_delegate_to_inner(): void
    {
        $inner = $this->spyDriver();
        $batching = new BatchingAnalytics($inner, new IdentityDedupeCache());

        $this->assertSame('spy', $batching->name());
        $this->assertSame($inner->capabilities()->toArray(), $batching->capabilities()->toArray());
    }

    private function tempSpoolPath(): string
    {
        return sys_get_temp_dir() . '/vortos-analytics-test-' . bin2hex(random_bytes(8)) . '/events.spool';
    }

    private function event(string $tag): AnalyticsEvent
    {
        return new AnalyticsEvent(new DistinctId('user-1'), 'evt', ['tag' => $tag]);
    }

    private function spyDriver(): object
    {
        return new class implements AnalyticsInterface {
            /** @var list<AnalyticsEvent> */
            public array $captured = [];
            /** @var list<IdentitySet> */
            public array $identified = [];
            /** @var list<GroupAssociation> */
            public array $grouped = [];
            public int $flushCount = 0;

            public function name(): string
            {
                return 'spy';
            }

            public function capture(AnalyticsEvent $event): void
            {
                $this->captured[] = $event;
            }

            public function identify(IdentitySet $identity): void
            {
                $this->identified[] = $identity;
            }

            public function group(GroupAssociation $group): void
            {
                $this->grouped[] = $group;
            }

            public function flush(): void
            {
                $this->flushCount++;
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => true]);
            }
        };
    }

    private function throwingDriver(): AnalyticsInterface
    {
        return new class implements AnalyticsInterface {
            public function name(): string
            {
                return 'throwing';
            }

            public function capture(AnalyticsEvent $event): void
            {
                throw new RuntimeException('boom');
            }

            public function identify(IdentitySet $identity): void
            {
                throw new RuntimeException('boom');
            }

            public function group(GroupAssociation $group): void
            {
                throw new RuntimeException('boom');
            }

            public function flush(): void
            {
                throw new RuntimeException('boom');
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => false]);
            }
        };
    }
}
