<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\DrillRunner;
use Vortos\Backup\Environment\DefaultEnvironment;

#[AsCommand(name: 'backup:drill', description: 'Run a restore drill (provision → restore → invariants → teardown).')]
final class BackupDrillCommand extends Command
{
    public function __construct(private readonly ?DrillRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Database engine: postgres|mongo', 'postgres')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', DefaultEnvironment::NAME)
            ->addOption('shallow', null, InputOption::VALUE_NONE, 'Shallow decrypt-verify only (no full restore)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runner === null) {
            $output->writeln('<error>Restore drills are not configured. Set VORTOS_BACKUP_DRILL_DSN '
                . 'to an ephemeral-database endpoint to enable backup:drill, then re-run.</error>');

            return self::FAILURE;
        }

        $engine = DatabaseEngine::fromString((string) $input->getOption('engine'));
        $env = (string) $input->getOption('env');
        $shallow = (bool) $input->getOption('shallow');

        $report = $this->runner->run($engine, $env, $shallow);

        if ($report->passed()) {
            $output->writeln(sprintf(
                '<info>Drill passed:</info> %s/%s — RTO %dms',
                $engine->value,
                $env,
                $report->rtoMs,
            ));

            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>Drill FAILED:</error> %s/%s — %s',
            $engine->value,
            $env,
            $report->error ?? 'invariant failure',
        ));

        foreach ($report->invariants as $result) {
            $status = $result->passed ? '<info>✓</info>' : '<error>✗</error>';
            $output->writeln(sprintf('  %s %s: %s', $status, $result->name, $result->detail));
        }

        return self::FAILURE;
    }
}
