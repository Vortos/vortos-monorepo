<?php

declare(strict_types=1);

namespace Vortos\Tests\Cqrs\Command;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Cqrs\Command\CommandBus;
use Vortos\Cqrs\Command\Idempotency\InMemoryCommandIdempotencyStore;
use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\Domain\Command\AbstractCommand;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final readonly class ValidatedCommand extends AbstractCommand
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Email]            public string $email,
        #[Assert\NotBlank] #[Assert\Length(min: 2)]   public string $name,
        #[Assert\Positive]                             public int    $age,
    ) {}
}

final readonly class UnconstrainedCommand extends AbstractCommand
{
    public function __construct(public string $payload) {}
}

final class CommandBusValidationTest extends TestCase
{
    private function makeBus(
        array $handlers,
        ?UnitOfWorkInterface $uow = null,
        ?VortosValidator $validator = null,
    ): CommandBus {
        $locator = new ServiceLocator(
            array_map(fn($h) => fn() => $h, $handlers),
        );
        $uow ??= $this->makeUow();

        return new CommandBus(
            $locator,
            $uow,
            $this->createMock(EventBusInterface::class),
            new InMemoryCommandIdempotencyStore(),
            new NullLogger(),
            [],
            $validator,
        );
    }

    private function makeUow(): UnitOfWorkInterface
    {
        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(fn(callable $fn) => $fn());
        return $uow;
    }

    public function test_valid_command_reaches_handler(): void
    {
        $called = false;
        $bus = $this->makeBus(
            [ValidatedCommand::class => function (ValidatedCommand $c) use (&$called) { $called = true; }],
            validator: new VortosValidator(),
        );
        $bus->dispatch(new ValidatedCommand('alice@example.com', 'Alice', 30));
        $this->assertTrue($called);
    }

    public function test_invalid_command_throws_before_handler(): void
    {
        $called = false;
        $bus = $this->makeBus(
            [ValidatedCommand::class => function (ValidatedCommand $c) use (&$called) { $called = true; }],
            validator: new VortosValidator(),
        );
        try {
            $bus->dispatch(new ValidatedCommand('not-an-email', '', -1));
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
            $this->assertFalse($called);
        }
    }

    public function test_invalid_command_does_not_open_transaction(): void
    {
        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->expects($this->never())->method('run');
        $bus = $this->makeBus(
            [ValidatedCommand::class => fn(ValidatedCommand $c) => null],
            uow: $uow,
            validator: new VortosValidator(),
        );
        try {
            $bus->dispatch(new ValidatedCommand('bad', '', -1));
        } catch (ValidationException) {}
    }

    public function test_unconstrained_command_dispatches_without_validator(): void
    {
        $called = false;
        $bus = $this->makeBus(
            [UnconstrainedCommand::class => function (UnconstrainedCommand $c) use (&$called) { $called = true; }],
            validator: null,
        );
        $bus->dispatch(new UnconstrainedCommand('hello'));
        $this->assertTrue($called);
    }

    public function test_violation_map_contains_all_failing_fields(): void
    {
        $bus = $this->makeBus(
            [ValidatedCommand::class => fn(ValidatedCommand $c) => null],
            validator: new VortosValidator(),
        );
        try {
            $bus->dispatch(new ValidatedCommand('bad', '', -1));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $map = $e->getViolationMap();
            $this->assertArrayHasKey('email', $map);
            $this->assertArrayHasKey('name', $map);
            $this->assertArrayHasKey('age', $map);
        }
    }

    public function test_invalid_command_does_not_mark_idempotency_key(): void
    {
        $store   = new InMemoryCommandIdempotencyStore();
        $locator = new ServiceLocator([ValidatedCommand::class => fn() => fn(ValidatedCommand $c) => null]);
        $bus     = new CommandBus(
            $locator, $this->makeUow(),
            $this->createMock(EventBusInterface::class),
            $store, new NullLogger(), [], new VortosValidator(),
        );
        try {
            $bus->dispatch(new ValidatedCommand('bad', 'Alice', 30));
        } catch (ValidationException) {}
        $this->assertFalse($store->wasProcessed('any-key'));
    }
}
