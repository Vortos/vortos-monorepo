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
 */
final class DestructiveSqlDetector
{
    /** @var array<string, string> regex (without delimiters) => human label */
    private const PATTERNS = [
        'DROP\s+TABLE'                                                                 => 'DROP TABLE',
        'DROP\s+COLUMN'                                                                => 'DROP COLUMN',
        'DROP\s+INDEX'                                                                 => 'DROP INDEX',
        'DROP\s+CONSTRAINT'                                                            => 'DROP CONSTRAINT',
        'ALTER\s+(?:TABLE\s+\S+\s+)?ALTER\s+COLUMN\s+\S+\s+(?:SET\s+DATA\s+)?TYPE'     => 'ALTER COLUMN TYPE',
        'ALTER\s+(?:TABLE\s+\S+\s+)?ALTER\s+COLUMN\s+\S+\s+SET\s+NOT\s+NULL'           => 'SET NOT NULL',
        'RENAME\s+(?:TABLE|COLUMN|TO)'                                                 => 'RENAME',
        'ALTER\s+(?:TABLE\s+\S+\s+)?ALTER\s+COLUMN\s+\S+\s+DROP\s+DEFAULT'             => 'DROP DEFAULT',
        'TRUNCATE'                                                                     => 'TRUNCATE',
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
        foreach (self::PATTERNS as $pattern => $label) {
            if (preg_match('/' . $pattern . '/i', $sql) === 1) {
                return $label;
            }
        }

        return null;
    }
}
