<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

final readonly class TargetSchemaSnapshot
{
    /**
     * @param array<string, TableStat> $tableStats
     * @param int|null $serverVersionNum PostgreSQL's server_version_num (e.g. 160004), or null when
     *        the server could not be asked. Rules that would otherwise tell a human to "confirm the
     *        version by hand" read it here instead — the analyzer holds a live connection, so the
     *        version is a fact it can establish rather than a question it should delegate.
     */
    public function __construct(
        public array $tableStats,
        public ?int $serverVersionNum = null,
    ) {}

    /**
     * Whether a measurement exists for this table at all.
     *
     * Distinct from isHot(). A table with no statistic is UNKNOWN, not big: it is usually a table an
     * earlier migration in the same batch creates, so it does not exist yet when the analyzer looks.
     * Rules still fail closed on unknown, but they must not report a number they never measured —
     * "hot table (>100000 rows)" about a nine-row table is what taught people to reach for the
     * opt-out attribute reflexively.
     */
    public function hasStatFor(string $table): bool
    {
        return isset($this->tableStats[strtolower($table)]);
    }

    public function statFor(string $table): ?TableStat
    {
        return $this->tableStats[strtolower($table)] ?? null;
    }

    public function isHot(string $table, int $rowThreshold, int $bytesThreshold): bool
    {
        $stat = $this->statFor($table);

        if ($stat === null) {
            return true;
        }

        return $stat->isHot($rowThreshold, $bytesThreshold);
    }
}
