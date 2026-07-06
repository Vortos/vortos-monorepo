<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\EngineResolver;
use Vortos\Backup\Service\BackupRunner;

/**
 * Takes one backup. The verb is identical locally and in CI / host cron — the
 * scheduled fragment ({@see \Vortos\Backup\Schedule\CronFragmentGenerator}) invokes
 * exactly this.
 */
#[AsCommand(name: 'backup:run', description: 'Run a database backup (dump → store → verify → catalog).')]
final class BackupRunCommand extends Command
{
    public function __construct(
        private readonly BackupRunner $runner,
        private readonly EngineResolver $engineResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Database engine: postgres|mongo (defaults to VORTOS_BACKUP_ENGINE)')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Backup kind: logical_full|physical_base|mongo_archive', 'logical_full')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', 'prod')
            ->addOption('from-replica', null, InputOption::VALUE_NONE, 'Source the dump from the read replica/secondary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $engine = $this->engineResolver->resolve($this->optionalEngine($input->getOption('engine')));
        $kind = BackupKind::from((string) $input->getOption('kind'));
        $env = (string) $input->getOption('env');

        $request = new BackupRequest(
            $engine,
            $kind,
            $env,
            fromReplica: (bool) $input->getOption('from-replica'),
        );

        $artifact = $this->runner->run($request);

        if ($artifact === null) {
            $output->writeln('<comment>Skipped: a backup of this scope is already in progress.</comment>');

            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Backup complete:</info> %s (%d bytes, %s)',
            $artifact->id->value(),
            $artifact->sizeBytes,
            (string) $artifact->checksum,
        ));

        return self::SUCCESS;
    }

    private function optionalEngine(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
