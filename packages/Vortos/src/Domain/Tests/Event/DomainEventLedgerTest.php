<?php

declare(strict_types=1);

namespace Vortos\Domain\Tests\Event;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Domain\Identity\AggregateId;

final readonly class LedgerTestPayload
{
    public function __construct(public string $value) {}
}

final class LedgerTestId extends AggregateId {}

final class LedgerTestAggregate extends AggregateRoot
{
    private LedgerTestId $id;

    public function __construct()
    {
        $this->id = LedgerTestId::generate();
    }

    public function getId(): AggregateId
    {
        return $this->id;
    }

    public function act(string $value): void
    {
        $this->recordEvent(new LedgerTestPayload($value));
    }
}

final class DomainEventLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        DomainEventLedger::discard();
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    private static function envelope(string $value): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          'test-' . $value,
            aggregateId:      'agg-1',
            aggregateType:    'TestAggregate',
            aggregateVersion: 1,
            payloadType:      LedgerTestPayload::class,
            schemaVersion:    1,
            occurredAt:       new \DateTimeImmutable(),
            payload:          new LedgerTestPayload($value),
            metadata:         Metadata::empty(),
        );
    }

    public function test_record_outside_scope_is_ignored(): void
    {
        $ledger = DomainEventLedger::instance();
        $ledger->record(self::envelope('a'));

        $this->assertFalse($ledger->hasPending());
        $this->assertSame([], $ledger->drain());
    }

    public function test_record_inside_scope_is_collected_in_order(): void
    {
        $ledger = DomainEventLedger::instance();
        $ledger->open();
        $ledger->record(self::envelope('first'));
        $ledger->record(self::envelope('second'));

        $drained = $ledger->drain();
        $this->assertCount(2, $drained);
        $this->assertSame('test-first', $drained[0]->eventId);
        $this->assertSame('test-second', $drained[1]->eventId);
        $ledger->close();
    }

    public function test_drain_clears_buffer(): void
    {
        $ledger = DomainEventLedger::instance();
        $ledger->open();
        $ledger->record(self::envelope('a'));

        $ledger->drain();
        $this->assertFalse($ledger->hasPending());
        $this->assertSame([], $ledger->drain());
        $ledger->close();
    }

    public function test_only_first_open_is_root(): void
    {
        $ledger = DomainEventLedger::instance();
        $this->assertTrue($ledger->open());
        $this->assertFalse($ledger->open());
        $ledger->close();
        $ledger->close();
        // After full unwind a new scope is root again
        $this->assertTrue($ledger->open());
        $ledger->close();
    }

    public function test_nested_scopes_share_one_buffer(): void
    {
        $ledger = DomainEventLedger::instance();
        $ledger->open();
        $ledger->record(self::envelope('outer'));
        $ledger->open();
        $ledger->record(self::envelope('inner'));
        $ledger->close();

        // Inner close must not clear — root still owns the buffer
        $this->assertCount(2, $ledger->drain());
        $ledger->close();
    }

    public function test_root_close_clears_undrained_events(): void
    {
        // Failure path: handler threw, drain never ran, transaction rolled back.
        // Nothing may survive into the next dispatch (worker mode).
        $ledger = DomainEventLedger::instance();
        $ledger->open();
        $ledger->record(self::envelope('doomed'));
        $ledger->close();

        $ledger->open();
        $this->assertFalse($ledger->hasPending());
        $ledger->close();
    }

    public function test_aggregate_record_event_lands_in_open_ledger(): void
    {
        $ledger = DomainEventLedger::instance();
        $ledger->open();

        $aggregate = new LedgerTestAggregate();
        $aggregate->act('hello');

        $this->assertTrue($ledger->hasPending());
        $drained = $ledger->drain();
        $this->assertCount(1, $drained);
        $this->assertInstanceOf(LedgerTestPayload::class, $drained[0]->payload);
        $this->assertSame('hello', $drained[0]->payload->value);
        $ledger->close();

        // Aggregate-local buffer is independent — still available for assertions
        $this->assertCount(1, $aggregate->pullDomainEvents());
    }

    public function test_aggregate_outside_scope_keeps_local_buffer_only(): void
    {
        $aggregate = new LedgerTestAggregate();
        $aggregate->act('unit-test-style');

        $this->assertFalse(DomainEventLedger::instance()->hasPending());
        $this->assertCount(1, $aggregate->pullDomainEvents());
    }
}
