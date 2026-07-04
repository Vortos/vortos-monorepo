<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\DependencyInjection\Compiler\ConsoleCommandPass;

/**
 * ConsoleCommandPass is the framework-wide, single-registration mechanism (wired once
 * via FoundationPackage, loaded by every real app through Foundation\Bootstrap\Container.php)
 * that keeps every console.command-tagged service — and everything it depends on — alive
 * against Symfony's RemoveUnusedDefinitionsPass, and wires them into the Application through a
 * **lazy** command loader so only the invoked command boots (deploy-in-image, infra-free).
 *
 * These tests assert the pass (a) fails loudly instead of silently no-op'ing when a container is
 * assembled without the public Application service Foundation normally provides, and (b) registers
 * a lazy ContainerCommandLoader rather than eagerly adding every command.
 */
final class ConsoleCommandPassTest extends TestCase
{
    public function test_no_op_when_no_commands_tagged_and_no_application(): void
    {
        $container = new ContainerBuilder();

        (new ConsoleCommandPass())->process($container);

        self::assertFalse($container->has(Application::class));
    }

    public function test_throws_when_commands_tagged_but_no_application_registered(): void
    {
        $container = new ContainerBuilder();
        $container->register(FixtureCommand::class, FixtureCommand::class)
            ->setPublic(false)
            ->addTag('console.command');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/console\.command.*no.*Application.*service/s');

        (new ConsoleCommandPass())->process($container);
    }

    public function test_throws_when_application_registered_but_private(): void
    {
        $container = new ContainerBuilder();
        $container->register(FixtureCommand::class, FixtureCommand::class)
            ->setPublic(false)
            ->addTag('console.command');
        $container->register(Application::class, Application::class)
            ->setPublic(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/is registered but not public/');

        (new ConsoleCommandPass())->process($container);
    }

    public function test_registers_a_lazy_command_loader_instead_of_eager_add_command(): void
    {
        $container = new ContainerBuilder();
        $container->register(FixtureCommand::class, FixtureCommand::class)
            ->setPublic(false)
            ->addTag('console.command');
        $container->register(Application::class, Application::class)
            ->setPublic(true);

        (new ConsoleCommandPass())->process($container);

        $calls = $container->getDefinition(Application::class)->getMethodCalls();
        self::assertCount(1, $calls);
        self::assertSame('setCommandLoader', $calls[0][0]);
        // Never eagerly add commands — that is exactly what forced every command (and its infra) to boot.
        self::assertNotContains('addCommand', array_column($calls, 0));
    }

    public function test_loader_maps_command_name_to_service_and_is_a_container_command_loader(): void
    {
        $container = new ContainerBuilder();
        $container->register(FixtureCommand::class, FixtureCommand::class)
            ->setPublic(false)
            ->addTag('console.command');
        $container->register(Application::class, Application::class)
            ->setPublic(true);

        (new ConsoleCommandPass())->process($container);

        $loaderRef = (string) $container->getDefinition(Application::class)->getMethodCalls()[0][1][0];
        $loader = $container->getDefinition($loaderRef);
        self::assertSame(ContainerCommandLoader::class, $loader->getClass());

        /** @var array<string, string> $commandMap */
        $commandMap = $loader->getArgument(1);
        self::assertArrayHasKey('fixture:cmd', $commandMap);
        self::assertSame(FixtureCommand::class, $commandMap['fixture:cmd']);
    }

    public function test_command_without_a_name_fails_closed(): void
    {
        $container = new ContainerBuilder();
        $container->register(NamelessFixtureCommand::class, NamelessFixtureCommand::class)
            ->setPublic(false)
            ->addTag('console.command');
        $container->register(Application::class, Application::class)
            ->setPublic(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no command name/');

        (new ConsoleCommandPass())->process($container);
    }
}

#[AsCommand(name: 'fixture:cmd', description: 'A fixture command.')]
final class FixtureCommand extends Command
{
}

final class NamelessFixtureCommand extends Command
{
}
