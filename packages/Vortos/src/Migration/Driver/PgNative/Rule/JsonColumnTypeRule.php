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
 * Refuses a new column typed JSON where JSONB was almost certainly meant.
 *
 * The two look interchangeable and are not. JSON keeps the raw text and re-parses it on every
 * read; it has no equality operator, so it cannot be DISTINCT-ed, grouped or compared; it cannot
 * carry a GIN index; and PostgreSQL refuses to UNION a JSON column with a JSONB one. That last one
 * is not theoretical: a query joining two payment snapshots, one of each type, failed on every run
 * and silently stopped a nightly reconciliation job from ever completing. Doctrine's `json` type
 * produces JSON on PostgreSQL unless `jsonb` is set, so the wrong type is the easy one to write.
 *
 * Catching it in the migration is the only cheap moment. Once rows exist, fixing it is a table
 * rewrite. Only column definitions are matched — CREATE TABLE and ALTER TABLE statements — and a
 * `::json` cast or a json_* function name is not a column type.
 *
 * Opt out with #[AllowJsonColumn(reason: …)] when the exact text genuinely must be kept.
 */
final class JsonColumnTypeRule implements SafetyRuleInterface
{
    public function id(): string
    {
        return 'pg.type.json';
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
        if ($artifact->hasAllowJsonColumn) {
            return;
        }

        if (!$statement->matches('^\s*(?:CREATE\s+(?:UNLOGGED\s+)?TABLE|ALTER\s+TABLE)\b')) {
            return;
        }

        // JSON as a whole word: not JSONB, not json_build_object, not a ::json cast.
        if (!$statement->matches('(?<!:)\bJSON\b')) {
            return;
        }

        $table = null;
        if (preg_match('/\b(?:TABLE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(?:ONLY\s+)?["`]?(\w+)["`]?(?:\.["`]?(\w+)["`]?)?/i', $statement->raw, $m)) {
            // Group 2 is the last group, so it is absent rather than empty when there is no schema.
            $table = strtolower(isset($m[2]) ? $m[1] . '.' . $m[2] : $m[1]);
        }

        yield new SafetyDiagnostic(
            ruleId: $this->id(),
            severity: $this->defaultSeverity(),
            table: $table,
            statementExcerpt: $statement->raw,
            message: 'Column typed JSON rather than JSONB. JSON has no equality operator or GIN index, is re-parsed on every read, and cannot be UNIONed with a JSONB column.',
            remediation: 'Use JSONB (Doctrine: set the `jsonb` platform option on the json column). If the exact text must be preserved, add #[AllowJsonColumn(reason: "...")].',
            optOutAttribute: 'AllowJsonColumn',
        );
    }
}
