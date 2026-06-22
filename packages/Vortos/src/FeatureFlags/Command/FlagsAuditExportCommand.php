<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Compliance\Export\AuditExportFilter;
use Vortos\FeatureFlags\Compliance\Export\AuditExportService;
use Vortos\FeatureFlags\Compliance\Export\ExportFormat;

#[AsCommand(
    name:        'vortos:flags:audit:export',
    description: 'Stream a signed audit log export (NDJSON or CSV) for compliance / SOC2 evidence',
)]
final class FlagsAuditExportCommand extends Command
{
    public function __construct(
        private readonly AuditExportService $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Export format: ndjson or csv', 'ndjson')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (stdout if omitted)')
            ->addOption('flag', null, InputOption::VALUE_REQUIRED, 'Filter by flag name')
            ->addOption('environment', 'e', InputOption::VALUE_REQUIRED, 'Filter by environment')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Filter by project ID')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Filter by actor ID')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date (ISO-8601, e.g. 2026-01-01T00:00:00Z)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date (ISO-8601)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Stream batch size (1–5000)', '500')
            ->addOption('manifest', 'm', InputOption::VALUE_REQUIRED, 'Write signed manifest JSON to this file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatStr = strtolower((string) $input->getOption('format'));
        $format    = ExportFormat::tryFrom($formatStr);

        if ($format === null) {
            $output->writeln('<error>Unknown format. Use "ndjson" or "csv".</error>');
            return Command::FAILURE;
        }

        $filter = new AuditExportFilter(
            flagName:    $input->getOption('flag'),
            environment: $input->getOption('environment'),
            projectId:   $input->getOption('project'),
            actorId:     $input->getOption('actor'),
            from:        $this->parseDate($input->getOption('from')),
            to:          $this->parseDate($input->getOption('to')),
            batchSize:   (int) $input->getOption('batch-size'),
        );

        $outputPath = $input->getOption('output');

        if ($outputPath !== null) {
            $fh   = fopen($outputPath, 'wb');
            $sink = static function (string $chunk) use ($fh): void { fwrite($fh, $chunk); };
        } else {
            $sink = static function (string $chunk) use ($output): void { $output->write($chunk); };
        }

        try {
            $manifest = $this->exporter->export($filter, $format, $sink);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Export failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        } finally {
            if ($outputPath !== null && isset($fh)) {
                fclose($fh);
            }
        }

        $manifestPath = $input->getOption('manifest');
        if ($manifestPath !== null) {
            file_put_contents($manifestPath, $manifest->toJson());
        }

        $output->writeln('');
        $output->writeln(sprintf(
            ' <info>exported</info> %d rows · content-hash: <fg=gray>%s</> · sig: <fg=gray>%.16s…</>',
            $manifest->rowCount,
            substr($manifest->contentHash, 0, 16) . '…',
            $manifest->signature,
        ));

        return Command::SUCCESS;
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
