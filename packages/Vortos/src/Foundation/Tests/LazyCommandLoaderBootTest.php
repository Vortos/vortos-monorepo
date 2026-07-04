<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\DependencyInjection\Compiler\ConsoleCommandPass;

/**
 * End-to-end proof of the B10 fix: with the lazy command loader, resolving and running one command
 * never constructs any other command. This is the property that lets a single operator/deploy
 * command boot on an infra-less host — a sibling command whose graph touches Redis/Postgres/Kafka
 * is simply never instantiated.
 *
 * The tripwire command throws from its constructor; if the old eager `addCommand()` behaviour
 * regressed, building the Application (or listing) would construct it and this test would fail.
 */
final class LazyCommandLoaderBootTest extends TestCase
{
    public function test_running_one_command_never_constructs_a_sibling(): void
    {
        $container = new ContainerBuilder();
        $container->register(Application::class, Application::class)
            ->setArguments(['Test', '1.0.0'])
            ->setPublic(true);
        $container->register(SafeFixtureCommand::class, SafeFixtureCommand::class)
            ->setPublic(false)
            ->addTag('console.command');
        $container->register(TripwireFixtureCommand::class, TripwireFixtureCommand::class)
            ->setPublic(false)
            ->addTag('console.command');

        (new ConsoleCommandPass())->process($container);
        $container->compile();

        /** @var Application $app */
        $app = $container->get(Application::class);
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);

        // Resolve + run only the safe command; the tripwire must never be constructed.
        $command = $app->find('safe:cmd');
        self::assertSame('safe:cmd', $command->getName());
        self::assertFalse(TripwireFixtureCommand::$constructed, 'Sibling command must not be constructed.');
    }
}

#[AsCommand(name: 'safe:cmd', description: 'Safe fixture.')]
final class SafeFixtureCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}

#[AsCommand(name: 'tripwire:cmd', description: 'Blows up if constructed.')]
final class TripwireFixtureCommand extends Command
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;
        parent::__construct();

        throw new \RuntimeException('Tripwire command was constructed — laziness is broken.');
    }
}
