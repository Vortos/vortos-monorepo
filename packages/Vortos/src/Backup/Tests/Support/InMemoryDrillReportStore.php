<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use Vortos\Backup\Domain\BackupKind;
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
        return $this->newest(static fn (DrillReport $r): bool => true, $engine, $environment);
    }

    public function latestOfKind(string $engine, string $environment, BackupKind $kind): ?DrillReport
    {
        return $this->newest(static fn (DrillReport $r): bool => $r->kind === $kind, $engine, $environment);
    }

    /** @param callable(DrillReport): bool $extra */
    private function newest(callable $extra, string $engine, string $environment): ?DrillReport
    {
        $matches = array_filter(
            $this->reports,
            static fn (DrillReport $r): bool => $r->engine->value === $engine
                && $r->environment === $environment
                && $extra($r),
        );

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (DrillReport $a, DrillReport $b): int => $b->startedAt <=> $a->startedAt);

        return array_values($matches)[0];
    }
}
