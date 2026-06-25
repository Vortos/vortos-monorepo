<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Attribute\AllowFullTableRewrite;

final class BackfillSafetyAnalyzer
{
    public function __construct(
        private readonly MigrationSqlExtractorInterface $extractor,
    ) {}

    /** @return list<BackfillFinding> */
    public function analyze(string $migrationClass): array
    {
        $hasOptOut = $this->hasAllowFullTableRewrite($migrationClass);
        $sql = $this->extractor->extractFromClass($migrationClass);
        $findings = [];

        foreach ($sql as $statement) {
            $finding = $this->analyzeStatement($statement, $hasOptOut);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $sqlStatements
     * @return list<BackfillFinding>
     */
    public function analyzeStatements(array $sqlStatements, bool $hasOptOut = false): array
    {
        $findings = [];

        foreach ($sqlStatements as $statement) {
            $finding = $this->analyzeStatement($statement, $hasOptOut);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function analyzeStatement(string $statement, bool $hasOptOut): ?BackfillFinding
    {
        $normalized = strtoupper(trim($statement));

        if ($this->isUnboundedUpdate($normalized)) {
            return $hasOptOut
                ? BackfillFinding::allowed($statement)
                : BackfillFinding::unboundedUpdate($statement);
        }

        if ($this->isUnboundedDelete($normalized)) {
            return $hasOptOut
                ? BackfillFinding::allowed($statement)
                : BackfillFinding::unboundedDelete($statement);
        }

        return null;
    }

    private function isUnboundedUpdate(string $normalized): bool
    {
        if (!str_starts_with($normalized, 'UPDATE')) {
            return false;
        }

        if (str_contains($normalized, 'WHERE')) {
            return false;
        }

        if (str_contains($normalized, 'LIMIT')) {
            return false;
        }

        return true;
    }

    private function isUnboundedDelete(string $normalized): bool
    {
        if (!str_starts_with($normalized, 'DELETE')) {
            return false;
        }

        if (str_contains($normalized, 'WHERE')) {
            return false;
        }

        if (str_contains($normalized, 'LIMIT')) {
            return false;
        }

        return true;
    }

    private function hasAllowFullTableRewrite(string $migrationClass): bool
    {
        if (!class_exists($migrationClass)) {
            return false;
        }

        $reflection = new \ReflectionClass($migrationClass);

        return $reflection->getAttributes(AllowFullTableRewrite::class) !== [];
    }
}
