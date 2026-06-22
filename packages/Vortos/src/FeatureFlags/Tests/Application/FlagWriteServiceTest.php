<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Application;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Domain\Event\FlagArchivedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagCreatedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagDisabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagEnabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagRulesChangedEvent;
use Vortos\FeatureFlags\Exception\FlagNotFoundException;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class FlagWriteServiceTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        // The ledger is a process singleton — isolate every test.
        DomainEventLedger::discard();
        $this->dispatched = [];
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_create_persists_and_publishes_created_event(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->expects($this->once())->method('save');

        $service = $this->service($storage);
        $service->create($this->state(enabled: false, name: 'new-flag'), 'admin-1', 'launch');

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(FlagCreatedEvent::class, $this->dispatched[0]);
        $this->assertSame('admin-1', $this->dispatched[0]->actorId);
    }

    public function test_enable_loads_persists_and_publishes(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturn($this->state(enabled: false));
        $storage->expects($this->once())->method('save');

        $this->service($storage)->enable('my-flag', 'admin-1');

        $this->assertInstanceOf(FlagEnabledEvent::class, $this->dispatched[0]);
    }

    public function test_enable_with_rollout_rules_publishes_two_events(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturn($this->state(enabled: false));

        $this->service($storage)->enable('my-flag', 'admin-1', null, [
            new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 10),
        ]);

        $this->assertInstanceOf(FlagEnabledEvent::class, $this->dispatched[0]);
        $this->assertInstanceOf(FlagRulesChangedEvent::class, $this->dispatched[1]);
    }

    public function test_disable_publishes_disabled_event(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturn($this->state(enabled: true));

        $this->service($storage)->disable('my-flag', 'admin-1', 'incident');

        $this->assertInstanceOf(FlagDisabledEvent::class, $this->dispatched[0]);
        $this->assertSame('incident', $this->dispatched[0]->reason);
    }

    public function test_archive_and_delete_publishes_archived_event_and_deletes_row(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturn($this->state(enabled: true));
        $storage->expects($this->once())->method('delete')->with('my-flag');
        $storage->expects($this->never())->method('save');

        $this->service($storage)->archiveAndDelete('my-flag', 'admin-1', 'cleanup');

        $this->assertInstanceOf(FlagArchivedEvent::class, $this->dispatched[0]);
    }

    public function test_idempotent_enable_publishes_nothing(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturn($this->state(enabled: true));

        $this->service($storage)->enable('my-flag', 'admin-1');

        $this->assertSame([], $this->dispatched);
    }

    public function test_mutating_missing_flag_throws(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturn(null);

        $this->expectException(FlagNotFoundException::class);
        $this->service($storage)->enable('ghost', 'admin-1');
    }

    private function service(FlagStorageInterface $storage): FlagWriteService
    {
        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $work) => $work());

        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->method('dispatch')->willReturnCallback(function (EventEnvelope $e): void {
            $this->dispatched[] = $e->payload;
        });

        return new FlagWriteService($storage, $uow, $eventBus);
    }

    private function state(bool $enabled, string $name = 'my-flag'): FeatureFlag
    {
        $now = new \DateTimeImmutable();

        return new FeatureFlag(self::ID, $name, '', $enabled, [], null, $now, $now);
    }
}
