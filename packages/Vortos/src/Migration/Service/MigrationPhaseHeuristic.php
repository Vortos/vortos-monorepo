<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Schema\MigrationPhase;

final class MigrationPhaseHeuristic
{
    private const DESTRUCTIVE_PATTERNS = [
        'DROP\s+TABLE' => 'DROP TABLE',
        'DROP\s+COLUMN' => 'DROP COLUMN',
        'DROP\s+INDEX' => 'DROP INDEX',
        'DROP\s+CONSTRAINT' => 'DROP CONSTRAINT',
        'ALTER\s+(?:TABLE\s+\S+\s+)?ALTER\s+COLUMN\s+\S+\s+(?:SET\s+DATA\s+)?TYPE' => 'ALTER COLUMN TYPE',
        'ALTER\s+(?:TABLE\s+\S+\s+)?ALTER\s+COLUMN\s+\S+\s+SET\s+NOT\s+NULL' => 'SET NOT NULL',
        'RENAME\s+(?:TABLE|COLUMN|TO)' => 'RENAME',
        'ALTER\s+(?:TABLE\s+\S+\s+)?ALTER\s+COLUMN\s+\S+\s+DROP\s+DEFAULT' => 'DROP DEFAULT',
    ];

    public function __construct(
        private readonly MigrationSqlExtractorInterface $extractor,
    ) {}

    /**
     * @throws PhaseMisdeclarationException if declared phase contradicts the SQL heuristic
     */
    public function validate(string $migrationClass, MigrationPhase $declaredPhase): void
    {
        if ($declaredPhase === MigrationPhase::Contract) {
            return;
        }

        $sql = $this->extractor->extractFromClass($migrationClass);
        $destructive = $this->detectDestructiveTokens($sql);

        if ($destructive !== []) {
            throw new PhaseMisdeclarationException($migrationClass, $declaredPhase, $destructive);
        }
    }

    /**
     * @param string[] $sqlStatements
     * @return list<string>
     */
    public function detectDestructiveTokens(array $sqlStatements): array
    {
        $found = [];
        $normalized = implode("\n", $sqlStatements);

        foreach (self::DESTRUCTIVE_PATTERNS as $pattern => $label) {
            if (preg_match('/' . $pattern . '/i', $normalized)) {
                $found[] = $label;
            }
        }

        return array_values(array_unique($found));
    }
}
