<?php

declare(strict_types=1);

namespace Vortos\Backup\Service;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Backup\Catalog\BackupCatalogRepositoryInterface;
use Vortos\Backup\Catalog\RetentionCatalogInterface;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\Exception\RetentionFloorViolation;
use Vortos\Backup\Domain\ObjectLockPolicy;
use Vortos\Backup\Domain\RetentionPlan;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Port\BackupStoreInterface;

/**
 * Computes and (optionally) applies a retention plan for one engine+environment.
 * WORM-aware: objects inside their Object Lock retention window or under legal-hold
 * are excluded from the delete plan and reported as "retained (locked)".
 */
final class RetentionEnforcer
{
    public function __construct(
        private readonly RetentionCatalogInterface $readModel,
        private readonly BackupCatalogRepositoryInterface $repository,
        private readonly BackupEventSinkInterface $events,
        private readonly ClockInterface $clock,
        private readonly ?ObjectLockPolicy $lockPolicy = null,
    ) {}

    public function plan(DatabaseEngine $engine, string $environment, RetentionPolicy $policy): RetentionPlan
    {
        $restorePoints = $this->readModel->listRestorePoints($engine, $environment);

        $rpPlan = $policy->plan($restorePoints, $this->clock->now());
        $merged = $this->mergeWalPlan($rpPlan, $engine, $environment);

        if ($this->lockPolicy !== null) {
            return $this->applyLockExclusions($merged);
        }

        return $merged;
    }

    public function enforce(
        BackupStoreInterface $store,
        DatabaseEngine $engine,
        string $environment,
        RetentionPolicy $policy,
        bool $apply,
    ): RetentionPlan {
        $plan = $this->plan($engine, $environment, $policy);

        if (!$apply || $plan->isNoop()) {
            return $plan;
        }

        $refusedKeys = array_map(static fn (array $r): string => $r['artifact']->storeKey, $plan->refused);
        foreach ($plan->delete as $artifact) {
            if (in_array($artifact->storeKey, $refusedKeys, true)) {
                throw RetentionFloorViolation::forKey($artifact->storeKey);
            }
        }

        $deleted = [];
        $refused = $plan->refused;

        foreach ($plan->delete as $artifact) {
            try {
                $store->delete($artifact->storeKey);
                $this->repository->forget($artifact->id->value());
                $deleted[] = $artifact;
            } catch (Throwable $e) {
                // A store refusing to delete an immutable object is not an error and never was: it
                // is the store enforcing exactly the guarantee it was configured to provide, and it
                // said so in as many words. Retention's job is to record that and move on.
                //
                // This used to be conditional on `$this->lockPolicy !== null` — on whether the APP
                // had declared an Object Lock policy. That is backwards. Immutability is a property
                // of the bucket, discovered at the moment of the call; whether someone remembered to
                // mirror it in configuration has no bearing on what just happened. With a WORM
                // bucket and no declared policy, retention threw on the first locked object, so it
                // failed its occurrence, exhausted its retries, raised an alert, and pruned nothing
                // — daily, indefinitely. Not one line of that was true: nothing was broken.
                if ($this->isLockRejection($e)) {
                    $refused[] = ['artifact' => $artifact, 'reason' => 'retained (locked by store)'];

                    continue;
                }
                throw $e;
            }
        }

        // Count what was actually deleted, not what was planned. The old code emitted the planned
        // total even on the path that skipped locked objects, which is how a retention pass that
        // removed nothing could report a healthy number of deletions.
        $this->events->emit(BackupEvent::retentionApplied($engine, $environment, count($deleted), $this->now()));

        // The returned plan describes what happened: refused now carries anything the store declined.
        return new RetentionPlan($plan->keep, $deleted, $refused, $plan->keptWalCount);
    }

    private function applyLockExclusions(RetentionPlan $plan): RetentionPlan
    {
        $now = $this->clock->now();
        $keep = $plan->keep;
        $delete = [];
        $refused = $plan->refused;

        foreach ($plan->delete as $artifact) {
            if ($this->lockPolicy !== null && $this->lockPolicy->isWithinRetention($artifact->createdAt, $now)) {
                $keep[] = $artifact;
                $refused[] = ['artifact' => $artifact, 'reason' => 'retained (locked)'];
            } else {
                $delete[] = $artifact;
            }
        }

        return new RetentionPlan($keep, $delete, $refused, $plan->keptWalCount);
    }

    /**
     * Fold WAL retention into a restore-point plan.
     *
     * WAL retention turns on a single instant: $oldestKeptBase, the oldest retained physical base
     * backup. A segment older than that can never be replayed onto anything we still hold, so it is
     * prunable; everything at or after it is needed for PITR. With no retained base there is no
     * anchor to replay onto, and the only safe reading of that is "prune nothing" — deleting WAL on
     * the strength of an absent base is how a PITR window silently becomes unrecoverable.
     *
     * Both branches ask the catalogue for exactly the set they need. The prunable side is bounded
     * by the prune itself; the retained side is unbounded and is therefore only counted. Deriving
     * either by filtering "every artifact" in PHP is what exhausted the worker's memory limit.
     */
    private function mergeWalPlan(RetentionPlan $rpPlan, DatabaseEngine $engine, string $environment): RetentionPlan
    {
        $oldestKeptBase = null;
        foreach ($rpPlan->keep as $kept) {
            if ($kept->kind === BackupKind::PhysicalBase) {
                if ($oldestKeptBase === null || $kept->createdAt < $oldestKeptBase) {
                    $oldestKeptBase = $kept->createdAt;
                }
            }
        }

        $deleteWal = $oldestKeptBase === null
            ? []
            : $this->readModel->listWalOlderThan($engine, $environment, $oldestKeptBase);

        return new RetentionPlan(
            $rpPlan->keep,
            [...$rpPlan->delete, ...$deleteWal],
            $rpPlan->refused,
            $this->readModel->countWalFrom($engine, $environment, $oldestKeptBase),
        );
    }

    /**
     * Whether the store declined this delete because the object is immutable.
     *
     * Deliberately NOT a bare `str_contains($msg, 'lock')`, which is what this was. That also
     * matches "deadlock detected" and "lock timeout" — errors from the catalog write in the same
     * try block — so a transient database failure would have been silently recorded as an
     * immutable object and the row left orphaned in the catalog while the object survived. The
     * phrases below are specific to immutability, and "deadlock" matches none of them.
     */
    private function isLockRejection(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        foreach ([
            'locked by',        // R2: "The object is locked by the bucket policy."
            'object lock',
            'objectlock',
            'object is locked',
            'immutable',
            'worm',
            'legal hold',
            'retention period',
            'compliance mode',
            'governance mode',
        ] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
