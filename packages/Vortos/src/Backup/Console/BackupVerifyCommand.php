<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Domain\Exception\IntegrityException;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Service\IntegrityVerifier;

/** Re-verify an existing cataloged backup by reading it back from the store. */
#[AsCommand(name: 'backup:verify', description: 'Verify the integrity of a cataloged backup.')]
final class BackupVerifyCommand extends Command
{
    public function __construct(
        private readonly BackupCatalogReadModelInterface $catalog,
        private readonly BackupStoreRegistry $stores,
        private readonly IntegrityVerifier $verifier,
        private readonly string $storeKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Backup id to verify')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        $artifact = $this->catalog->byId($id);

        if ($artifact === null) {
            $output->writeln(sprintf('<error>No cataloged backup with id %s</error>', $id));

            return self::FAILURE;
        }

        $store = $this->stores->store($this->storeKey);

        try {
            $this->verifier->verify(
                $store,
                $artifact->storeKey,
                $artifact->checksum,
                $artifact->engine,
                $artifact->kind,
                $artifact->codec,
            );
        } catch (IntegrityException $e) {
            $output->writeln('<error>FAILED: ' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf('<info>OK</info> %s verified (%s).', $id, (string) $artifact->checksum));

        return self::SUCCESS;
    }
}
