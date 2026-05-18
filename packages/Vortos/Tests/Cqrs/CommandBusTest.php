<?php
declare(strict_types=1);

namespace Vortos\Tests\Cqrs;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Cqrs\Command\CommandBus;
use Vortos\Cqrs\Command\Idempotency\InMemoryCommandIdempotencyStore;
use Vortos\Cqrs\Exception\CommandHandlerNotFoundException;
use Vortos\Domain\Command\AbstractCommand;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;
use Vortos\Tracing\NoOpTracer;

final readonly class BusTestCommand extends AbstractCommand
{
    public function __construct(public string $value = 'test') {}
}

final class CommandBusTest extends TestCase
{
    private function makeBus(array $handlers = [], array $strategies = []): CommandBus
    {
        $locator = new ServiceLocator(
            array_map(fn($h) => fn() => $h, $handlers)
        );

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(fn(callable $fn) => $fn());

        $eventBus = $this->createMock(EventBusInterface::class);
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

    public function test_dispatch_returns_null_for_idempotent_duplicate(): void
    {
        $handler = new class {
            public function __invoke(BusTestCommand $command): string { return 'first'; }
        };

        // Use property strategy so we can control the idempotency key
        $bus = $this->makeBus(
            [BusTestCommand::class => $handler],
            [BusTestCommand::class => ['strategy' => 'property', 'property' => 'value']],
        );

        $command = new BusTestCommand('idempotency-key');
        $bus->dispatch($command); // first — processed
        $result = $bus->dispatch($command); // second — skipped

        $this->assertNull($result);
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
