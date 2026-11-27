<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Check;

use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Drill\InvariantResult;

/**
 * Every foreign key in the restored database holds: no child row points at a parent that is missing.
 *
 * READ FROM pg_constraint, NOT information_schema. The earlier version joined
 * information_schema.table_constraints × key_column_usage × constraint_column_usage, which went wrong
 * three ways, each of which hid or invented orphans:
 *  - information_schema shows a constraint only to a role that owns the table or holds a privilege
 *    other than SELECT on it. A drill connecting as a read-only backup role saw no foreign keys at all
 *    and reported "all FK constraints satisfied" — on a copy that held 153 real orphans.
 *  - a composite key's columns came back as a cross product, so `(team_registration_id,
 *    organization_id) → team_registrations(id, organization_id)` was checked as four single-column
 *    pairs, including organization_id → team_registrations.id: 281 orphans that did not exist.
 *  - only schema `public` was looked at.
 *
 * pg_constraint is visible to every role. conkey and confkey are unnested together WITH ORDINALITY, so
 * child and parent columns stay paired in key order, and a composite key is checked as one key.
 *
 * NULL SEMANTICS match the server's. MATCH SIMPLE (the default) checks a row only when every key
 * column is non-null; MATCH FULL also counts a row whose key is partly null.
 *
 * NOTHING VISIBLE IS A FAILURE. A database with tables and zero foreign keys almost always means the
 * check could not see them, so it fails rather than reporting a pass it cannot back. A schema that
 * truly has none opts out with `$requireConstraints: false`.
 */
final class ReferentialIntegrityInvariant implements InvariantCheck
{
    private const MAX_REPORTED = 20;

    public function __construct(
        private readonly bool $requireConstraints = true,
    ) {}

    public function name(): string
    {
        return 'referential_integrity';
    }

    public function check(array $connectionParams): InvariantResult
    {
        try {
            $pdo = $this->connect($connectionParams);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $keys = $pdo->query(self::foreignKeysSql())->fetchAll(\PDO::FETCH_ASSOC);

            if ($keys === []) {
                return $this->requireConstraints
                    ? InvariantResult::fail($this->name(), 'no foreign keys are visible in the restored database, so nothing was proved; check the drill role can read pg_constraint')
                    : InvariantResult::pass($this->name(), 'no foreign keys declared');
            }

            $violations = [];
            foreach ($keys as $key) {
                $count = (int) $pdo->query(self::orphanCountSql($key))->fetchColumn();
                if ($count > 0) {
                    $violations[] = sprintf(
                        '%s: %s(%s) → %s(%s): %d orphan%s',
                        $key['name'],
                        $key['child'],
                        implode(', ', self::columns($key['child_columns'])),
                        $key['parent'],
                        implode(', ', self::columns($key['parent_columns'])),
                        $count,
                        $count === 1 ? '' : 's',
                    );
                }
            }

            if ($violations !== []) {
                $shown = array_slice($violations, 0, self::MAX_REPORTED);
                $more  = count($violations) - count($shown);

                return InvariantResult::fail(
                    $this->name(),
                    implode('; ', $shown) . ($more > 0 ? sprintf('; and %d more', $more) : ''),
                );
            }

            return InvariantResult::pass($this->name(), sprintf('all %d foreign keys satisfied', count($keys)));
        } catch (\Throwable $e) {
            return InvariantResult::fail($this->name(), $e->getMessage());
        }
    }

    /**
     * One row per foreign key: its name, the child and parent tables (schema-qualified where needed and
     * already quoted by regclass), the paired columns as JSON arrays of quoted identifiers in key
     * order, and the match type ('s' simple, 'f' full, 'p' partial — not implemented by PostgreSQL).
     *
     * @internal public for the tests
     */
    public static function foreignKeysSql(): string
    {
        return <<<'SQL'
            SELECT c.conname                         AS name,
                   c.conrelid::regclass::text        AS child,
                   c.confrelid::regclass::text       AS parent,
                   c.confmatchtype                   AS match_type,
                   json_agg(quote_ident(ca.attname) ORDER BY k.ord)::text AS child_columns,
                   json_agg(quote_ident(pa.attname) ORDER BY k.ord)::text AS parent_columns
              FROM pg_constraint c
              JOIN pg_namespace n ON n.oid = c.connamespace
              CROSS JOIN LATERAL unnest(c.conkey, c.confkey) WITH ORDINALITY AS k(child_attnum, parent_attnum, ord)
              JOIN pg_attribute ca ON ca.attrelid = c.conrelid  AND ca.attnum = k.child_attnum
              JOIN pg_attribute pa ON pa.attrelid = c.confrelid AND pa.attnum = k.parent_attnum
             WHERE c.contype = 'f'
               AND n.nspname NOT IN ('pg_catalog', 'information_schema')
               AND n.nspname NOT LIKE 'pg\_toast%'
             GROUP BY c.oid, c.conname, c.conrelid, c.confrelid, c.confmatchtype
             ORDER BY c.conrelid::regclass::text, c.conname
        SQL;
    }

    /**
     * @param array{child: string, parent: string, match_type: string, child_columns: string, parent_columns: string} $key
     *
     * @internal public for the tests
     */
    public static function orphanCountSql(array $key): string
    {
        $child  = self::columns($key['child_columns']);
        $parent = self::columns($key['parent_columns']);

        if ($child === [] || count($child) !== count($parent)) {
            throw new \UnexpectedValueException(sprintf('foreign key on %s has unpaired columns', $key['child']));
        }

        $allSet  = implode(' AND ', array_map(static fn (string $c): string => "t.$c IS NOT NULL", $child));
        $matches = implode(' AND ', array_map(static fn (string $c, string $p): string => "r.$p = t.$c", $child, $parent));
        $missing = sprintf('(%s AND NOT EXISTS (SELECT 1 FROM %s r WHERE %s))', $allSet, $key['parent'], $matches);

        if ($key['match_type'] === 'f' && count($child) > 1) {
            // MATCH FULL: a key that is partly null is itself a violation.
            $anySet  = implode(' OR ', array_map(static fn (string $c): string => "t.$c IS NOT NULL", $child));
            $missing = sprintf('(%s OR ((%s) AND NOT (%s)))', $missing, $anySet, $allSet);
        }

        return sprintf('SELECT count(*) FROM %s t WHERE %s', $key['child'], $missing);
    }

    /** @return list<string> */
    private static function columns(string $json): array
    {
        $columns = json_decode($json, true, 4, JSON_THROW_ON_ERROR);

        return is_array($columns) ? array_values(array_map('strval', $columns)) : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function connect(array $params): \PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $params['host'] ?? 'localhost',
            $params['port'] ?? '5432',
            $params['dbname'] ?? 'postgres',
        );

        return new \PDO($dsn, (string) ($params['user'] ?? 'postgres'), (string) ($params['password'] ?? ''));
    }
}
