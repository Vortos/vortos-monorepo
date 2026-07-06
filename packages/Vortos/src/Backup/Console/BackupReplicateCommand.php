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
use Vortos\Backup\Replication\SecondaryReplicator;
use Vortos\Backup\Environment\DefaultEnvironment;

#[AsCommand(name: 'backup:replicate', description: 'Reconcile: copy any artifact missing a secondary copy.')]
final class BackupReplicateCommand extends Command
{
    public function __construct(
        private readonly BackupCatalogReadModelInterface $catalog,
        private readonly BackupStoreRegistry $stores,
        private readonly SecondaryReplicator $replicator,
        private readonly string $storeKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Database engine: postgres|mongo', 'postgres')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', DefaultEnvironment::NAME);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $engine = DatabaseEngine::fromString((string) $input->getOption('engine'));
        $env = (string) $input->getOption('env');
        $store = $this->stores->store($this->storeKey);

        $artifacts = $this->catalog->list($engine, $env);
        $replicated = 0;
        $failed = 0;

        foreach ($artifacts as $artifact) {
            if ($artifact->secondaryStoreKey !== null) {
                continue;
            }

            $result = $this->replicator->replicate($artifact, $store);
            if ($result->success) {
                $replicated++;
            } else {
                $failed++;
                $output->writeln(sprintf('<error>Failed:</error> %s — %s', $artifact->id->value(), $result->error ?? 'unknown'));
            }
        }

        $output->writeln(sprintf('<info>Replicated:</info> %d, Failed: %d', $replicated, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
