<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

final class BlockingAlterRule implements SafetyRuleInterface
{
    private const DEFAULT_ROW_THRESHOLD = 100_000;
    private const DEFAULT_BYTES_THRESHOLD = 67_108_864;

    public function __construct(
        private readonly int $rowThreshold = self::DEFAULT_ROW_THRESHOLD,
        private readonly int $bytesThreshold = self::DEFAULT_BYTES_THRESHOLD,
    ) {}

    public function id(): string
    {
        return 'pg.alter.blocking';
    }

    public function defaultSeverity(): Severity
    {
        return Severity::Error;
    }

    public function evaluate(
        MigrationArtifact $artifact,
        ?TargetSchemaSnapshot $target,
        ParsedStatement $statement,
    ): iterable {
        $table = null;
        if (preg_match('/\bALTER\s+TABLE\s+["`]?(\w+)["`]?/i', $statement->raw, $m)) {
            $table = strtolower($m[1]);
        }

        if ($table === null) {
            return;
        }

        yield from $this->checkSetNotNull($statement, $table);
        yield from $this->checkAlterType($statement, $table);
        yield from $this->checkAddConstraint($statement, $table);
        yield from $this->checkDropColumn($statement, $table, $artifact, $target);
    }

    /** @return iterable<SafetyDiagnostic> */
    private function checkSetNotNull(ParsedStatement $statement, string $table): iterable
    {
        if (!$statement->matches('\bSET\s+NOT\s+NULL\b')) {
            return;
        }

        if (!$statement->matches('\bADD\s+(?:COLUMN\s+)?\w+')) {
            yield new SafetyDiagnostic(
                ruleId: $this->id(),
                severity: $this->defaultSeverity(),
                table: $table,
                statementExcerpt: $statement->raw,
                message: 'ALTER COLUMN SET NOT NULL scans the entire table and holds an ACCESS EXCLUSIVE lock.',
                remediation: 'Add a CHECK constraint with NOT VALID first, then VALIDATE CONSTRAINT in a separate migration. PG12+ can use SET NOT NULL if a valid CHECK exists.',
            );
        }
    }

    /** @return iterable<SafetyDiagnostic> */
    private function checkAlterType(ParsedStatement $statement, string $table): iterable
    {
        if (!$statement->matches('\bALTER\s+COLUMN\s+["`]?\w+["`]?\s+(?:SET\s+DATA\s+)?TYPE\b')) {
            return;
        }

        yield new SafetyDiagnostic(
            ruleId: $this->id(),
            severity: $this->defaultSeverity(),
            table: $table,
            statementExcerpt: $statement->raw,
            message: 'ALTER COLUMN TYPE rewrites the entire table and holds an ACCESS EXCLUSIVE lock.',
            remediation: 'Use an expand/contract approach: add a new column with the desired type, migrate data in batches, then drop the old column in a contract migration.',
        );
    }

    /** @return iterable<SafetyDiagnostic> */
    private function checkAddConstraint(ParsedStatement $statement, string $table): iterable
    {
        if (!$statement->matches('\bADD\s+CONSTRAINT\b')) {
            return;
        }

        if (!$statement->matches('\b(?:FOREIGN\s+KEY|CHECK)\b')) {
            return;
        }

        if ($statement->matches('\bNOT\s+VALID\b')) {
            return;
        }

        yield new SafetyDiagnostic(
            ruleId: $this->id(),
            severity: $this->defaultSeverity(),
            table: $table,
            statementExcerpt: $statement->raw,
            message: 'ADD CONSTRAINT (FOREIGN KEY / CHECK) without NOT VALID scans the entire table while holding an ACCESS EXCLUSIVE lock.',
            remediation: 'Use ADD CONSTRAINT … NOT VALID, then VALIDATE CONSTRAINT in a separate migration. VALIDATE acquires only a SHARE UPDATE EXCLUSIVE lock.',
        );
    }

    /** @return iterable<SafetyDiagnostic> */
    private function checkDropColumn(
        ParsedStatement $statement,
        string $table,
        MigrationArtifact $artifact,
        ?TargetSchemaSnapshot $target,
    ): iterable {
        if (!$statement->matches('\bDROP\s+COLUMN\b')) {
            return;
        }

        if ($target === null || $target->isHot($table, $this->rowThreshold, $this->bytesThreshold)) {
            yield new SafetyDiagnostic(
                ruleId: $this->id(),
                severity: $this->defaultSeverity(),
                table: $table,
                statementExcerpt: $statement->raw,
                message: 'DROP COLUMN on a hot table requires an ACCESS EXCLUSIVE lock.',
                remediation: 'Ensure this is in a contract migration (#[DeployPhase(Contract)]). For hot tables, consider marking the column as unused first.',
            );
        }
    }
}
