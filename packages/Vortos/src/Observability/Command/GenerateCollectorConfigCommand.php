<?php

declare(strict_types=1);

namespace Vortos\Observability\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Observability\Collector\CollectorBufferPolicy;
use Vortos\Observability\Collector\CollectorConfigPublisher;
use Vortos\OpsKit\Driver\Exception\UnknownDriverException;

/**
 * Generates the OpenTelemetry Collector sidecar config + compose fragment for the
 * selected metrics sink. The sink is chosen by its driver key (no backend name in
 * the command), so swapping backends is a flag change.
 */
#[AsCommand(
    name: 'vortos:observability:collector',
    description: 'Generate the OpenTelemetry Collector sidecar config for the selected metrics sink',
)]
final class GenerateCollectorConfigCommand extends Command
{
    public function __construct(
        private readonly CollectorConfigPublisher $publisher,
        private readonly string $defaultSink,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sink', null, InputOption::VALUE_REQUIRED, 'Metrics sink driver key (e.g. grafana, null)', $this->defaultSink)
            ->addOption('storage-dir', null, InputOption::VALUE_REQUIRED, 'Collector persistent-queue directory', '/var/lib/otelcol/storage')
            ->addOption('memory-limit-mib', null, InputOption::VALUE_REQUIRED, 'Collector memory_limiter hard cap (MiB)', '256')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sink = (string) $input->getOption('sink');
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry run - no files will be written.');
        }

        try {
            $policy = new CollectorBufferPolicy(
                storageDir: (string) $input->getOption('storage-dir'),
                memoryLimitMib: max(32, (int) $input->getOption('memory-limit-mib')),
            );
            $result = $this->publisher->publish((string) getcwd(), $sink, $policy, $force, $dryRun);
        } catch (UnknownDriverException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($result->written !== []) {
            $io->success(sprintf('%d collector asset(s) %s:', count($result->written), $dryRun ? 'would be written' : 'written'));
            $io->listing($result->written);
        }
        if ($result->skipped !== []) {
            $io->section('Skipped (already up to date or present - use --force)');
            $io->listing($result->skipped);
        }
        if (!$dryRun && $result->written !== []) {
            $io->note('Mount otel-collector-config.yaml into the collector sidecar. Backend credentials are resolved at runtime via ${env:...} - never commit them.');
        }

        return Command::SUCCESS;
    }
}
