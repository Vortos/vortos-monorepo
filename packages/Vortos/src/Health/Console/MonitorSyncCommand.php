<?php

declare(strict_types=1);

namespace Vortos\Health\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Health\Monitor\MonitorDescriptorHasher;
use Vortos\Health\Monitor\SyncRecordStoreInterface;
use Vortos\Health\Uptime\MonitorDescriptorSet;
use Vortos\Health\Uptime\UptimeMonitorRegistry;

/**
 * Idempotent sync (§6.4): hashes the rendered journey, no-ops when unchanged.
 * **Dry-run is the default** — `--apply` is required to mutate the provider, so a CI
 * run can't accidentally reconfigure production monitors.
 */
#[AsCommand(
    name: 'health:monitor:sync',
    description: 'Idempotently declare/update an uptime-monitor journey with the external provider (dry-run by default)',
)]
final class MonitorSyncCommand extends Command
{
    public function __construct(
        private readonly MonitorDescriptorSet $descriptors,
        private readonly UptimeMonitorRegistry $monitors,
        private readonly SyncRecordStoreInterface $store,
        private readonly MonitorDescriptorHasher $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('journey-key', InputArgument::REQUIRED, 'The declared MonitorDescriptor key to sync')
            ->addArgument('env', InputArgument::REQUIRED, 'Target environment')
            ->addOption('driver', 'd', InputOption::VALUE_REQUIRED, 'Uptime monitor driver key', 'null')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually write to the provider (default is dry-run)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $journeyKey = (string) $input->getArgument('journey-key');
        $env = (string) $input->getArgument('env');
        $apply = (bool) $input->getOption('apply');

        if (!$this->descriptors->has($journeyKey)) {
            $output->writeln(sprintf('<error>Unknown monitor descriptor "%s".</error>', $journeyKey));

            return Command::FAILURE;
        }

        $descriptor = $this->descriptors->get($journeyKey);
        $payloadHash = $this->hasher->hash($descriptor);
        $lastHash = $this->store->lastHash($env, $journeyKey);

        if ($lastHash === $payloadHash) {
            return $this->report($output, $input, $journeyKey, 'noop', null, $payloadHash);
        }

        if (!$apply) {
            return $this->report($output, $input, $journeyKey, 'dry-run', null, $payloadHash);
        }

        $monitorId = $this->monitors->monitor((string) $input->getOption('driver'))->sync($descriptor);
        $this->store->record($env, $journeyKey, $payloadHash, $monitorId);

        return $this->report($output, $input, $journeyKey, 'applied', $monitorId, $payloadHash);
    }

    private function report(OutputInterface $output, InputInterface $input, string $journeyKey, string $outcome, ?string $monitorId, string $hash): int
    {
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'journey_key' => $journeyKey,
                'outcome' => $outcome,
                'monitor_id' => $monitorId,
                'payload_hash' => $hash,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $output->writeln(match ($outcome) {
            'noop' => sprintf('No change for "%s" — sync is a no-op.', $journeyKey),
            'dry-run' => sprintf('<comment>[dry-run]</comment> would sync "%s" (hash %s). Pass --apply to write.', $journeyKey, $hash),
            'applied' => sprintf('Synced "%s" -> monitor id "%s".', $journeyKey, (string) $monitorId),
            default => 'unknown outcome',
        });

        return Command::SUCCESS;
    }
}
