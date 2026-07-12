<?php

declare(strict_types=1);

namespace Vortos\Migration\DependencyInjection;

use Vortos\Migration\Safety\Severity;

/**
 * Fluent configuration for the migration safety analyzer.
 *
 * Reached via VortosMigrationConfig::safety(). Every safety rule ships on-by-default
 * at its own severity; this object lets an application tune the hot-table thresholds
 * and, as a break-glass, downgrade (or raise) an individual rule by its id.
 *
 * Severity overrides are keyed by rule id, so they are driver-agnostic: today's rules
 * are namespaced `pg.*`; a future MySQL driver would register `mysql.*` rules and the
 * exact same override channel governs them with no shape change.
 *
 *   $config->safety(fn (MigrationSafetyConfig $s) => $s
 *       ->hotTableRowThreshold(250_000)
 *       ->overrideSeverity('pg.index.non-idempotent-concurrent', Severity::Warning));
 *
 * Prefer the per-migration opt-out attribute (e.g. #[AllowNonIdempotentConcurrent]) for
 * a single knowing exception; use overrideSeverity() only to change a rule fleet-wide.
 */
final class MigrationSafetyConfig
{
    private int $hotTableRowThreshold;
    private int $hotTableBytesThreshold;

    /** @var array<string, Severity> */
    private array $severityOverrides = [];

    public function __construct()
    {
        $this->hotTableRowThreshold   = \max(1, (int) ($_ENV['MIGRATION_HOT_TABLE_ROW_THRESHOLD'] ?? 100_000));
        $this->hotTableBytesThreshold = \max(1, (int) ($_ENV['MIGRATION_HOT_TABLE_BYTES_THRESHOLD'] ?? 67_108_864));
    }

    /** Row count above which a table is treated as "hot" (blocking-DDL rules escalate). */
    public function hotTableRowThreshold(int $rows): static
    {
        $this->hotTableRowThreshold = \max(1, $rows);
        return $this;
    }

    /** On-disk byte size above which a table is treated as "hot". */
    public function hotTableBytesThreshold(int $bytes): static
    {
        $this->hotTableBytesThreshold = \max(1, $bytes);
        return $this;
    }

    /**
     * Override a safety rule's severity by its id (e.g. 'pg.index.non-concurrent').
     *
     * An unknown rule id fails fast at container build via SafetyRuleSet::validateOverrides().
     */
    public function overrideSeverity(string $ruleId, Severity $severity): static
    {
        $this->severityOverrides[$ruleId] = $severity;
        return $this;
    }

    public function getHotTableRowThreshold(): int
    {
        return $this->hotTableRowThreshold;
    }

    public function getHotTableBytesThreshold(): int
    {
        return $this->hotTableBytesThreshold;
    }

    /** @return array<string, Severity> */
    public function getSeverityOverrides(): array
    {
        return $this->severityOverrides;
    }
}
