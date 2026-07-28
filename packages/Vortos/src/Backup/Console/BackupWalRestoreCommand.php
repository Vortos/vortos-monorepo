<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Environment\DefaultEnvironment;
use Vortos\Backup\Pitr\PostgresWalFetcher;
use Vortos\Backup\Pitr\ArchivedWalNotFoundException;

/**
 * Restores one archived WAL segment — the hook for
 * `restore_command = 'vortos backup:wal-restore %f %p --env=prod'`.
 *
 * Exit codes are a protocol here, not diagnostics. Postgres reads zero as "segment restored" and
 * non-zero as "not available", and it depends on the second to end recovery: at the end of the
 * archive it asks for the next segment, expects to be told no, and stops. So a missing segment
 * exits non-zero WITHOUT being reported as an error, while a genuine failure — unreadable store,
 * missing decryption key, short write — is loud, because that difference is the difference between
 * a completed recovery and a truncated one that looks completed.
 */
#[AsCommand(name: BackupWalRestoreCommand::NAME, description: 'Restore a single archived Postgres WAL segment (restore_command hook).')]
final class BackupWalRestoreCommand extends Command
{
    /** Single source of truth for the name, referenced by the PITR recipe generator. */
    public const NAME = 'backup:wal-restore';

    /** Postgres convention: any non-zero means "segment not available". */
    private const NOT_ARCHIVED = 1;

    public function __construct(private readonly PostgresWalFetcher $fetcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('segment', InputArgument::REQUIRED, 'WAL segment file name (Postgres %f)')
            ->addArgument('destination', InputArgument::REQUIRED, 'Absolute destination path (Postgres %p)')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Source environment', DefaultEnvironment::NAME);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $segment = (string) $input->getArgument('segment');

        try {
            $bytes = $this->fetcher->fetch(
                $segment,
                (string) $input->getArgument('destination'),
                (string) $input->getOption('env'),
            );
        } catch (ArchivedWalNotFoundException) {
            // Expected at the end of every recovery. Written to stderr at low volume so it is
            // visible when diagnosing, without being dressed up as a failure.
            if ($output->isVerbose()) {
                $output->writeln(sprintf('<comment>WAL segment %s not in archive (end of archive).</comment>', $segment));
            }

            return self::NOT_ARCHIVED;
        }

        if ($output->isVerbose()) {
            $output->writeln(sprintf('<info>Restored WAL</info> %s (%d bytes)', $segment, $bytes));
        }

        return self::SUCCESS;
    }
}
