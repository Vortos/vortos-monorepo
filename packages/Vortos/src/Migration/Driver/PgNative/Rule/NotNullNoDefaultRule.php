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
        // ADD CONSTRAINT is not ADD COLUMN. Without this the loose pattern below binds
        // its `\w+` to the CONSTRAINT keyword, and the `NOT NULL` inside a CHECK *body*
        // trips the guard — so `ADD CONSTRAINT c CHECK (col IS NOT NULL) NOT VALID` was
        // reported as "ADD COLUMN with NOT NULL and no DEFAULT causes a full table
        // rewrite" about a statement that adds no column and rewrites nothing.
        //
        // That statement is precisely what BlockingAlterRule tells you to write instead
        // of ALTER COLUMN … SET NOT NULL, and this rule's own remediation then points
        // back at SET NOT NULL. The two rules formed a closed loop with no opt-out on
        // either, leaving no legal way to make an existing column non-null.
        if ($statement->matches('\bADD\s+CONSTRAINT\b')) {
            return;
        }

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
            // Deliberately does NOT say "then ALTER COLUMN SET NOT NULL": BlockingAlterRule
            // refuses that in every phase, with no opt-out. Point at the route that is
            // actually permitted end to end.
            remediation: 'Add the column as NULL with a DEFAULT, backfill existing rows, then enforce it with '
                . 'ADD CONSTRAINT … CHECK (col IS NOT NULL) NOT VALID and VALIDATE CONSTRAINT in a contract migration.',
        );
    }
}
