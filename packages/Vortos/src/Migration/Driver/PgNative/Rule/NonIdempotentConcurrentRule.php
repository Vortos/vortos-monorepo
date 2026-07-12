<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

/**
 * Requires CREATE INDEX CONCURRENTLY to be idempotent so a failed build is safely re-runnable.
 *
 * CONCURRENTLY index builds run outside a transaction, so a mid-build failure is NOT rolled
 * back — Postgres leaves an INVALID index behind. On the next deploy the migration re-runs
 * and, without a guard, fails with "relation already exists", stalling the deploy and
 * requiring manual intervention on the box. Guarding the statement with IF NOT EXISTS makes
 * the migration re-runnable.
 *
 * Note the residual caveat, spelled out in the remediation: IF NOT EXISTS skips creation if
 * an INVALID index of the same name already exists, so the fully robust pattern also drops a
 * leftover invalid index first (DROP INDEX IF EXISTS ...). This rule enforces the checkable
 * minimum (IF NOT EXISTS); the DROP is recommended, not machine-required.
 *
 * This rule is the counterpart to pg.index.non-concurrent: that rule forces indexes onto the
 * CONCURRENTLY path; this one makes that path safe to retry.
 */
final class NonIdempotentConcurrentRule implements SafetyRuleInterface
{
    public function id(): string
    {
        return 'pg.index.non-idempotent-concurrent';
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
        // Only the non-transactional CONCURRENTLY path is affected by INVALID-index leftovers.
        if (!$statement->matches('\bCREATE\s+(?:UNIQUE\s+)?INDEX\s+CONCURRENTLY\b')) {
            return;
        }

        // Already re-runnable.
        if ($statement->matches('\bIF\s+NOT\s+EXISTS\b')) {
            return;
        }

        // Author explicitly accepts manual cleanup of a leftover invalid index.
        if ($artifact->hasAllowNonIdempotentConcurrent) {
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
            message: 'CREATE INDEX CONCURRENTLY is not idempotent: a failed build leaves an INVALID index, so re-running the migration fails with "relation already exists".',
            remediation: 'Add IF NOT EXISTS (CREATE INDEX CONCURRENTLY IF NOT EXISTS ...) so the migration is re-runnable. To also clear a leftover invalid index, prefix with DROP INDEX IF EXISTS <name>. Or use #[AllowNonIdempotentConcurrent] if you handle cleanup manually.',
            optOutAttribute: 'AllowNonIdempotentConcurrent',
        );
    }
}
