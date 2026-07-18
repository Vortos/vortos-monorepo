<?php

declare(strict_types=1);

namespace Vortos\Search\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Search\Index\Dbal\Postgres\PostgresSearchExtrasInstaller;

/**
 * Installs the Postgres-only search extras that the portable Schema-diff migration can't
 * express: the generated `search_vector` tsvector column + GIN index, the pg_trgm trigram
 * index, and (behind ->rowLevelSecurity(true)) tenant row-level security. Idempotent — safe to
 * run on every deploy, and only needed with the Postgres FTS driver.
 *
 *   php bin/console vortos:search:pg:install           # vector + trigram (+ RLS if configured)
 *   php bin/console vortos:search:pg:install --rls      # force-enable RLS
 *   php bin/console vortos:search:pg:install --no-rls   # disable RLS
 */
#[AsCommand(name: 'vortos:search:pg:install', description: 'Install Postgres search extras (tsvector + GIN + trigram + optional RLS).')]
final class SearchPgInstallCommand extends Command
{
    public function __construct(
        private readonly ?PostgresSearchExtrasInstaller $installer,
        private readonly bool $rlsConfigured = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rls', null, InputOption::VALUE_NONE, 'Enable row-level security regardless of config.');
        $this->addOption('no-rls', null, InputOption::VALUE_NONE, 'Disable row-level security.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->installer === null || !$this->installer->isPostgres()) {
            $io->warning('Not on Postgres (or doctrine-dbal missing) — nothing to install; the portable driver needs no extras.');
            return Command::SUCCESS;
        }

        $this->installer->installVectorColumn();
        $io->writeln('· search_vector generated column + GIN index installed.');

        $this->installer->installTrigram();
        $io->writeln('· pg_trgm extension + keywords trigram index installed.');

        if ($input->getOption('no-rls')) {
            $this->installer->disableRls();
            $io->writeln('· Row-level security disabled.');
        } elseif ($input->getOption('rls') || $this->rlsConfigured) {
            $this->installer->enableRls();
            $io->writeln('· Row-level security enabled (tenant-isolation policy active).');
        } else {
            $io->writeln('· Row-level security left off (not configured).');
        }

        $io->success('Search Postgres extras are up to date.');
        return Command::SUCCESS;
    }
}
