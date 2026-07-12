<?php

declare(strict_types=1);

namespace Vortos\Audit\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Audit\Storage\Dbal\Postgres\PostgresAuditExtrasInstaller;

/**
 * Installs the Postgres-only audit extras that the portable Schema-diff migrations can't
 * express: the full-text-search GIN index and (behind ->rowLevelSecurity(true)) row-level
 * security. Idempotent — safe to run on every deploy.
 *
 *   php bin/console vortos:audit:pg:install            # FTS index (+ RLS if configured on)
 *   php bin/console vortos:audit:pg:install --rls      # force-enable RLS
 *   php bin/console vortos:audit:pg:install --no-rls   # disable RLS
 */
#[AsCommand(name: 'vortos:audit:pg:install', description: 'Install Postgres audit extras (FTS GIN index + optional row-level security).')]
final class AuditPgInstallCommand extends Command
{
    public function __construct(
        private readonly ?PostgresAuditExtrasInstaller $installer,
        private readonly bool                          $rlsConfigured = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rls', null, InputOption::VALUE_NONE, 'Enable row-level security regardless of config.');
        $this->addOption('no-rls', null, InputOption::VALUE_NONE, 'Disable row-level security.');
        $this->addOption('skip-fts', null, InputOption::VALUE_NONE, 'Skip the FTS GIN index.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->installer === null) {
            $io->error('Postgres audit extras are unavailable (no Postgres connection / doctrine-dbal).');
            return Command::FAILURE;
        }

        if (!$input->getOption('skip-fts')) {
            $this->installer->installFtsIndex();
            $io->writeln('· FTS GIN index installed.');
        }

        $enableRls = $input->getOption('rls') || ($this->rlsConfigured && !$input->getOption('no-rls'));
        if ($input->getOption('no-rls')) {
            $this->installer->disableRls();
            $io->writeln('· Row-level security disabled.');
        } elseif ($enableRls) {
            $this->installer->enableRls();
            $io->writeln('· Row-level security enabled (tenant-isolation policy active).');
        } else {
            $io->writeln('· Row-level security left off (not configured).');
        }

        $io->success('Audit Postgres extras are up to date.');
        return Command::SUCCESS;
    }
}
