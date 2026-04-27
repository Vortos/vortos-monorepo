<?php
declare(strict_types=1);

namespace Vortos\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\DomainEvent;

final readonly class TestDomainEvent extends DomainEvent
{
    public function __construct(string $aggregateId = 'agg-1')
    {
        parent::__construct($aggregateId);
    }
}

final class DomainEventTest extends TestCase
{
    public function test_event_has_occurred_at(): void
    {
        $event = new TestDomainEvent();
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt());
    }

    public function test_event_has_aggregate_id(): void
    {
        $event = new TestDomainEvent('agg-123');
        $this->assertSame('agg-123', $event->aggregateId());
    }

    public function test_default_event_version_is_one(): void
    {
        $event = new TestDomainEvent();
        $this->assertSame(1, $event->eventVersion());
    }

    public function test_occurred_at_is_recent(): void
    {
        $before = new \DateTimeImmutable();
        $event = new TestDomainEvent();
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $event->occurredAt());
        $this->assertLessThanOrEqual($after, $event->occurredAt());
    }
}
