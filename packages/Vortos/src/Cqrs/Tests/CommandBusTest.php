<?php
declare(strict_types=1);

namespace Vortos\Cqrs\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Cqrs\Command\CommandBus;
use Vortos\Cqrs\Command\Idempotency\InMemoryCommandIdempotencyStore;
use Vortos\Cqrs\Exception\CommandHandlerNotFoundException;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Command\AbstractCommand;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Identity\AggregateId;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;
use Vortos\Tracing\NoOpTracer;

final readonly class BusTestCommand extends AbstractCommand
{
    public function __construct(public string $value = 'test') {}
}

final readonly class BusTestEventPayload
{
    public function __construct(public string $value) {}
}

final class BusTestAggregateId extends AggregateId {}

final class BusTestAggregate extends AggregateRoot
{
    private BusTestAggregateId $id;

    public function __construct()
    {
        $this->id = BusTestAggregateId::generate();
    }

    public function getId(): AggregateId
    {
        return $this->id;
    }

    public function act(string $value): void
    {
        $this->recordEvent(new BusTestEventPayload($value));
    }
}

/** Captures dispatched envelopes; optionally runs a callback per dispatch (simulates in-process handlers). */
final class CapturingEventBus implements EventBusInterface
{
    /** @var EventEnvelope[] */
    public array $dispatched = [];

    /** @var null|callable(EventEnvelope): void */
    public $onDispatch = null;

    public function dispatch(EventEnvelope $envelope): void
    {
        $this->dispatched[] = $envelope;
        if ($this->onDispatch !== null) {
            ($this->onDispatch)($envelope);
        }
    }

    public function dispatchBatch(EventEnvelope ...$envelopes): void
    {
        foreach ($envelopes as $envelope) {
            $this->dispatch($envelope);
        }
    }
}

final class CommandBusTest extends TestCase
{
    protected function setUp(): void
    {
        DomainEventLedger::discard();
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    private function makeBus(array $handlers = [], array $strategies = [], ?EventBusInterface $eventBus = null): CommandBus
    {
        $locator = new ServiceLocator(
            array_map(fn($h) => fn() => $h, $handlers)
        );

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(fn(callable $fn) => $fn());

        $eventBus ??= $this->createMock(EventBusInterface::class);
        $idempotency = new InMemoryCommandIdempotencyStore();
        $logger = new NullLogger();
        $tracer = new NoOpTracer();

        return new CommandBus($locator, $uow, $eventBus, $idempotency, $logger, $tracer, $strategies);
    }

    public function test_dispatches_command_to_handler(): void
    {
        $handled = false;
        $handler = new class($handled) {
            public function __construct(private bool &$handled) {}
            public function __invoke(BusTestCommand $command): void
            {
                $this->handled = true;
            }
        };

        $bus = $this->makebus(
            [BusTestCommand::class => $handler],
            [BusTestCommand::class => ['strategy' => 'none']]
        );
        $bus->dispatch(new BusTestCommand());

        $this->assertTrue($handled);
    }

    public function test_throws_when_no_handler(): void
    {
        $bus = $this->makeBus();
        $this->expectException(CommandHandlerNotFoundException::class);
        $bus->dispatch(new BusTestCommand());
    }

    public function test_dispatch_returns_handler_result(): void
    {
        $handler = new class {
            public function __invoke(BusTestCommand $command): string
            {
                return 'aggregate-result';
            }
        };

        $bus    = $this->makeBus([BusTestCommand::class => $handler]);
        $result = $bus->dispatch(new BusTestCommand());

        $this->assertSame('aggregate-result', $result);
    }

    public function test_dispatch_returns_null_when_handler_returns_void(): void
    {
        $handler = new class {
            public function __invoke(BusTestCommand $command): void {}
        };

        $bus    = $this->makeBus([BusTestCommand::class => $handler]);
        $result = $bus->dispatch(new BusTestCommand());

        $this->assertNull($result);
    }

    public function test_dispatch_returns_cached_result_for_idempotent_duplicate(): void
    {
        $handler = new class {
            public function __invoke(BusTestCommand $command): string { return 'first'; }
        };

        $bus = $this->makeBus(
            [BusTestCommand::class => $handler],
            [BusTestCommand::class => ['strategy' => 'property', 'property' => 'value']],
        );

        $command = new BusTestCommand('idempotency-key');
        $first  = $bus->dispatch($command);
        $second = $bus->dispatch($command);

        $this->assertSame('first', $first);
        $this->assertSame('first', $second);
    }

    public function test_duplicate_handler_not_called_again(): void
    {
        $callCount = 0;
        $handler = new class($callCount) {
            public function __construct(private int &$callCount) {}
            public function __invoke(BusTestCommand $command): string
            {
                $this->callCount++;
                return 'result';
            }
        };

        $bus = $this->makeBus(
            [BusTestCommand::class => $handler],
            [BusTestCommand::class => ['strategy' => 'property', 'property' => 'value']],
        );

        $command = new BusTestCommand('same-key');
        $bus->dispatch($command);
        $bus->dispatch($command);
        $bus->dispatch($command);

        $this->assertSame(1, $callCount);
    }

    public function test_events_dispatch_when_handler_returns_void(): void
    {
        $eventBus = new CapturingEventBus();
        $handler = new class {
            public function __invoke(BusTestCommand $command): void
            {
                $aggregate = new BusTestAggregate();
                $aggregate->act('void-return');
                // no return — events must still dispatch
            }
        };

        $bus = $this->makeBus([BusTestCommand::class => $handler], [], $eventBus);
        $bus->dispatch(new BusTestCommand());

        $this->assertCount(1, $eventBus->dispatched);
        $this->assertSame('void-return', $eventBus->dispatched[0]->payload->value);
    }

    public function test_events_dispatch_when_handler_returns_plain_dto(): void
    {
        $eventBus = new CapturingEventBus();
        $handler = new class {
            public function __invoke(BusTestCommand $command): array
            {
                $aggregate = new BusTestAggregate();
                $aggregate->act('dto-return');
                return ['id' => (string) $aggregate->getId()];
            }
        };

        $bus = $this->makeBus([BusTestCommand::class => $handler], [], $eventBus);
        $result = $bus->dispatch(new BusTestCommand());

        $this->assertCount(1, $eventBus->dispatched);
        $this->assertArrayHasKey('id', $result);
    }

    public function test_returning_aggregate_does_not_double_dispatch(): void
    {
        $eventBus = new CapturingEventBus();
        $handler = new class {
            public function __invoke(BusTestCommand $command): BusTestAggregate
            {
                $aggregate = new BusTestAggregate();
                $aggregate->act('returned');
                return $aggregate;
            }
        };

        $bus = $this->makeBus([BusTestCommand::class => $handler], [], $eventBus);
        $bus->dispatch(new BusTestCommand());

        $this->assertCount(1, $eventBus->dispatched);
    }

    public function test_bulk_handler_events_from_all_aggregates_dispatch_in_order(): void
    {
        $eventBus = new CapturingEventBus();
        $handler = new class {
            public function __invoke(BusTestCommand $command): void
            {
                foreach (['a', 'b', 'c'] as $value) {
                    $aggregate = new BusTestAggregate();
                    $aggregate->act($value);
                }
            }
        };

        $bus = $this->makeBus([BusTestCommand::class => $handler], [], $eventBus);
        $bus->dispatch(new BusTestCommand());

        $this->assertSame(
            ['a', 'b', 'c'],
            array_map(fn($e) => $e->payload->value, $eventBus->dispatched),
        );
    }

    public function test_follow_on_events_recorded_during_dispatch_are_dispatched(): void
    {
        // Simulates an in-process event handler that mutates another aggregate
        // while the ledger is mid-drain.
        $eventBus = new CapturingEventBus();
        $eventBus->onDispatch = function (EventEnvelope $envelope) {
            if ($envelope->payload->value === 'first') {
                $reactor = new BusTestAggregate();
                $reactor->act('follow-on');
            }
        };

        $handler = new class {
            public function __invoke(BusTestCommand $command): void
            {
                $aggregate = new BusTestAggregate();
                $aggregate->act('first');
            }
        };

        $bus = $this->makeBus([BusTestCommand::class => $handler], [], $eventBus);
        $bus->dispatch(new BusTestCommand());

        $this->assertSame(
            ['first', 'follow-on'],
            array_map(fn($e) => $e->payload->value, $eventBus->dispatched),
        );
    }

    public function test_no_events_dispatched_when_handler_throws_and_no_leak_into_next_dispatch(): void
    {
        $eventBus = new CapturingEventBus();
        $failing = new class {
            public function __invoke(BusTestCommand $command): void
            {
                $aggregate = new BusTestAggregate();
                $aggregate->act('doomed');
                throw new \RuntimeException('boom');
            }
        };

        $bus = $this->makeBus([BusTestCommand::class => $failing], [], $eventBus);

        try {
            $bus->dispatch(new BusTestCommand());
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $eventBus->dispatched);

        // Worker-mode simulation: the next dispatch on the same process must not
        // see the failed dispatch's events.
        $clean = new class {
            public function __invoke(BusTestCommand $command): void
            {
                $aggregate = new BusTestAggregate();
                $aggregate->act('clean');
            }
        };
        $bus2 = $this->makeBus([BusTestCommand::class => $clean], [], $eventBus);
        $bus2->dispatch(new BusTestCommand());

        $this->assertCount(1, $eventBus->dispatched);
        $this->assertSame('clean', $eventBus->dispatched[0]->payload->value);
    }

    public function test_handler_receives_correct_command(): void
    {
        $received = null;
        $handler = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(BusTestCommand $command): void
            {
                $this->received = $command;
            }
        };

        $bus = $this->makeBus(
            [BusTestCommand::class => $handler],
            [BusTestCommand::class => ['strategy' => 'none']]
        );
        $command = new BusTestCommand('hello');
        $bus->dispatch($command);

        $this->assertSame($command, $received);
    }
}
