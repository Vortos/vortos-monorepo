<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\DependencyInjection\Compiler\ConsoleCommandPass;

/**
 * ConsoleCommandPass is the framework-wide, single-registration mechanism (wired once
 * via FoundationPackage, loaded by every real app through Foundation\Bootstrap\Container.php)
 * that keeps every console.command-tagged service — and everything it depends on — alive
 * against Symfony's RemoveUnusedDefinitionsPass. Without a public Application service, that
 * whole chain silently disappears during compilation.
 *
 * These tests assert the pass fails loudly instead of silently no-op'ing when a container
 * is assembled without the Application service Foundation normally provides — this is the
 * framework-wide guard against any package (present or future) accidentally building an
 * incomplete container.
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

    public function test_wires_commands_when_application_is_public(): void
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
        self::assertSame('addCommand', $calls[0][0]);
    }
}

final class FixtureCommand extends Command
{
}
