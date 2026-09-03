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

    public function execute(BackupSchedule $schedule): LifecycleOutcome
    {
        return match ($schedule->type) {
            BackupScheduleType::Backup => $this->runBackup($schedule),
            BackupScheduleType::Retention => $this->runRetention($schedule),
            BackupScheduleType::Drill => $this->runDrill($schedule),
        };
    }

    private function runBackup(BackupSchedule $schedule): LifecycleOutcome
    {
        $artifact = $this->backupRunner->run(new BackupRequest(
            engine: $schedule->engine,
            kind: $schedule->kind,
            environment: $schedule->environment,
        ));

        // A null artifact means exactly one thing: BackupRunner could not take the single-flight
        // lock for this engine+environment, so it deliberately did not dump. Nothing ran and nothing
        // failed — the occurrence is still owed, and reporting it as completed is what let a lost
        // race silently consume a six-hour window.
        if ($artifact === null) {
            return LifecycleOutcome::skipped(sprintf(
                'skipped: another %s/%s backup holds the single-flight lock',
                $schedule->engine->value,
                $schedule->environment,
            ));
        }

        return LifecycleOutcome::completed(sprintf('backup %s stored', $artifact->id->value));
    }

    private function runRetention(BackupSchedule $schedule): LifecycleOutcome
    {
        $plan = $this->retentionEnforcer->enforce(
            $this->stores->store($this->storeKey),
            $schedule->engine,
            $schedule->environment,
            $this->retentionPolicy,
            apply: true,
        );

        return LifecycleOutcome::completed(
            sprintf('retention applied: kept %d, deleted %d', $plan->keptTotal(), $plan->deletedTotal()),
        );
    }

    private function runDrill(BackupSchedule $schedule): LifecycleOutcome
    {
        if ($this->drillRunner === null) {
            throw new \RuntimeException('backup drill scheduled but no DrillRunner is wired (install/configure restore drills).');
        }

        // The schedule's kind is passed through rather than letting the runner pick the newest
        // restorable artifact. Two drills on two cadences prove two different things, and a runner
        // choosing for itself would take whichever backup happened to be newer — in practice always
        // the frequent logical dump, leaving the point-in-time schedule green and vacuous.
        $report = $this->drillRunner->run(
            $schedule->engine,
            $schedule->environment,
            onlyKind: $schedule->kind,
        );

        if (!$report->passed()) {
            throw new \RuntimeException(sprintf(
                'restore drill did not pass (outcome=%s%s)',
                $report->outcome,
                $report->error !== null ? ': ' . $report->error : '',
            ));
        }

        return LifecycleOutcome::completed(sprintf(
            'drill passed (%s, rto=%dms)',
            $report->kind->value ?? 'unknown kind',
            $report->rtoMs,
        ));
    }
}
