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

        // Extract the target table (possibly schema-qualified, e.g. vortos.audit_saved_views).
        $table = null;
        if (preg_match('/\bON\s+["`]?([\w.]+)["`]?/i', $statement->raw, $m)) {
            $table = strtolower($m[1]);
        }

        // Exempt an index on a table CREATE'd in this same migration: the table is empty, so the
        // exclusive lock is instantaneous and harmless — no CONCURRENTLY needed. This lets a
        // module's published schema migration create its own table + secondary indexes in one
        // transactional step (the migrate:publish generator can't emit CONCURRENTLY).
        if ($table !== null && $this->tableCreatedInSameMigration($artifact, $table)) {
            return;
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

    /** True when a CREATE TABLE for $table appears earlier in the same migration's up SQL. */
    private function tableCreatedInSameMigration(MigrationArtifact $artifact, string $table): bool
    {
        // Match the bare table name too, so 'vortos.audit_saved_views' matches a CREATE TABLE
        // that may or may not be schema-qualified.
        $bare    = str_contains($table, '.') ? substr((string) strrchr($table, '.'), 1) : $table;
        $pattern = '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?(?:[\w]+\.)?' . preg_quote($bare, '/') . '\b/i';

        foreach ($artifact->upSql as $sql) {
            if (preg_match($pattern, (string) $sql) === 1) {
                return true;
            }
        }

        return false;
    }
}
