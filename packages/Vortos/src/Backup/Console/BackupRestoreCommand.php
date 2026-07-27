<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Backup\Restore\RestoreRequest;
use Vortos\Backup\Environment\DefaultEnvironment;

#[AsCommand(name: 'backup:restore', description: 'Restore a backup to a target database (operator-driven).')]
final class BackupRestoreCommand extends Command
{
    public function __construct(
        private readonly BackupCatalogReadModelInterface $catalog,
        private readonly BackupStoreRegistry $stores,
        private readonly RestoreCoordinator $coordinator,
        private readonly string $storeKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Database engine: postgres|mongo', 'postgres')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', DefaultEnvironment::NAME)
            ->addOption('artifact-id', null, InputOption::VALUE_REQUIRED, 'Specific artifact ID (default: latest)')
            ->addOption('destination', null, InputOption::VALUE_REQUIRED, 'Destination DSN')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Required to actually run the restore');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('confirm')) {
            $output->writeln('<error>--confirm is required to run a restore. This is a destructive operation.</error>');

            return self::FAILURE;
        }

        $destination = (string) $input->getOption('destination');
        if ($destination === '') {
            $output->writeln('<error>--destination DSN is required.</error>');

            return self::FAILURE;
        }

        $engine = DatabaseEngine::fromString((string) $input->getOption('engine'));
        $env = (string) $input->getOption('env');
        $artifactId = $input->getOption('artifact-id');

        // Without an explicit id, restore the newest RESTORABLE artifact — never simply the newest
        // row. With continuous WAL archiving a wal_segment lands roughly every sixty seconds, so
        // "the latest backup" is almost always a single WAL fragment that cannot be restored on its
        // own. This is the command an operator reaches for during an incident, so handing them a
        // fragment is the worst possible moment for that surprise.
        $artifact = $artifactId !== null
            ? $this->catalog->byId((string) $artifactId)
            : $this->catalog->latestOfKind(
                $engine,
                $env,
                [BackupKind::LogicalFull, BackupKind::PhysicalBase, BackupKind::MongoArchive],
            );

        if ($artifact === null) {
            $output->writeln('<error>No restorable backup artifact found. WAL segments alone cannot '
                . 'be restored — they replay onto a base backup.</error>');

            return self::FAILURE;
        }

        $store = $this->stores->store($this->storeKey);

        $this->coordinator->restore($artifact, $store, new RestoreRequest($destination));

        $output->writeln(sprintf(
            '<info>Restore complete:</info> %s → %s',
            $artifact->id->value(),
            $destination,
        ));

        return self::SUCCESS;
    }
}
