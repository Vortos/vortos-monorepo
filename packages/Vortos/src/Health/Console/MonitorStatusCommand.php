<?php

declare(strict_types=1);

namespace Vortos\Health\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Health\Uptime\MonitorState;
use Vortos\Health\Uptime\UptimeMonitorRegistry;

#[AsCommand(
    name: 'health:monitor:status',
    description: 'Read off-host status for an external uptime monitor id',
)]
final class MonitorStatusCommand extends Command
{
    public function __construct(
        private readonly UptimeMonitorRegistry $monitors,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('monitor-id', InputArgument::REQUIRED, 'Provider monitor id (returned by health:monitor:sync)')
            ->addOption('driver', 'd', InputOption::VALUE_REQUIRED, 'Uptime monitor driver key', 'null')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $monitorId = (string) $input->getArgument('monitor-id');
        $status = $this->monitors->monitor((string) $input->getOption('driver'))->status($monitorId);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'monitor_id' => $status->monitorId,
                'state' => $status->state->value,
                'latency_ms' => $status->latencyMs,
                'last_checked_at' => $status->lastCheckedAt->format(DATE_ATOM),
                'failing_regions' => $status->failingRegions,
                'incident_id' => $status->incidentId,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $output->writeln(sprintf('%s: %s', $status->monitorId, $status->state->value));
            if ($status->latencyMs !== null) {
                $output->writeln(sprintf('  latency: %.1fms', $status->latencyMs));
            }
            if ($status->failingRegions !== []) {
                $output->writeln('  failing regions: ' . implode(', ', $status->failingRegions));
            }
            if ($status->incidentId !== null) {
                $output->writeln('  incident: ' . $status->incidentId);
            }
        }

        return $status->state === MonitorState::Down ? Command::FAILURE : Command::SUCCESS;
    }
}
