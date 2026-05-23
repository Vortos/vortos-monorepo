<?php

declare(strict_types=1);

namespace Vortos\Foundation\Command;

use Vortos\Foundation\Doctor\DoctorRegistry;
use Vortos\Foundation\Doctor\DoctorStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:doctor',
    description: 'Run pre-flight diagnostics — check configuration, env vars, and module health',
)]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly DoctorRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fail-on-warning', null, InputOption::VALUE_NONE, 'Exit non-zero on warnings as well as errors');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failOnWarning = (bool) $input->getOption('fail-on-warning');

        $output->writeln('<info>VORTOS DOCTOR</info>');
        $output->writeln('<fg=gray>' . str_repeat('─', 60) . '</>');
        $output->writeln('');

        if (!$this->registry->hasChecks()) {
            $output->writeln('  <comment>No doctor checks registered.</comment>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $results  = $this->registry->run();
        $passed   = 0;
        $warnings = 0;
        $errors   = 0;

        foreach ($results as $result) {
            match ($result->status) {
                DoctorStatus::Ok      => $passed++,
                DoctorStatus::Warning => $warnings++,
                DoctorStatus::Error   => $errors++,
            };

            $badge = match ($result->status) {
                DoctorStatus::Ok      => '<info>[OK]   </>',
                DoctorStatus::Warning => '<comment>[WARN] </>',
                DoctorStatus::Error   => '<error>[ERROR]</>',
            };

            $output->writeln(sprintf(
                '  %s  %-30s  %s',
                $badge,
                $result->name,
                $result->summary,
            ));

            if ($result->fix !== null && $result->status !== DoctorStatus::Ok) {
                $output->writeln(sprintf(
                    '         <fg=gray>Fix: %s</>',
                    $result->fix,
                ));
            }
        }

        $output->writeln('');
        $output->writeln('<fg=gray>' . str_repeat('─', 60) . '</>');
        $output->writeln(sprintf(
            '  <info>%d passed</>  <comment>%d warned</>  %s',
            $passed,
            $warnings,
            $errors > 0 ? "<error>{$errors} failed</>" : '<info>0 failed</>',
        ));
        $output->writeln('');

        if ($errors > 0) {
            return Command::FAILURE;
        }

        if ($failOnWarning && $warnings > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
