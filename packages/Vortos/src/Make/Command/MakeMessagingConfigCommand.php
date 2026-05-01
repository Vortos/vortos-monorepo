<?php

declare(strict_types=1);

namespace Vortos\Make\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Engine\GeneratorEngine;

#[AsCommand(
    name: 'vortos:make:messaging-config',
    description: 'Generate a MessagingConfig class wiring transport, producer and consumer',
)]
final class MakeMessagingConfigCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. User)')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Transport name (e.g. user.events)')
            ->addOption('topic', null, InputOption::VALUE_REQUIRED, 'Kafka topic name (e.g. user.events)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context   = (string) $input->getOption('context');
        $transport = (string) $input->getOption('transport');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        if ($transport === '') {
            $output->writeln('<error>--transport is required. Example: --transport=user.events</error>');
            return Command::FAILURE;
        }

        $topic   = (string) ($input->getOption('topic') ?: $transport);
        $groupId = strtolower($context) . '-service';

        $vars = [
            'Namespace'     => "App\\{$context}",
            'ClassName'     => $context,
            'TransportName' => $transport,
            'TopicName'     => $topic,
            'GroupId'       => $groupId,
        ];

        $output->writeln("<info>vortos:make:messaging-config</info> --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Infrastructure/{$context}MessagingConfig.php",
            $this->engine->render('messaging-config', $vars),
            $output,
        );

        return Command::SUCCESS;
    }
}
