<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

/**
 * Single source of truth for "is this DDL statement destructive?" — shared by the CI safety
 * analyzer (PhaseMismatchRule), the deploy-runtime phase reader (to classify un-annotated app
 * migrations), and the deploy:doctor preflight check.
 *
 * Destructive = an operation that can drop data or take a long/blocking lock that is only safe
 * behind expand/contract discipline: DROP TABLE/COLUMN/INDEX/CONSTRAINT, column type changes,
 * SET NOT NULL, RENAME, DROP DEFAULT, and TRUNCATE. Additive DDL (CREATE, ADD COLUMN) is safe.
 *
 * Matching runs over the statement with string literals and line comments stripped, because the
 * keywords also appear in places that do the OPPOSITE of the operation they name. A trigger that
 * FORBIDS truncation declares `BEFORE TRUNCATE` and raises a message containing the word
 * "TRUNCATE"; classifying that as destructive pushes a purely protective migration into the
 * contract phase, where an install running contract migrations manually would simply never apply
 * it. Guarding against an operation must not be mistaken for performing it.
 */
final class DestructiveSqlDetector
{
    /** @var array<string, string> regex (without delimiters) => human label */
    private const PATTERNS = [
        // \b on both ends throughout: without it TRUNCATE matched inside the identifier
        // `audit_events_no_truncate`, so the function that FORBIDS truncation read as one.
        '\bDROP\s+TABLE\b'                                                             => 'DROP TABLE',
        '\bDROP\s+COLUMN\b'                                                            => 'DROP COLUMN',
        '\bDROP\s+INDEX\b'                                                             => 'DROP INDEX',
        '\bDROP\s+CONSTRAINT\b'                                                        => 'DROP CONSTRAINT',
        '\bALTER\s+(?:TABLE\s+\S+\s+)?ALTER\s+COLUMN\s+\S+\s+(?:SET\s+DATA\s+)?TYPE\b'  => 'ALTER COLUMN TYPE',
        '\bALTER\s+(?:TABLE\s+\S+\s+)?ALTER\s+COLUMN\s+\S+\s+SET\s+NOT\s+NULL\b'        => 'SET NOT NULL',
        '\bRENAME\s+(?:TABLE|COLUMN|TO)\b'                                              => 'RENAME',
        '\bALTER\s+(?:TABLE\s+\S+\s+)?ALTER\s+COLUMN\s+\S+\s+DROP\s+DEFAULT\b'          => 'DROP DEFAULT',
        '\bTRUNCATE\b'                                                                  => 'TRUNCATE',
    ];

    public function isDestructive(string $sql): bool
    {
        return $this->firstMatch($sql) !== null;
    }

    /**
     * @param list<string> $statements
     */
    public function anyDestructive(array $statements): bool
    {
        foreach ($statements as $sql) {
            if ($this->isDestructive($sql)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The label of the first destructive pattern that matches, or null if the statement is safe.
     */
    public function firstMatch(string $sql): ?string
    {
        $scannable = $this->stripNonExecutable($sql);

        foreach (self::PATTERNS as $pattern => $label) {
            if (preg_match('/' . $pattern . '/i', $scannable) === 1) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Blank out the parts of a statement that cannot execute DDL: single-quoted literals, line
     * comments, and a trigger's event clause. Replaced with spaces rather than removed so nothing
     * either side accidentally joins into a new keyword.
     */
    private function stripNonExecutable(string $sql): string
    {
        $blank = static fn (array $m): string => str_repeat(' ', strlen($m[0]));

        // Dollar-quoted bodies — a PL/pgSQL function body is not DDL. Tagged and untagged forms
        // are separate alternatives: a backreference to a group that did not participate never
        // matches, so one combined pattern silently skipped every anonymous $$ ... $$ body.
        $sql = preg_replace_callback('/\$\$.*?\$\$/s', $blank, $sql) ?? $sql;
        $sql = preg_replace_callback('/\$([A-Za-z_]\w*)\$.*?\$\1\$/s', $blank, $sql) ?? $sql;

        // Single-quoted literals, doubled '' escapes included.
        $sql = preg_replace_callback("/'(?:[^']|'')*'/", $blank, $sql) ?? $sql;

        // -- line comments.
        $sql = preg_replace_callback('/--[^\n]*/', $blank, $sql) ?? $sql;

        // A trigger fires BEFORE/AFTER/INSTEAD OF an event; naming the event is not doing it.
        $sql = preg_replace_callback(
            '/\b(?:BEFORE|AFTER|INSTEAD\s+OF)\s+(?:INSERT|UPDATE|DELETE|TRUNCATE)'
            . '(?:\s+OR\s+(?:INSERT|UPDATE|DELETE|TRUNCATE))*/i',
            $blank,
            $sql,
        ) ?? $sql;

        return $sql;
    }
}
