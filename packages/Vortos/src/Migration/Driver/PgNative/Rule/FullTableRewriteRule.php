<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

final class FullTableRewriteRule implements SafetyRuleInterface
{
    public function id(): string
    {
        return 'pg.backfill.full-rewrite';
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
        $normalized = $statement->normalized;

        $isUnboundedUpdate = str_starts_with($normalized, 'UPDATE')
            && !str_contains($normalized, 'WHERE')
            && !str_contains($normalized, 'LIMIT');

        $isUnboundedDelete = str_starts_with($normalized, 'DELETE')
            && !str_contains($normalized, 'WHERE')
            && !str_contains($normalized, 'LIMIT');

        if (!$isUnboundedUpdate && !$isUnboundedDelete) {
            return;
        }

        if ($artifact->hasAllowFullTableRewrite) {
            return;
        }

        $table = null;
        if ($isUnboundedUpdate && preg_match('/\bUPDATE\s+["`]?(\w+)["`]?/i', $statement->raw, $m)) {
            $table = strtolower($m[1]);
        } elseif ($isUnboundedDelete && preg_match('/\bDELETE\s+FROM\s+["`]?(\w+)["`]?/i', $statement->raw, $m)) {
            $table = strtolower($m[1]);
        }

        $kind = $isUnboundedUpdate ? 'UPDATE' : 'DELETE';

        yield new SafetyDiagnostic(
            ruleId: $this->id(),
            severity: $this->defaultSeverity(),
            table: $table,
            statementExcerpt: $statement->raw,
            message: sprintf('Unbounded %s without WHERE clause. This rewrites the entire table and can cause prolonged locking.', $kind),
            remediation: 'Batch the operation with WHERE/LIMIT or use #[AllowFullTableRewrite] if intentional.',
            optOutAttribute: 'AllowFullTableRewrite',
        );
    }
}
