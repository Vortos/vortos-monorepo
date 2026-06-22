<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestProcessor;

#[AsCommand(
    name: 'vortos:flags:change-requests:process',
    description: 'Apply due scheduled change requests and expire stale ones (Block 14 sweeper)',
)]
final class FlagsProcessChangeRequestsCommand extends Command
{
    public function __construct(
        private readonly ChangeRequestProcessor $processor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'actor',
            null,
            InputOption::VALUE_REQUIRED,
            'Actor id recorded as the applier of system-processed changes',
            'system',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = (string) $input->getOption('actor');

        $applied = $this->processor->processScheduledApplications($actor);
        $expired = $this->processor->processExpired();

        $output->writeln(sprintf('  <info>applied:</info> %d due change request(s)', $applied));
        $output->writeln(sprintf('  <info>expired:</info> %d stale change request(s)', $expired));

        return Command::SUCCESS;
    }
}
