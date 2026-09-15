<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\DR\PostgresConfigDriftInspector;
use Vortos\Backup\DR\PostgresConfigDriftStatus;

/**
 * Prints whether the running PostgreSQL cluster is configured by exactly the declared file (RC-5), and every
 * departure by setting name and origin — never a value.
 *
 * Output is written, not logged: production hides info-level logs, and an operator verifying a recreate
 * must see the result.
 *
 * Exit code: 0 = clean, 1 = drifted or unverifiable (the probe pages for both), 2 = undeclared or the
 * settings could not be read. 2 is NOT success — an unchecked configuration is not a declared one.
 */
#[AsCommand(name: 'backup:postgres-config', description: 'Check the running PostgreSQL configuration against the declared config file.')]
final class BackupPostgresConfigCommand extends Command
{
    public function __construct(private readonly PostgresConfigDriftInspector $inspector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->inspector->inspect();

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($report->toDetail(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(sprintf('PostgreSQL config [%s] declared file: %s', $report->status->value, $report->declaredConfigFile ?? '(none)'));
            if ($report->reason !== null) {
                $output->writeln('    ' . $report->reason);
            }
            foreach ($report->drift as $drift) {
                $output->writeln(sprintf('    %s  %s  (%s)', $drift->kind->value, $drift->setting, $drift->origin));
            }
        }

        return match ($report->status) {
            PostgresConfigDriftStatus::Clean => Command::SUCCESS,
            PostgresConfigDriftStatus::Drifted, PostgresConfigDriftStatus::Unverifiable => Command::FAILURE,
            PostgresConfigDriftStatus::Undeclared, PostgresConfigDriftStatus::Indeterminate => 2,
        };
    }
}
