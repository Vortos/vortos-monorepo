<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

/**
 * Parses SQL strings for schema object declarations.
 *
 * Pure: no DB connection, no I/O. Used by MigrationPlanAnalyzer to decide
 * per-migration strategy before touching the database.
 */
final class MigrationSqlParser
{
    /**
     * Extract CREATE TABLE declarations from a SQL string.
     *
     * @return list<array{name: string, ifNotExists: bool}>
     */
    public function parseTables(string $sql): array
    {
        $results = [];

        preg_match_all(
            '/\bCREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?["`]?(\w+)["`]?/i',
            $sql,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $m) {
            $results[] = [
                'name'        => strtolower($m[2]),
                'ifNotExists' => trim($m[1]) !== '',
            ];
        }

        return $results;
    }

    /**
     * Extract CREATE [UNIQUE] INDEX declarations from a SQL string.
     *
     * @return list<array{name: string, table: string, ifNotExists: bool}>
     */
    public function parseIndexes(string $sql): array
    {
        $results = [];

        preg_match_all(
            '/\bCREATE\s+(?:UNIQUE\s+)?INDEX\s+(IF\s+NOT\s+EXISTS\s+)?["`]?(\w+)["`]?\s+ON\s+["`]?(\w+)["`]?/i',
            $sql,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $m) {
            $results[] = [
                'name'        => strtolower($m[2]),
                'table'       => strtolower($m[3]),
                'ifNotExists' => trim($m[1]) !== '',
            ];
        }

        return $results;
    }

    /**
     * Extract ALTER TABLE … ADD [COLUMN] declarations from a SQL string.
     *
     * @return list<array{table: string, column: string}>
     */
    public function parseAddColumns(string $sql): array
    {
        $results = [];

        preg_match_all(
            '/\bALTER\s+TABLE\s+["`]?(\w+)["`]?\s+ADD\s+(?:COLUMN\s+)?["`]?(\w+)["`]?/i',
            $sql,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $m) {
            $results[] = [
                'table'  => strtolower($m[1]),
                'column' => strtolower($m[2]),
            ];
        }

        return $results;
    }
}
