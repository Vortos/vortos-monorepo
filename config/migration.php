<?php

declare(strict_types=1);

use Vortos\Migration\DependencyInjection\MigrationSafetyConfig;
use Vortos\Migration\DependencyInjection\VortosMigrationConfig;
use Vortos\Migration\Safety\Severity;

/**
 * Vortos migration configuration.
 *
 * Every setting is env-defaulted — this file is optional. Env-specific overrides go in
 * config/{env}/migration.php (loaded after this base file, so they take precedence).
 */
return static function (VortosMigrationConfig $config): void {
    // Whether a batch of transactional migrations commits atomically (default true).
    // The runner is transactionality-aware: a CREATE INDEX CONCURRENTLY migration is
    // non-transactional and always runs on its own as a commit barrier, regardless.
    // $config->allOrNothing(true);

    // Lock / statement timeouts for guarded DDL (ms; statement 0 = unlimited).
    // $config->lockTimeoutMs(3000)->statementTimeoutMs(0);

    // Safety analyzer tuning.
    $config->safety(function (MigrationSafetyConfig $safety): void {
        // "Hot" table thresholds — above these, blocking-DDL rules escalate.
        // $safety->hotTableRowThreshold(100_000)->hotTableBytesThreshold(64 * 1024 * 1024);

        // Break-glass: downgrade a rule fleet-wide by id. Prefer a per-migration opt-out
        // attribute (e.g. #[AllowNonIdempotentConcurrent]) for a single known exception.
        // An unknown rule id fails fast at container build.
        // $safety->overrideSeverity('pg.index.non-idempotent-concurrent', Severity::Warning);
    });
};
