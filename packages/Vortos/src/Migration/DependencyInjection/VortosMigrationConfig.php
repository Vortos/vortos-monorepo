<?php

declare(strict_types=1);

namespace Vortos\Migration\DependencyInjection;

/**
 * Fluent configuration object for the migration module.
 *
 * Loaded via require in MigrationExtension::load(), mirroring every other vortos module
 * (VortosSchedulerConfig, VortosMetricsConfig, …). Every setting has a sensible default
 * — no config file is required for basic usage.
 *
 * Create config/migration.php in your project:
 *
 *   use Vortos\Migration\DependencyInjection\VortosMigrationConfig;
 *   use Vortos\Migration\DependencyInjection\MigrationSafetyConfig;
 *   use Vortos\Migration\Safety\Severity;
 *
 *   return static function (VortosMigrationConfig $config): void {
 *       $config
 *           ->allOrNothing(true)
 *           ->safety(fn (MigrationSafetyConfig $s) => $s
 *               ->overrideSeverity('pg.index.non-idempotent-concurrent', Severity::Warning));
 *   };
 *
 * Env-specific overrides go in config/{env}/migration.php, loaded after the base file.
 */
final class VortosMigrationConfig
{
    private bool $allOrNothing;
    private int $lockTimeoutMs;
    private int $statementTimeoutMs;
    private MigrationSafetyConfig $safety;

    public function __construct()
    {
        $this->allOrNothing       = \filter_var($_ENV['MIGRATION_ALL_OR_NOTHING'] ?? true, \FILTER_VALIDATE_BOOL);
        $this->lockTimeoutMs      = \max(0, (int) ($_ENV['MIGRATION_LOCK_TIMEOUT_MS'] ?? 3000));
        $this->statementTimeoutMs = \max(0, (int) ($_ENV['MIGRATION_STATEMENT_TIMEOUT_MS'] ?? 0));
        $this->safety             = new MigrationSafetyConfig();
    }

    /**
     * Whether a batch of transactional migrations runs atomically (all commit or all roll back).
     *
     * The runner is transactionality-aware: this setting governs runs of transactional
     * migrations only. A non-transactional migration (e.g. CREATE INDEX CONCURRENTLY) can
     * never participate in a wrapping transaction and always executes on its own, acting as
     * a commit barrier regardless of this flag.
     */
    public function allOrNothing(bool $enabled): static
    {
        $this->allOrNothing = $enabled;
        return $this;
    }

    /** Milliseconds to wait for a table lock before a guarded DDL statement aborts. */
    public function lockTimeoutMs(int $ms): static
    {
        $this->lockTimeoutMs = \max(0, $ms);
        return $this;
    }

    /** Milliseconds a single migration statement may run before Postgres cancels it (0 = unlimited). */
    public function statementTimeoutMs(int $ms): static
    {
        $this->statementTimeoutMs = \max(0, $ms);
        return $this;
    }

    /** @param callable(MigrationSafetyConfig): mixed $configure */
    public function safety(callable $configure): static
    {
        $configure($this->safety);
        return $this;
    }

    public function getAllOrNothing(): bool
    {
        return $this->allOrNothing;
    }

    public function getLockTimeoutMs(): int
    {
        return $this->lockTimeoutMs;
    }

    public function getStatementTimeoutMs(): int
    {
        return $this->statementTimeoutMs;
    }

    public function getSafety(): MigrationSafetyConfig
    {
        return $this->safety;
    }
}
