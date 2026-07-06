<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Pitr\PostgresWalArchiver;
use Vortos\Backup\Environment\DefaultEnvironment;

/**
 * Ships one Postgres WAL segment to the backup store — the hook for the host
 * `archive_command = 'vortos backup:wal-archive %p --env=prod'`.
 *
 * Exit code is meaningful to Postgres: non-zero makes Postgres retry the segment, so
 * this command must fail (non-zero) on any archiving error — never report a false
 * success that would let Postgres recycle an un-archived segment.
 */
#[AsCommand(name: 'backup:wal-archive', description: 'Archive a single Postgres WAL segment (archive_command hook).')]
final class BackupWalArchiveCommand extends Command
{
    public function __construct(private readonly PostgresWalArchiver $archiver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Absolute path to the WAL segment (Postgres %p)')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', DefaultEnvironment::NAME);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $artifact = $this->archiver->archive(
            (string) $input->getArgument('path'),
            (string) $input->getOption('env'),
        );

        $output->writeln(sprintf('<info>Archived WAL</info> %s (%d bytes)', $artifact->storeKey, $artifact->sizeBytes));

        return self::SUCCESS;
    }
}
