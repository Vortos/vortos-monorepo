<?php

declare(strict_types=1);

namespace Vortos\Logger\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enforces retention (age, file count, total size) across all rotating file
 * sinks. RotatingFileHandler only enforces `maxFiles` for files it itself
 * rotates during the current process lifetime — this command sweeps each
 * sink's directory so age- and size-based limits are enforced regardless of
 * process lifecycle (cron-friendly).
 *
 * ## Usage
 *
 *   php bin/console vortos:logs:prune
 *   php bin/console vortos:logs:prune --dry-run
 */
#[AsCommand(
    name: 'vortos:logs:prune',
    description: 'Prune rotated log files beyond the configured retention limits',
)]
final class LogPruneCommand extends Command
{
    /**
     * @param list<array{sink: string, dir: string, filename: string, maxFiles: int, maxAgeDays: int, maxTotalSizeMb: int, compress: bool}> $fileSinks
     */
    public function __construct(private array $fileSinks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List files that would be deleted without deleting them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($this->fileSinks === []) {
            $io->info('No rotating file sinks are configured.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($this->fileSinks as $sink) {
            $deleted = $this->pruneSink($sink, $dryRun);

            foreach ($deleted as [$file, $reason]) {
                $rows[] = [$sink['sink'], $file, $reason];
            }
        }

        if ($rows === []) {
            $io->success('Nothing to prune — all log files are within retention limits.');

            return Command::SUCCESS;
        }

        $io->table(['Sink', 'File', 'Reason'], $rows);

        if ($dryRun) {
            $io->note(sprintf('%d file(s) would be deleted (dry run).', count($rows)));
        } else {
            $io->success(sprintf('%d file(s) deleted.', count($rows)));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array{sink: string, dir: string, filename: string, maxFiles: int, maxAgeDays: int, maxTotalSizeMb: int, compress: bool} $sink
     *
     * @return list<array{0: string, 1: string}> [path, reason] pairs of files removed (or that would be removed)
     */
    private function pruneSink(array $sink, bool $dryRun): array
    {
        if (!is_dir($sink['dir'])) {
            return [];
        }

        $base = pathinfo($sink['filename'], PATHINFO_FILENAME);
        $ext  = pathinfo($sink['filename'], PATHINFO_EXTENSION);

        $candidates = array_merge(
            glob($sink['dir'] . '/' . $base . '-*.' . $ext) ?: [],
            glob($sink['dir'] . '/' . $base . '-*.' . $ext . '.gz') ?: [],
        );

        $files = [];
        foreach ($candidates as $path) {
            $mtime = filemtime($path);
            if ($mtime === false) {
                continue;
            }

            $files[] = ['path' => $path, 'mtime' => $mtime, 'size' => filesize($path) ?: 0];
        }

        // Newest first.
        usort($files, static fn (array $a, array $b): int => $b['mtime'] - $a['mtime']);

        $toDelete = [];
        $ageThreshold = time() - ($sink['maxAgeDays'] * 86400);

        foreach ($files as $index => $file) {
            if ($file['mtime'] < $ageThreshold) {
                $toDelete[$file['path']] = sprintf('older than %d days', $sink['maxAgeDays']);

                continue;
            }

            if ($index >= $sink['maxFiles']) {
                $toDelete[$file['path']] = sprintf('exceeds max file count (%d)', $sink['maxFiles']);
            }
        }

        $remaining = array_values(array_filter($files, static fn (array $f): bool => !isset($toDelete[$f['path']])));
        $totalBytes = array_sum(array_column($remaining, 'size'));
        $maxBytes = $sink['maxTotalSizeMb'] * 1024 * 1024;

        if ($totalBytes > $maxBytes) {
            // Oldest-first within the remaining set.
            $oldestFirst = array_reverse($remaining);
            foreach ($oldestFirst as $file) {
                if ($totalBytes <= $maxBytes) {
                    break;
                }

                $toDelete[$file['path']] = sprintf('exceeds max total size (%d MB)', $sink['maxTotalSizeMb']);
                $totalBytes -= $file['size'];
            }
        }

        $deleted = [];
        foreach ($toDelete as $path => $reason) {
            if (!$dryRun) {
                unlink($path);
            }

            $deleted[] = [$path, $reason];
        }

        return $deleted;
    }
}
