<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

final class NotNullNoDefaultRule implements SafetyRuleInterface
{
    public function id(): string
    {
        return 'pg.column.not-null-no-default';
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
        if (!$statement->matches('\bADD\s+(?:COLUMN\s+)?["`]?\w+["`]?\s+\w+')) {
            return;
        }

        if (!$statement->matches('\bNOT\s+NULL\b')) {
            return;
        }

        if ($statement->matches('\bDEFAULT\b')) {
            return;
        }

        $table = null;
        if (preg_match('/\bALTER\s+TABLE\s+["`]?(\w+)["`]?/i', $statement->raw, $m)) {
            $table = strtolower($m[1]);
        }

        yield new SafetyDiagnostic(
            ruleId: $this->id(),
            severity: $this->defaultSeverity(),
            table: $table,
            statementExcerpt: $statement->raw,
            message: 'ADD COLUMN with NOT NULL and no DEFAULT causes a full table rewrite and acquires an ACCESS EXCLUSIVE lock.',
            remediation: 'Add the column as NULL with a DEFAULT first, backfill existing rows, then ALTER COLUMN SET NOT NULL.',
        );
    }
}
