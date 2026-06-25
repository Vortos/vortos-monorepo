<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

final class PhaseMismatchRule implements SafetyRuleInterface
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

    public function id(): string
    {
        return 'pg.phase.mismatch';
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
        if ($artifact->phase !== MigrationPhase::Expand) {
            return;
        }

        foreach (self::DESTRUCTIVE_PATTERNS as $pattern => $label) {
            if (preg_match('/' . $pattern . '/i', $statement->raw)) {
                $table = null;
                if (preg_match('/\bALTER\s+TABLE\s+["`]?(\w+)["`]?/i', $statement->raw, $m)) {
                    $table = strtolower($m[1]);
                }

                yield new SafetyDiagnostic(
                    ruleId: $this->id(),
                    severity: $this->defaultSeverity(),
                    table: $table,
                    statementExcerpt: $statement->raw,
                    message: sprintf(
                        'Destructive DDL (%s) declared as Expand phase. Destructive operations must be in a Contract migration.',
                        $label,
                    ),
                    remediation: 'Change the migration to #[DeployPhase(MigrationPhase::Contract)].',
                );

                return;
            }
        }
    }
}
