<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use Vortos\Backup\Drill\DrillReport;
use Vortos\Backup\Drill\DrillReportStoreInterface;

/** @internal */
final class InMemoryDrillReportStore implements DrillReportStoreInterface
{
    /** @var list<DrillReport> */
    public array $reports = [];

    public function save(DrillReport $report): void
    {
        $this->reports[] = $report;
    }

    public function latest(string $engine, string $environment): ?DrillReport
    {
        $matches = array_filter(
            $this->reports,
            static fn (DrillReport $r): bool => $r->engine->value === $engine && $r->environment === $environment,
        );

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (DrillReport $a, DrillReport $b): int => $b->startedAt <=> $a->startedAt);

        return $matches[0];
    }
}
