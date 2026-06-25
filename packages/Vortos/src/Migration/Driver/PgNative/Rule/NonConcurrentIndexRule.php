<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

final class NonConcurrentIndexRule implements SafetyRuleInterface
{
    public function id(): string
    {
        return 'pg.index.non-concurrent';
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
        if (!$statement->matches('\bCREATE\s+(?:UNIQUE\s+)?INDEX\b')) {
            return;
        }

        if ($statement->matches('\bCONCURRENTLY\b')) {
            return;
        }

        $table = null;
        if (preg_match('/\bON\s+["`]?(\w+)["`]?/i', $statement->raw, $m)) {
            $table = strtolower($m[1]);
        }

        yield new SafetyDiagnostic(
            ruleId: $this->id(),
            severity: $this->defaultSeverity(),
            table: $table,
            statementExcerpt: $statement->raw,
            message: 'CREATE INDEX without CONCURRENTLY acquires an exclusive lock on the table.',
            remediation: 'Use CREATE INDEX CONCURRENTLY. Requires the migration to be non-transactional (withTransaction(false) in Doctrine).',
        );
    }
}
