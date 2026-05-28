<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Command\Make;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Engine\GeneratorEngine;

/**
 * Generates a skeleton SES bounce handler class.
 *
 * Usage:
 *   bin/console vortos:ses:make:bounce-handler NotifySupport --context=Notification
 */
#[AsCommand(
    name:        'vortos:ses:make:bounce-handler',
    description: 'Generate a skeleton SES bounce handler.',
)]
final class MakeBounceHandlerCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Class name prefix (without "BounceHandler" suffix)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. Notification)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = (string) $input->getArgument('name');
        $context = (string) ($input->getOption('context') ?? '');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=Notification</error>');
            return Command::FAILURE;
        }

        $vars = [
            'Namespace' => "App\\{$context}",
            'ClassName' => $name,
        ];

        $output->writeln("<info>vortos:ses:make:bounce-handler</info> {$name} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Infrastructure/Email/{$name}BounceHandler.php",
            $this->engine->render('bounce-handler', $vars),
            $output,
        );

        $output->writeln('');
        $output->writeln(
            sprintf(
                '<info>%sBounceHandler</info> auto-registers via <info>#[AsBounceHandler]</info> or implement <info>BounceHandlerInterface</info> with autoconfigure enabled.',
                $name,
            ),
        );

        return Command::SUCCESS;
    }
}
