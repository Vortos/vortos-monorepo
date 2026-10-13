<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\DrillReportStoreInterface;
use Vortos\Backup\Environment\DefaultEnvironment;
use Vortos\Backup\PgBackRest\PgBackRestRepositoryInspector;
use Vortos\Backup\PgBackRest\PgBackRestRepositoryStatus;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Backup\Service\RetentionEnforcer;

/**
 * Retires the framework's physical point-in-time recovery once pgBackRest has replaced it: deletes every
 * physical_base restore point and every archived WAL segment. Logical dumps are untouched.
 *
 * **Dry-run is the default** — deletion is irreversible. Even with `--apply` it refuses unless the
 * replacement is proven: the framework's own base backup and point-in-time drill are no longer scheduled
 * (or new ones would keep arriving), the pgBackRest repository is healthy, and the newest point-in-time
 * drill restored. Prints JSON, because production hides info logs.
 */
#[AsCommand(name: 'backup:pitr:retire', description: 'Delete the framework physical base backups and WAL archive after the pgBackRest cutover (dry-run unless --apply).')]
final class BackupPitrRetireCommand extends Command
{
    public function __construct(
        private readonly RetentionEnforcer $enforcer,
        private readonly BackupStoreRegistry $stores,
        private readonly string $storeKey,
        private readonly BackupScheduleRegistry $schedules,
        private readonly PgBackRestRepositoryInspector $repository,
        private readonly DrillReportStoreInterface $drills,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', DefaultEnvironment::NAME)
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete (default is a dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $apply = (bool) $input->getOption('apply');

        $refusals = $this->refusals($env);
        if ($apply && $refusals !== []) {
            $output->writeln((string) json_encode(['applied' => false, 'refused' => $refusals], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $result = $this->enforcer->retirePhysicalRecovery($this->stores->store($this->storeKey), DatabaseEngine::Postgres, $env, $apply);

        $output->writeln((string) json_encode(
            $result->toArray() + ($apply ? [] : ['would_refuse' => $refusals]),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return self::SUCCESS;
    }

    /** @return list<string> every reason deleting now would be unsafe */
    private function refusals(string $env): array
    {
        $refusals = [];

        foreach ($this->schedules as $schedule) {
            $framework = ($schedule->type === BackupScheduleType::Backup || $schedule->type === BackupScheduleType::Drill)
                && $schedule->kind === BackupKind::PhysicalBase;
            if ($framework) {
                $refusals[] = sprintf('"%s" still schedules a framework physical_base %s; remove it from config/backup.php first', $schedule->name, $schedule->type->value);
            }
        }

        $assessment = $this->repository->assess();
        if ($assessment->status !== PgBackRestRepositoryStatus::Healthy) {
            $refusals[] = sprintf('the pgBackRest repository is %s%s', $assessment->status->value, $assessment->problems === [] ? '' : ': ' . implode('; ', $assessment->problems));
        }

        $drill = $this->drills->latestOfKind(DatabaseEngine::Postgres->value, $env, BackupKind::PhysicalBase);
        if ($drill === null || !$drill->restored()) {
            $refusals[] = $drill === null
                ? 'no point-in-time drill has ever run'
                : sprintf('the newest point-in-time drill (%s) did not restore', $drill->id);
        }

        return $refusals;
    }
}
