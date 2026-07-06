<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Safety\DestructiveSqlDetector;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

final class PhaseMismatchRule implements SafetyRuleInterface
{
    public function __construct(
        private readonly DestructiveSqlDetector $detector = new DestructiveSqlDetector(),
    ) {}

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

        $label = $this->detector->firstMatch($statement->raw);

        if ($label === null) {
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
            message: sprintf(
                'Destructive DDL (%s) declared as Expand phase. Destructive operations must be in a Contract migration.',
                $label,
            ),
            remediation: 'Change the migration to #[DeployPhase(MigrationPhase::Contract)].',
        );
    }
}
