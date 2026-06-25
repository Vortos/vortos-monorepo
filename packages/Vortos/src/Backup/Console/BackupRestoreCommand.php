<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Backup\Restore\RestoreRequest;

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
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', 'prod')
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

        $artifact = $artifactId !== null
            ? $this->catalog->byId((string) $artifactId)
            : $this->catalog->latest($engine, $env);

        if ($artifact === null) {
            $output->writeln('<error>No backup artifact found.</error>');

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
