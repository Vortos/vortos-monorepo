<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

final class PhaseUndeclaredRule implements SafetyRuleInterface
{
    public function id(): string
    {
        return 'pg.phase.undeclared';
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
        if ($artifact->phase !== null) {
            return;
        }

        if (!$statement->isDDL()) {
            return;
        }

        if ($statement->index > 0) {
            return;
        }

        yield new SafetyDiagnostic(
            ruleId: $this->id(),
            severity: $this->defaultSeverity(),
            table: null,
            statementExcerpt: $statement->raw,
            message: 'Migration contains DDL but has no #[DeployPhase] declaration. Every migration with schema changes must declare its phase.',
            remediation: 'Add #[DeployPhase(MigrationPhase::Expand)] or #[DeployPhase(MigrationPhase::Contract)] to the migration class.',
        );
    }
}
