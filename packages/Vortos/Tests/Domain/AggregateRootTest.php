<?php
declare(strict_types=1);

namespace Vortos\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Event\DomainEvent;
use Vortos\Domain\Identity\AggregateId;

final readonly class AggregateTestEvent extends DomainEvent {}

final class AggregateTestId extends AggregateId {}

final class TestAggregate extends AggregateRoot
{
    private AggregateTestId $id;

    public function __construct()
    {
        $this->id = AggregateTestId::generate();
    }

    public function getId(): AggregateId { return $this->id; }

    public function doSomething(): void
    {
        $this->recordEvent(new AggregateTestEvent('agg-1'));
    }
}

final class AggregateRootTest extends TestCase
{
    public function test_raises_and_records_domain_event(): void
    {
        $aggregate = new TestAggregate();
        $aggregate->doSomething();
        $this->assertCount(1, $aggregate->pullDomainEvents());
    }

    public function test_pull_clears_events(): void
    {
        $aggregate = new TestAggregate();
        $aggregate->doSomething();
        $aggregate->pullDomainEvents();
        $this->assertEmpty($aggregate->pullDomainEvents());
    }

    public function test_raises_multiple_events(): void
    {
        $aggregate = new TestAggregate();
        $aggregate->doSomething();
        $aggregate->doSomething();
        $this->assertCount(2, $aggregate->pullDomainEvents());
    }

    public function test_has_domain_events_true_when_events_recorded(): void
    {
        $aggregate = new TestAggregate();
        $aggregate->doSomething();
        $this->assertTrue($aggregate->hasDomainEvents());
    }

    public function test_has_domain_events_false_after_pull(): void
    {
        $aggregate = new TestAggregate();
        $aggregate->doSomething();
        $aggregate->pullDomainEvents();
        $this->assertFalse($aggregate->hasDomainEvents());
    }

    public function test_version_starts_at_zero(): void
    {
        $this->assertSame(0, (new TestAggregate())->getVersion());
    }

    public function test_version_increments(): void
    {
        $aggregate = new TestAggregate();
        $aggregate->incrementVersion();
        $aggregate->incrementVersion();
        $this->assertSame(2, $aggregate->getVersion());
    }

    public function test_get_id_returns_aggregate_id(): void
    {
        $this->assertInstanceOf(AggregateId::class, (new TestAggregate())->getId());
    }
}
