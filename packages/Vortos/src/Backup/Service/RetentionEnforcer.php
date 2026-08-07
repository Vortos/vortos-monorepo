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

        foreach ($plan->delete as $artifact) {
            try {
                $store->delete($artifact->storeKey);
                $this->repository->forget($artifact->id->value());
            } catch (Throwable $e) {
                if ($this->lockPolicy !== null && $this->isLockRejection($e)) {
                    continue;
                }
                throw $e;
            }
        }

        $this->events->emit(BackupEvent::retentionApplied($engine, $environment, count($plan->delete), $this->now()));

        return $plan;
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

    private function isLockRejection(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'lock') || str_contains($msg, 'immutable') || str_contains($msg, 'worm');
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
