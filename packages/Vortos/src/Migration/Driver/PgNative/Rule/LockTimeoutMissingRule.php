<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

final class LockTimeoutMissingRule implements SafetyRuleInterface
{
    public function __construct(
        private readonly int $enforcerLockTimeoutMs = 0,
    ) {}

    public function id(): string
    {
        return 'pg.lock-timeout.missing';
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
        if (!$statement->isDDL()) {
            return;
        }

        if ($this->enforcerLockTimeoutMs > 0) {
            return;
        }

        $hasMigrationLockTimeout = false;
        foreach ($artifact->upSql as $sql) {
            if (preg_match('/\bSET\s+lock_timeout\b/i', $sql)) {
                $hasMigrationLockTimeout = true;
                break;
            }
        }

        if ($hasMigrationLockTimeout) {
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
            message: 'DDL present but no lock_timeout is configured (neither via MigrationLockSafetyEnforcer nor an explicit SET lock_timeout in the migration).',
            remediation: 'Configure vortos.migration.lock_timeout_ms (recommended: 3000) or add an explicit SET lock_timeout statement at the start of the migration.',
        );
    }
}
