<?php

declare(strict_types=1);

namespace Vortos\Observability\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Observability\Audit\AuditExportService;
use Vortos\Observability\Audit\DeployAuditQuery;
use Vortos\Observability\Audit\ExportFormat;

/**
 * `vortos:observability:audit:export` — signed compliance export (Block 16, §3.5),
 * reusing the FeatureFlags export pattern for SOC2/ISO evidence.
 */
#[AsCommand(name: 'vortos:observability:audit:export', description: 'Stream a signed deploy-audit export (NDJSON or CSV)')]
final class AuditExportCommand extends Command
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
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Filter by environment')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Filter by actor id')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date (ISO-8601)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date (ISO-8601)')
            ->addOption('manifest', 'm', InputOption::VALUE_REQUIRED, 'Write signed manifest JSON to this file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatStr = strtolower((string) $input->getOption('format'));
        $format = ExportFormat::tryFrom($formatStr);

        if ($format === null) {
            $output->writeln('<error>Unknown format. Use "ndjson" or "csv".</error>');

            return Command::FAILURE;
        }

        $query = new DeployAuditQuery(
            env: $this->stringOrNull($input->getOption('env')),
            actorId: $this->stringOrNull($input->getOption('actor')),
            from: $this->parseDate($input->getOption('from')),
            to: $this->parseDate($input->getOption('to')),
        );

        $outputPath = $input->getOption('output');

        if (is_string($outputPath) && $outputPath !== '') {
            $fh = fopen($outputPath, 'wb');
            $sink = static function (string $chunk) use ($fh): void {
                fwrite($fh, $chunk);
            };
        } else {
            $sink = static function (string $chunk) use ($output): void {
                $output->write($chunk);
            };
        }

        try {
            $manifest = $this->exporter->export($query, $format, $sink);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Export failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        } finally {
            if (isset($fh) && is_resource($fh)) {
                fclose($fh);
            }
        }

        $manifestPath = $input->getOption('manifest');
        if (is_string($manifestPath) && $manifestPath !== '') {
            file_put_contents($manifestPath, $manifest->toJson());
        }

        $output->writeln('');
        $output->writeln(sprintf(
            ' <info>exported</info> %d rows · content-hash: <fg=gray>%s</> · sig: <fg=gray>%s</>',
            $manifest->rowCount,
            substr($manifest->contentHash, 0, 16) . '…',
            substr($manifest->signature, 0, 16) . '…',
        ));

        return Command::SUCCESS;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
