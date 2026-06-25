<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\DR\DrRunbookGenerator;

#[AsCommand(name: 'backup:dr-runbook', description: 'Generate a DR runbook from live config + latest drill data.')]
final class BackupDrRunbookCommand extends Command
{
    public function __construct(
        private readonly DrRunbookGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', 'prod')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (default: stdout)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $runbook = $this->generator->generate($env);

        $outputPath = $input->getOption('output');
        if ($outputPath !== null && $outputPath !== '') {
            file_put_contents((string) $outputPath, $runbook);
            $output->writeln(sprintf('<info>Runbook written to:</info> %s', $outputPath));
        } else {
            $output->write($runbook);
        }

        return self::SUCCESS;
    }
}
