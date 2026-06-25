<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

final readonly class TargetSchemaSnapshot
{
    /** @param array<string, TableStat> $tableStats */
    public function __construct(
        public array $tableStats,
    ) {}

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
