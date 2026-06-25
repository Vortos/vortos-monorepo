<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Preflight\PreflightReport;
use Vortos\Deploy\Preflight\PreflightStatus;

/**
 * 'deploy:doctor' — the fail-closed preflight, runnable by a human and by CI.
 *
 * Exit code is the contract: '0' when clear, '1' on any failure. '--json' emits the
 * versioned {@see PreflightReport} so Block 14's pipeline can gate the deploy stage
 * on a machine-readable summary. The same {@see PreflightReport::isClear()} the
 * deploy consults drives this exit code — one source, two consumers.
 */
#[AsCommand(name: 'deploy:doctor', description: 'Fail-closed deploy preflight — refuses rather than guesses')]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly PreflightContextFactory $contextFactory,
        private readonly DeployDoctor $doctor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', 'production');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit the machine-readable PreflightReport JSON (CI mode)');
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Treat warnings as failures (forward-compat; v1 has none)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $json = (bool) $input->getOption('json');
        $strict = (bool) $input->getOption('strict');

        try {
            $context = $this->contextFactory->build($env);
        } catch (\Throwable $e) {
            // Fail-closed: if we cannot even assemble the preflight context, refuse.
            if ($json) {
                $output->writeln(json_encode([
                    'schema_version' => PreflightReport::SCHEMA_VERSION,
                    'env' => $env,
                    'clear' => false,
                    'error' => $e->getMessage(),
                ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln(sprintf('<error>Preflight could not run: %s</error>', $e->getMessage()));
            }

            return Command::FAILURE;
        }

        $report = $this->doctor->run($context, $strict);

        if ($json) {
            $output->writeln($report->toJson());

            return $report->exitCode();
        }

        $this->renderHuman($report, $output);

        return $report->exitCode();
    }

    private function renderHuman(PreflightReport $report, OutputInterface $output): void
    {
        $output->writeln('<info>DEPLOY DOCTOR</info>');
        $output->writeln(sprintf('<fg=gray>env: %s</>', $report->environment));
        $output->writeln('<fg=gray>' . str_repeat('─', 60) . '</>');
        $output->writeln('');

        foreach ($report->findings as $finding) {
            $output->writeln(sprintf(
                '  %s  %-30s  %s',
                $this->badge($finding),
                $finding->id,
                $finding->summary,
            ));

            if ($finding->detail !== '') {
                $output->writeln(sprintf('         <fg=gray>%s</>', $finding->detail));
            }

            if ($finding->isFailure() && $finding->remediation !== '') {
                $output->writeln(sprintf('         <fg=gray>Fix: %s</>', $finding->remediation));
            }
        }

        $output->writeln('');
        $output->writeln('<fg=gray>' . str_repeat('─', 60) . '</>');
        $output->writeln(sprintf(
            '  <info>%d passed</>  <fg=gray>%d skipped</>  %s',
            $report->countByStatus(PreflightStatus::Pass),
            $report->countByStatus(PreflightStatus::Skip),
            $report->isClear()
                ? '<info>0 failed — CLEAR</>'
                : sprintf('<error>%d failed — REFUSED</>', $report->countByStatus(PreflightStatus::Fail)),
        ));
        $output->writeln('');
    }

    private function badge(PreflightFinding $finding): string
    {
        return match ($finding->status) {
            PreflightStatus::Pass => '<info>[OK]  </>',
            PreflightStatus::Fail => '<error>[FAIL]</>',
            PreflightStatus::Skip => '<comment>[SKIP]</>',
        };
    }
}
