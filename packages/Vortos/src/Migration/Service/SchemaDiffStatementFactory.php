<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\ModuleSchemaProviderInterface;

/**
 * Turns an ordered set of module schema providers into the *incremental* SQL each one
 * contributes, by diffing every provider against the cumulative schema of all providers
 * that ran before it.
 *
 * Why this exists (PUBLISH-1 / R7-1): the old publisher ran each provider's define()
 * against a *fresh* empty Schema and emitted Schema::toSql(). An alter-style provider —
 * one that adds columns/indexes to a table created by an *earlier* provider — guards its
 * ALTER with `if ($schema->hasTable(...))`, which is always false against a fresh schema,
 * so its additions were silently dropped from the generated migration (e.g. the four
 * backup_catalog encryption columns never made it into a migration, and every backup
 * failed at runtime).
 *
 * The fix: maintain one cumulative base schema across the ordered providers. For provider
 * i we clone the base (state of the world before i), let the provider define() into the
 * clone (its hasTable() guards now see the real base tables), then diff base→after with
 * Doctrine's Comparator and render the diff via the platform's getAlterSchemaSQL(). The
 * result is exactly provider i's delta — CREATE for new tables, ALTER … ADD for new
 * columns, CREATE INDEX for new indexes — and the base advances to `after`.
 *
 * Providers MUST be supplied in dependency order (the publisher orders them by migration
 * filename/timestamp, so a table's creator always precedes an alter that targets it).
 *
 * Raw-SQL stubs cannot participate — they are opaque SQL with no Schema representation.
 * An alter-style *schema provider* may therefore only target a table defined by another
 * *schema provider*, never one created by a raw .sql stub.
 */
final class SchemaDiffStatementFactory
{
    private readonly AbstractPlatform $platform;

    public function __construct(?AbstractPlatform $platform = null)
    {
        $this->platform = $platform ?? new PostgreSQLPlatform();
    }

    /**
     * @param list<array{relative: string, provider: ModuleSchemaProviderInterface}> $orderedProviders
     *        Schema providers in dependency (filename/timestamp) order.
     *
     * @return array<string, list<string>> relative-path => incremental, idempotent SQL statements
     */
    public function statementsFor(array $orderedProviders): array
    {
        // The Comparator constructor is marked @internal but is the only way to diff without a
        // live connection; at publish time we have no database, only the target platform.
        $comparator = new Comparator($this->platform);

        $base   = new Schema();
        $result = [];

        foreach ($orderedProviders as $entry) {
            $after = clone $base;          // Schema::__clone deep-clones tables/sequences
            $entry['provider']->define($after);

            $diff       = $comparator->compareSchemas($base, $after);
            $statements = array_map(
                $this->makeStatementIdempotent(...),
                $this->platform->getAlterSchemaSQL($diff),
            );

            $result[$entry['relative']] = array_values($statements);
            $base = $after;
        }

        return $result;
    }

    /**
     * Belt-and-suspenders idempotency so a re-applied migration never fails on an object that
     * already exists. Doctrine emits column adds without the optional COLUMN keyword
     * (`ALTER TABLE x ADD col …`); we must NOT rewrite constraint/key/index adds.
     */
    private function makeStatementIdempotent(string $statement): string
    {
        $statement = preg_replace('/^CREATE SCHEMA (?!IF NOT EXISTS )/i', 'CREATE SCHEMA IF NOT EXISTS ', $statement) ?? $statement;
        $statement = preg_replace('/^CREATE TABLE (?!IF NOT EXISTS )/i', 'CREATE TABLE IF NOT EXISTS ', $statement) ?? $statement;
        $statement = preg_replace('/^CREATE (UNIQUE )?INDEX (?!IF NOT EXISTS )/i', 'CREATE $1INDEX IF NOT EXISTS ', $statement) ?? $statement;

        // Column adds only: `ADD COLUMN col …` and the keyword-less `ADD col …`. Never
        // `ADD CONSTRAINT|PRIMARY KEY|UNIQUE|FOREIGN KEY|CHECK|INDEX`, and never a form that
        // already carries IF NOT EXISTS.
        $statement = preg_replace(
            '/\bADD COLUMN (?!IF NOT EXISTS )/i',
            'ADD COLUMN IF NOT EXISTS ',
            $statement,
        ) ?? $statement;
        $statement = preg_replace(
            '/\bADD (?!(?:COLUMN|IF NOT EXISTS|CONSTRAINT|PRIMARY|UNIQUE|FOREIGN|CHECK|INDEX)\b)/i',
            'ADD IF NOT EXISTS ',
            $statement,
        ) ?? $statement;

        return $statement;
    }
}
