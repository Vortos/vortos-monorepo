<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Drill\DrillRunner;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Backup\Service\BackupRunner;
use Vortos\Backup\Service\RetentionEnforcer;

/**
 * The framework-owned bridge from a declared {@see BackupSchedule} to the concrete lifecycle service —
 * so the app never hand-writes a #[Scheduled] class or a CommandBus payload (R8-6 A8/A9). Each type
 * dispatches to the same service the equivalent console command uses, so worker-run and hand-run agree.
 */
final class BackupLifecycleRunner implements BackupLifecycleRunnerInterface
{
    public function __construct(
        private readonly BackupRunner $backupRunner,
        private readonly RetentionEnforcer $retentionEnforcer,
        private readonly BackupStoreRegistry $stores,
        private readonly RetentionPolicy $retentionPolicy,
        private readonly string $storeKey,
        private readonly ?DrillRunner $drillRunner = null,
    ) {
    }

    public function execute(BackupSchedule $schedule): string
    {
        return match ($schedule->type) {
            BackupScheduleType::Backup => $this->runBackup($schedule),
            BackupScheduleType::Retention => $this->runRetention($schedule),
            BackupScheduleType::Drill => $this->runDrill($schedule),
        };
    }

    private function runBackup(BackupSchedule $schedule): string
    {
        $artifact = $this->backupRunner->run(new BackupRequest(
            engine: $schedule->engine,
            kind: $schedule->kind,
            environment: $schedule->environment,
        ));

        return $artifact === null
            ? 'backup produced no artifact'
            : sprintf('backup %s stored', $artifact->id->value);
    }

    private function runRetention(BackupSchedule $schedule): string
    {
        $plan = $this->retentionEnforcer->enforce(
            $this->stores->store($this->storeKey),
            $schedule->engine,
            $schedule->environment,
            $this->retentionPolicy,
            apply: true,
        );

        return sprintf('retention applied: kept %d, deleted %d', count($plan->keep), count($plan->delete));
    }

    private function runDrill(BackupSchedule $schedule): string
    {
        if ($this->drillRunner === null) {
            throw new \RuntimeException('backup drill scheduled but no DrillRunner is wired (install/configure restore drills).');
        }

        $report = $this->drillRunner->run($schedule->engine, $schedule->environment);

        if (!$report->passed()) {
            throw new \RuntimeException(sprintf(
                'restore drill did not pass (outcome=%s%s)',
                $report->outcome,
                $report->error !== null ? ': ' . $report->error : '',
            ));
        }

        return sprintf('drill passed (rto=%dms)', $report->rtoMs);
    }
}
