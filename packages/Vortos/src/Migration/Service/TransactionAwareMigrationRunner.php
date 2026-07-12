<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\Migrator;
use Doctrine\Migrations\MigratorConfiguration;

/**
 * Transactionality-aware migration runner.
 *
 * Doctrine's all-or-nothing mode wraps an entire plan in one transaction and rejects any
 * migration whose isTransactional() is false (MigrationConfigurationConflict). That makes
 * it impossible to run a CREATE INDEX CONCURRENTLY migration — which MUST be non-transactional
 * — through a single all-or-nothing call, even though the migrate:analyze safety gate
 * *requires* CONCURRENTLY for indexes. This runner resolves that contradiction.
 *
 * It splits an ordered plan into contiguous segments and preserves execution order:
 *
 *   - a run of consecutive transactional migrations executes as one batch, honoring the
 *     configured all-or-nothing setting (atomic — all commit or all roll back);
 *   - each non-transactional migration executes on its own with all-or-nothing disabled,
 *     forming a natural commit barrier between the transactional batches around it.
 *
 * Atomicity therefore holds within every transactional segment; a non-transactional
 * migration is an inherent boundary (Postgres cannot roll it into a surrounding
 * transaction anyway). Because each segment is migrated separately, every completed
 * migration is recorded immediately, so a failed run is safely resumable — re-running
 * continues from the first migration that did not complete.
 *
 * This is DB-agnostic: it relies only on Doctrine's per-migration isTransactional() flag,
 * not on any Postgres-specific behavior. It works for UP and DOWN plans alike, taking the
 * direction from the incoming plan.
 */
final class TransactionAwareMigrationRunner
{
    /**
     * @return int the number of migrations executed
     */
    public function run(Migrator $migrator, MigrationPlanList $plan, bool $allOrNothing): int
    {
        $direction = $plan->getDirection();
        $executed  = 0;

        /** @var list<MigrationPlan> $batch */
        $batch = [];

        $flush = function () use (&$batch, &$executed, $migrator, $allOrNothing, $direction): void {
            if ($batch === []) {
                return;
            }

            $migrator->migrate(
                new MigrationPlanList($batch, $direction),
                (new MigratorConfiguration())->setAllOrNothing($allOrNothing),
            );

            $executed += count($batch);
            $batch = [];
        };

        foreach ($plan->getItems() as $item) {
            if ($item->getMigration()->isTransactional()) {
                $batch[] = $item;
                continue;
            }

            // Non-transactional migration (e.g. CREATE INDEX CONCURRENTLY): commit the
            // pending transactional batch first, then run this one alone, never wrapped
            // in a transaction.
            $flush();

            $migrator->migrate(
                new MigrationPlanList([$item], $direction),
                (new MigratorConfiguration())->setAllOrNothing(false),
            );

            $executed++;
        }

        $flush();

        return $executed;
    }
}
