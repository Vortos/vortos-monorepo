<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;

/**
 * Proves a window of migrations can go up, come back down, and go up again — by running them the way
 * `vortos:migrate` runs them.
 *
 * ## What this replaced, and why
 *
 * down-verify used to extract each migration's SQL as text and execute the strings itself. That
 * diverged from a real migration run in every way a migration can differ from its text:
 *
 *   - `addSql($sql, $params)` lost its parameters, so any migration binding a value failed with a
 *     syntax error at the placeholder — the verifier could not get past such a migration at all;
 *   - `--count=N` ran only the last N against an EMPTY database, without the schema they alter, so
 *     it failed on the first ALTER of an existing table;
 *   - `isTransactional()`, `abortIf()`, `preUp()`/`postUp()` and declared irreversibility were
 *     invisible.
 *
 * Here every step goes through Doctrine's migrator and the same TransactionAwareMigrationRunner
 * `vortos:migrate` uses: the migrations before the window are applied first, then the window runs
 * up, down, up — one version at a time, so a failure names the migration that caused it.
 *
 * Each step gets a fresh DependencyFactory. Doctrine freezes a migration instance once it has run,
 * and caches instances in its repository; a real `vortos:migrate` is a fresh process every time,
 * so running one instance up then down in the same factory would fail here for a reason no real
 * deploy could meet.
 */
final class ReversibilityVerifier
{
    public function __construct(private readonly TransactionAwareMigrationRunner $runner) {}

    /**
     * @param \Closure(): DependencyFactory $factories a NEW factory for the disposable database per call
     * @param int                           $count     how many of the latest migrations to verify; 0 for all
     */
    public function verify(\Closure $factories, int $count, ?\Closure $progress = null): ReversibilityResult
    {
        $progress ??= static function (string $message): void {};

        $factory = $factories();
        $factory->getMetadataStorage()->ensureInitialized();

        $versions = array_map(
            static fn (AvailableMigration $m): string => (string) $m->getVersion(),
            $factory->getMigrationRepository()->getMigrations()->getItems(),
        );

        if ($versions === []) {
            return ReversibilityResult::passed(0);
        }

        $windowStart   = $count > 0 && $count < count($versions) ? count($versions) - $count : 0;
        $prerequisites = array_slice($versions, 0, $windowStart);
        $window        = array_slice($versions, $windowStart);

        if ($prerequisites !== []) {
            $progress(sprintf('Applying the %d migration(s) before the window…', count($prerequisites)));
            $failure = $this->run($factories, $prerequisites, Direction::UP, 'prerequisite');
            if ($failure !== null) {
                return $failure;
            }
        }

        $progress('Phase 1: Migrating UP…');
        if (($failure = $this->run($factories, $window, Direction::UP, 'up')) !== null) {
            return $failure;
        }

        $progress('Phase 2: Rolling back DOWN…');
        if (($failure = $this->run($factories, array_reverse($window), Direction::DOWN, 'down')) !== null) {
            return $failure;
        }

        $progress('Phase 3: Re-migrating UP…');
        if (($failure = $this->run($factories, $window, Direction::UP, 're-up')) !== null) {
            return $failure;
        }

        return ReversibilityResult::passed(count($window), $window);
    }

    /**
     * @param \Closure(): DependencyFactory $factories
     * @param list<string>                  $versions  in the order to run them
     */
    private function run(\Closure $factories, array $versions, string $direction, string $phase): ?ReversibilityResult
    {
        foreach ($versions as $version) {
            $factory  = $factories();
            $planner  = $factory->getMigrationPlanCalculator();
            $migrator = $factory->getMigrator();

            try {
                $this->runner->run(
                    $migrator,
                    $planner->getPlanForVersions([new Version($version)], $direction),
                    allOrNothing: false,
                );
            } catch (IrreversibleMigration $e) {
                return ReversibilityResult::failed($phase, $version, 'declared irreversible: ' . $e->getMessage());
            } catch (\Throwable $e) {
                return ReversibilityResult::failed($phase, $version, $e->getMessage());
            }
        }

        return null;
    }
}
