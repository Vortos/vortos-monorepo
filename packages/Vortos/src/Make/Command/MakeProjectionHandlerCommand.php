<?php

declare(strict_types=1);

namespace Vortos\Make\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Engine\GeneratorEngine;

#[AsCommand(
    name: 'vortos:make:projection-handler',
    description: 'Generate a Kafka projection handler',
)]
final class MakeProjectionHandlerCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Handler name without "ProjectionHandler" suffix (e.g. UserRegistered)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. User)')
            ->addOption('consumer', null, InputOption::VALUE_REQUIRED, 'Kafka consumer name (e.g. user.events)')
            ->addOption('handler-id', null, InputOption::VALUE_OPTIONAL, 'Unique handler ID (defaults to dot.case of name)')
            ->addOption('event', 'e', InputOption::VALUE_OPTIONAL, 'Event class name or FQCN — short name is auto-resolved from src/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name     = (string) $input->getArgument('name');
        $context  = (string) $input->getOption('context');
        $consumer = (string) $input->getOption('consumer');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        if ($consumer === '') {
            $output->writeln('<error>--consumer is required. Example: --consumer=user.events</error>');
            return Command::FAILURE;
        }

        $handlerId  = (string) ($input->getOption('handler-id') ?? $this->toDotCase($name));
        $eventInput = (string) $input->getOption('event');

        [$eventImport, $eventType] = $this->resolveEvent($eventInput, $output);
        if ($eventImport === null) {
            return Command::FAILURE;
        }

        $vars = [
            'Namespace'    => "App\\{$context}",
            'ClassName'    => $name,
            'ConsumerName' => $consumer,
            'HandlerId'    => $handlerId,
            'EventImport'  => $eventImport,
            'EventType'    => $eventType,
        ];

        $output->writeln("<info>vortos:make:projection-handler</info> {$name} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Application/Projection/{$name}ProjectionHandler.php",
            $this->engine->render('projection-handler', $vars),
            $output,
        );

        return Command::SUCCESS;
    }

    /** @return array{string|null, string} */
    private function resolveEvent(string $eventInput, OutputInterface $output): array
    {
        if ($eventInput === '') {
            return ['use Vortos\Domain\Event\DomainEventInterface;', 'DomainEventInterface'];
        }

        if (str_contains($eventInput, '\\')) {
            $shortClass = basename(str_replace('\\', '/', $eventInput));
            return ["use {$eventInput};", $shortClass];
        }

        $matches = $this->engine->findClassByShortName($eventInput);

        if (count($matches) === 0) {
            $output->writeln("<error>No class named '{$eventInput}' found in src/. Provide the full FQCN with --event.</error>");
            return [null, ''];
        }

        if (count($matches) > 1) {
            $output->writeln("<comment>Multiple classes named '{$eventInput}' found — re-run with the full class name:</comment>");
            $output->writeln('');
            foreach ($matches as $fqcn) {
                $output->writeln("  --event={$fqcn}");
            }
            return [null, ''];
        }

        $fqcn = $matches[0];
        $output->writeln("<info>Resolved:</info> {$fqcn}");

        return ["use {$fqcn};", $eventInput];
    }

    private function toDotCase(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '.$0', $name) ?? $name);
    }
}
