<?php

declare(strict_types=1);

namespace Vortos\Health\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Health\Monitor\MonitorTick;
use Vortos\Health\Probe\HealthProbeRegistry;

/**
 * Cron/systemd-timer-driven scheduler tick (§3): emits the dead-man heartbeat (if
 * Observability is wired), polls the external uptime monitor, and samples
 * capacity/cert probes — never the request path (§6.6).
 */
#[AsCommand(
    name: 'health:monitor:tick',
    description: 'Run one external-uptime + capacity/cert detector tick',
)]
final class MonitorTickCommand extends Command
{
    /** @var list<string> */
    private const DEFAULT_PROBE_NAMES = ['disk-capacity', 'memory-capacity', 'cpu-load', 'cert-expiry'];

    /** @param list<string> $defaultMonitorIds */
    public function __construct(
        private readonly MonitorTick $tick,
        private readonly HealthProbeRegistry $probes,
        private readonly array $defaultMonitorIds = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('monitor-id', 'm', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Off-host monitor id to poll (repeatable)', [])
            ->addOption('probe', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Health probe name to sample (repeatable; defaults to the registered capacity/cert probes)', [])
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $monitorIds */
        $monitorIds = $input->getOption('monitor-id');
        if ($monitorIds === []) {
            $monitorIds = $this->defaultMonitorIds;
        }

        /** @var list<string> $probeNames */
        $probeNames = $input->getOption('probe');
        if ($probeNames === []) {
            $probeNames = array_values(array_filter(self::DEFAULT_PROBE_NAMES, $this->probes->has(...)));
        }

        $probes = array_map($this->probes->probe(...), $probeNames);
        $report = $this->tick->run($monitorIds, $probes);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($this->toArray($report), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->renderHuman($output, $report);
        }

        return $report->isHealthy() ? Command::SUCCESS : Command::FAILURE;
    }

    private function renderHuman(OutputInterface $output, \Vortos\Health\Monitor\MonitorTickReport $report): void
    {
        foreach ($report->monitorStatuses as $status) {
            $output->writeln(sprintf('monitor %s: %s', $status->monitorId, $status->state->value));
        }
        foreach ($report->probeResults as $result) {
            $output->writeln(sprintf('probe %s: %s', $result->name, $result->status->value));
        }
        foreach ($report->probeErrors as $name => $message) {
            $output->writeln(sprintf('<error>probe %s errored: %s</error>', $name, $message));
        }
        if ($report->heartbeatPing !== null) {
            $output->writeln(sprintf('heartbeat: %s (acknowledged: %s)', $report->heartbeatPing->status->value, $report->heartbeatAcknowledged === true ? 'yes' : 'no'));
        }
        $output->writeln($report->isHealthy() ? 'OK' : 'UNHEALTHY');
    }

    /** @return array<string, mixed> */
    private function toArray(\Vortos\Health\Monitor\MonitorTickReport $report): array
    {
        return [
            'healthy' => $report->isHealthy(),
            'monitor_statuses' => array_map(static fn ($s) => [
                'monitor_id' => $s->monitorId,
                'state' => $s->state->value,
                'latency_ms' => $s->latencyMs,
                'failing_regions' => $s->failingRegions,
                'incident_id' => $s->incidentId,
            ], $report->monitorStatuses),
            'probe_results' => array_map(static fn ($r) => $r->toDetailedArray(), $report->probeResults),
            'probe_errors' => $report->probeErrors,
            'heartbeat' => $report->heartbeatPing === null ? null : [
                'status' => $report->heartbeatPing->status->value,
                'acknowledged' => $report->heartbeatAcknowledged,
            ],
        ];
    }
}
