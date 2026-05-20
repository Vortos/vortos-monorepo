<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\Migrations\Metadata\MigrationPlanList;
use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Schema\MigrationPlanItemAnalysis;
use Vortos\Migration\Service\MigrationRawInspectorInterface;
use Vortos\Migration\Service\MigrationSqlExtractorInterface;

/**
 * Analyses every migration in a pending plan before execution.
 *
 * For each item it produces a MigrationPlanItemAnalysis that MigrateCommand
 * uses to decide whether to run, auto-adopt, or block with guidance.
 *
 * Strategy per migration:
 *   - Module migrations (in manifest) → MigrationDriftDetector → convert result
 *   - App migrations                  → extract SQL from class file, check DB
 */
final class MigrationPlanAnalyzer
{
    public function __construct(
        private readonly MigrationRawInspectorInterface $inspector,
        private readonly MigrationSqlExtractorInterface $extractor,
        private readonly MigrationSqlParser $parser,
        private readonly ModuleMigrationRegistry $moduleRegistry,
        private readonly MigrationDriftDetector $driftDetector,
    ) {
    }

    /**
     * @return array<string, MigrationPlanItemAnalysis>  keyed by version string
     */
    public function analyze(MigrationPlanList $plan): array
    {
        $descriptors = $this->moduleRegistry->descriptorsByClass();
        $results     = [];

        foreach ($plan->getItems() as $item) {
            $version = (string) $item->getVersion();

            $results[$version] = isset($descriptors[$version])
                ? $this->fromDriftReport($this->driftDetector->detect($descriptors[$version]))
                : $this->analyzeAppMigration($version);
        }

        return $results;
    }

    private function analyzeAppMigration(string $className): MigrationPlanItemAnalysis
    {
        $sqlStrings = $this->extractor->extractFromClass($className);

        if ($sqlStrings === []) {
            return new MigrationPlanItemAnalysis(MigrationPlanItemAnalysis::Unknown);
        }

        $allSql  = implode("\n", $sqlStrings);
        $tables  = $this->parser->parseTables($allSql);
        $indexes = $this->parser->parseIndexes($allSql);
        $addCols = $this->parser->parseAddColumns($allSql);

        // All objects guarded by IF NOT EXISTS and no column additions → always safe to run
        $allSafe = $addCols === []
            && array_reduce($tables,  fn(bool $c, array $t) => $c && $t['ifNotExists'], true)
            && array_reduce($indexes, fn(bool $c, array $i) => $c && $i['ifNotExists'], true);

        if ($allSafe) {
            return new MigrationPlanItemAnalysis(MigrationPlanItemAnalysis::Safe);
        }

        // Check which non-IF-NOT-EXISTS tables exist in the DB
        $existingTables = [];
        $missingTables  = [];

        foreach ($tables as $t) {
            if ($t['ifNotExists']) {
                continue;
            }

            if ($this->inspector->tableExistsRaw($t['name'])) {
                $existingTables[] = $t['name'];
            } else {
                $missingTables[] = $t['name'];
            }
        }

        // For ALTER TABLE ADD COLUMN: a column that ALREADY EXISTS means the statement will fail
        // (needs adoption). A column that DOESN'T exist means the statement will succeed (run normally).
        $failingCols  = []; // columns that exist → ADD COLUMN would fail → adoption needed
        $pendingCols  = []; // columns that don't exist → ADD COLUMN will succeed → run normally

        foreach ($addCols as $ac) {
            if ($this->inspector->columnExistsRaw($ac['table'], $ac['column'])) {
                $failingCols[$ac['table']][] = $ac['column'];
            } else {
                $pendingCols[$ac['table']][] = $ac['column'];
            }
        }

        $wouldFail   = $existingTables !== [] || $failingCols !== [];
        $wouldSucceed = $missingTables  !== [] || $pendingCols  !== [];

        // Nothing would fail → run the migration as-is
        if (!$wouldFail) {
            return new MigrationPlanItemAnalysis(MigrationPlanItemAnalysis::Clean, missingTables: $missingTables);
        }

        // Everything would fail (all objects already exist) → safe to adopt
        if (!$wouldSucceed) {
            return new MigrationPlanItemAnalysis(
                MigrationPlanItemAnalysis::Adoptable,
                existingTables: $existingTables,
            );
        }

        // Mixed: some statements would fail, some would succeed → inconsistent DB state
        return new MigrationPlanItemAnalysis(
            MigrationPlanItemAnalysis::Partial,
            existingTables: $existingTables,
            missingTables: $missingTables,
        );
    }

    private function fromDriftReport(MigrationDriftReport $report): MigrationPlanItemAnalysis
    {
        return match ($report->status()) {
            MigrationDriftReport::Clean => new MigrationPlanItemAnalysis(MigrationPlanItemAnalysis::Clean),

            MigrationDriftReport::CompatibleExisting => new MigrationPlanItemAnalysis(
                MigrationPlanItemAnalysis::Adoptable,
                existingTables: $report->existingTables(),
            ),

            MigrationDriftReport::Partial => $report->missingColumns() !== []
                ? new MigrationPlanItemAnalysis(
                    MigrationPlanItemAnalysis::NeedsColumns,
                    existingTables: $report->existingTables(),
                    missingTables: $report->missingTables(),
                    missingColumns: $report->missingColumns(),
                )
                : new MigrationPlanItemAnalysis(
                    MigrationPlanItemAnalysis::Partial,
                    existingTables: $report->existingTables(),
                    missingTables: $report->missingTables(),
                ),

            default => new MigrationPlanItemAnalysis(MigrationPlanItemAnalysis::Unknown),
        };
    }
}
