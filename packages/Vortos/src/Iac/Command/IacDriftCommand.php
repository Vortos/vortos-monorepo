<?php

declare(strict_types=1);

namespace Vortos\Iac\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Iac\Lifecycle\IacDriftAuditorInterface;

#[AsCommand(
    name: 'vortos:iac:drift',
    description: 'Detect infrastructure drift (refresh-only plan). Exit 1 on drift.',
)]
final class IacDriftCommand extends Command
{
    public function __construct(
        private readonly IacDriftAuditorInterface $auditor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Target environment', 'dev')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output drift report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = (string) $input->getOption('env');

        $report = $this->auditor->audit($env);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'has_drift' => $report->hasDrift,
                'unreachable' => $report->unreachable,
                'summary' => $report->summary,
            ], JSON_PRETTY_PRINT));

            return $report->hasDrift || $report->unreachable ? 1 : Command::SUCCESS;
        }

        $io->writeln($report->summary);

        if ($report->unreachable) {
            $io->warning('Target was unreachable — drift check incomplete.');
            return 1;
        }

        if ($report->hasDrift) {
            $io->error('Infrastructure drift detected.');
            return 1;
        }

        $io->success('No infrastructure drift detected.');
        return Command::SUCCESS;
    }
}
