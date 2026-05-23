<?php

declare(strict_types=1);

namespace Vortos\Foundation\Command;

use Vortos\Foundation\Health\HealthRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:health',
    description: 'Check connectivity and health of all registered infrastructure dependencies',
)]
final class HealthCommand extends Command
{
    public function __construct(
        private readonly HealthRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('critical-only', null, InputOption::VALUE_NONE, 'Only run critical health checks')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $criticalOnly = (bool) $input->getOption('critical-only');
        $format       = $input->getOption('format');

        $results = $this->registry->run($criticalOnly);
        $healthy = $this->registry->isHealthy($results, criticalOnly: true);

        if ($format === 'json') {
            $data = [];
            foreach ($results as $name => $result) {
                $data[$name] = $result->toDetailedArray(includeRawErrors: true);
            }
            $output->writeln(json_encode([
                'status'  => $healthy ? 'ok' : 'degraded',
                'checks'  => $data,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $healthy ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln('<info>VORTOS HEALTH</info>');
        $output->writeln('<fg=gray>' . str_repeat('─', 60) . '</>');
        $output->writeln('');

        if (empty($results)) {
            $output->writeln('  <comment>No health checks registered.</comment>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $passed  = 0;
        $failed  = 0;
        $warned  = 0;

        foreach ($results as $result) {
            $latency = sprintf('%.1fms', $result->latencyMs);

            if ($result->healthy) {
                $status = '<info>[OK]   </>';
                $passed++;
            } elseif (!$result->critical) {
                $status = '<comment>[WARN] </>';
                $warned++;
            } else {
                $status = '<error>[FAIL] </>';
                $failed++;
            }

            $criticalTag = $result->critical ? '' : '  <fg=gray>[non-critical]</>';

            $output->writeln(sprintf(
                '  %s  %-20s  %s%s',
                $status,
                $result->name,
                $result->healthy ? "<fg=gray>{$latency}</>" : "<fg=yellow>{$latency}</>",
                $criticalTag,
            ));

            if (!$result->healthy && $result->error !== null) {
                $output->writeln(sprintf(
                    '         <fg=gray>%s</>',
                    $result->timedOut ? $result->error : mb_substr($result->error, 0, 100),
                ));
            }
        }

        $output->writeln('');
        $output->writeln('<fg=gray>' . str_repeat('─', 60) . '</>');
        $output->writeln(sprintf(
            '  <info>%d passed</>  <comment>%d warned</>  %s',
            $passed,
            $warned,
            $failed > 0 ? "<error>{$failed} failed</>" : '<info>0 failed</>',
        ));
        $output->writeln('');

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
