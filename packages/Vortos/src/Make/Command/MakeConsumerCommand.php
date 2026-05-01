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
    name: 'vortos:make:consumer',
    description: 'Generate a Kafka event handler (consumer)',
)]
final class MakeConsumerCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Handler name without "Handler" suffix (e.g. SendEmail)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. User)')
            ->addOption('event', 'e', InputOption::VALUE_REQUIRED, 'Event class name or FQCN — short name is auto-resolved from src/ (e.g. UserCreatedEvent)')
            ->addOption('consumer', null, InputOption::VALUE_REQUIRED, 'Kafka consumer name (e.g. user.events)')
            ->addOption('handler-id', null, InputOption::VALUE_OPTIONAL, 'Unique handler ID (defaults to dot.case of name)')
            ->addOption('idempotent', null, InputOption::VALUE_NONE, 'Mark handler as idempotent (safe to retry)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name       = (string) $input->getArgument('name');
        $context    = (string) $input->getOption('context');
        $eventClass = (string) $input->getOption('event');
        $consumer   = (string) $input->getOption('consumer');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        if ($eventClass === '') {
            $output->writeln('<error>--event is required. Example: --event=App\\User\\Domain\\Event\\UserCreatedEvent</error>');
            return Command::FAILURE;
        }

        if ($consumer === '') {
            $output->writeln('<error>--consumer is required. Example: --consumer=user.events</error>');
            return Command::FAILURE;
        }

        // Resolve short class name → FQCN when no backslash given
        if (!str_contains($eventClass, '\\')) {
            $matches = $this->engine->findClassByShortName($eventClass);

            if (count($matches) === 0) {
                $output->writeln("<error>No class named '{$eventClass}' found in src/. Provide the full FQCN with --event.</error>");
                return Command::FAILURE;
            }

            if (count($matches) > 1) {
                $output->writeln("<comment>Multiple classes named '{$eventClass}' found — re-run with the full class name:</comment>");
                $output->writeln('');
                foreach ($matches as $fqcn) {
                    $output->writeln("  --event={$fqcn}");
                }
                return Command::FAILURE;
            }

            $eventClass = $matches[0];
            $output->writeln("<info>Resolved:</info> {$eventClass}");
        }

        $handlerId  = (string) ($input->getOption('handler-id') ?? $this->toDotCase($name));
        $idempotent = $input->getOption('idempotent') ? 'true' : 'false';

        $eventShortClass = basename(str_replace('\\', '/', $eventClass));

        $vars = [
            'Namespace'       => "App\\{$context}",
            'ClassName'       => $name,
            'EventClass'      => $eventClass,
            'EventShortClass' => $eventShortClass,
            'ConsumerName'    => $consumer,
            'HandlerId'       => $handlerId,
            'Idempotent'      => $idempotent,
        ];

        $output->writeln("<info>vortos:make:consumer</info> {$name} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Application/EventHandler/{$name}Handler.php",
            $this->engine->render('event-handler', $vars),
            $output,
        );

        return Command::SUCCESS;
    }

    private function toDotCase(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '.$0', $name) ?? $name);
    }
}
