<?php

declare(strict_types=1);

namespace Vortos\Migration\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Access\DatabaseRoleConformanceInspector;
use Vortos\Migration\Access\DatabaseRoleConformanceStatus;

/**
 * Prints whether the live database holds exactly the declared role model, and every departure — names and
 * privilege words, never data.
 *
 * Exit code: 0 = conforming, 1 = violated, 2 = undeclared or indeterminate. 2 is NOT success: an unchecked role
 * set is not a least-privilege one.
 */
#[AsCommand(name: 'vortos:database:roles:check', description: 'Check the live database roles and privileges against the declared least-privilege model.')]
final class DatabaseRolesCheckCommand extends Command
{
    public function __construct(private readonly DatabaseRoleConformanceInspector $inspector)
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
            $output->writeln(sprintf('Database roles [%s]%s', $report->status->value, $report->connectionsChecked ? '' : ' (superuser connections not visible to this role)'));
            if ($report->reason !== null) {
                $output->writeln('    ' . $report->reason);
            }
            foreach ($report->violations as $violation) {
                $output->writeln(sprintf('    %s  %s  %s: %s', $violation->kind->value, $violation->role, $violation->subject, $violation->detail));
            }
        }

        return match ($report->status) {
            DatabaseRoleConformanceStatus::Conforming => Command::SUCCESS,
            DatabaseRoleConformanceStatus::Violated => Command::FAILURE,
            DatabaseRoleConformanceStatus::Undeclared, DatabaseRoleConformanceStatus::Indeterminate => 2,
        };
    }
}
