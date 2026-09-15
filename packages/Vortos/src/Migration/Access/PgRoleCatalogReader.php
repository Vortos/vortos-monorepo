<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

use Doctrine\DBAL\Connection;
use Vortos\Persistence\Access\DatabaseRoleModel;

/**
 * Reads roles, ownership and privileges from the PostgreSQL catalog over the process's own connection.
 *
 * Only catalogs every role can read are used (pg_roles, pg_auth_members, pg_class, pg_namespace, pg_proc,
 * pg_type, pg_default_acl, pg_database, pg_depend, the has_*_privilege functions), so the answer does not depend
 * on which audience's credential the process holds. The one exception is who else is connected: client addresses
 * of other roles' sessions are visible only to superusers and pg_read_all_stats members, so that count is null
 * elsewhere rather than a reassuring zero.
 *
 * ACLs are exploded with their built-in default when NULL (acldefault), so a database whose ACL was never touched
 * still shows PUBLIC's implicit CONNECT and TEMPORARY — the default is a grant like any other.
 */
final class PgRoleCatalogReader implements RoleCatalogReaderInterface
{
    private const ACL_JSON = "COALESCE((SELECT json_agg(json_build_object('grantee', CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END, 'privilege', a.privilege_type)) FROM aclexplode(%s) a WHERE a.grantee <> %s), '[]')";

    public function __construct(private readonly Connection $connection) {}

    public function read(DatabaseRoleModel $model): RoleCatalogSnapshot
    {
        $schemas = '{' . implode(',', $model->schemas) . '}';

        $roles = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT rolname, rolsuper, rolreplication, rolcreaterole, rolcreatedb, rolbypassrls, rolcanlogin
               FROM pg_catalog.pg_roles WHERE rolname !~ '^pg_' ORDER BY rolname",
        ) as $r) {
            $roles[(string) $r['rolname']] = [
                'superuser' => self::bool($r['rolsuper']),
                'replication' => self::bool($r['rolreplication']),
                'createrole' => self::bool($r['rolcreaterole']),
                'createdb' => self::bool($r['rolcreatedb']),
                'bypassrls' => self::bool($r['rolbypassrls']),
                'login' => self::bool($r['rolcanlogin']),
            ];
        }

        $memberships = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT u.rolname AS member, m.rolname AS granted
               FROM pg_catalog.pg_auth_members am
               JOIN pg_catalog.pg_roles u ON u.oid = am.member
               JOIN pg_catalog.pg_roles m ON m.oid = am.roleid
              WHERE u.rolname !~ '^pg_'
              ORDER BY 1, 2",
        ) as $r) {
            $memberships[(string) $r['member']][] = (string) $r['granted'];
        }
        foreach ($memberships as $member => $granted) {
            $memberships[$member] = array_values(array_unique($granted));
        }

        $database = $this->connection->fetchAssociative(
            'SELECT current_database() AS name, pg_get_userbyid(d.datdba) AS owner, '
            . sprintf(self::ACL_JSON, "COALESCE(d.datacl, acldefault('d', d.datdba))", 'd.datdba') . ' AS acl
               FROM pg_catalog.pg_database d WHERE d.datname = current_database()',
        );
        if ($database === false) {
            throw new \RuntimeException('The current database is missing from pg_database.');
        }

        $schemaRows = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT n.nspname AS name, pg_get_userbyid(n.nspowner) AS owner, '
            . sprintf(self::ACL_JSON, "COALESCE(n.nspacl, acldefault('n', n.nspowner))", 'n.nspowner') . ' AS acl
               FROM pg_catalog.pg_namespace n WHERE n.nspname = ANY(?::text[]) ORDER BY 1',
            [$schemas],
        ) as $r) {
            $schemaRows[(string) $r['name']] = ['owner' => (string) $r['owner'], 'privileges' => self::acl($r['acl'])];
        }

        $relations = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT n.nspname AS schema, c.relname AS name, c.relkind AS kind, pg_get_userbyid(c.relowner) AS owner, "
            // acldefault() takes "char": a CASE yields text, which PostgreSQL will not coerce implicitly.
            . sprintf(self::ACL_JSON, "COALESCE(c.relacl, acldefault((CASE WHEN c.relkind = 'S' THEN 's' ELSE 'r' END)::\"char\", c.relowner))", 'c.relowner') . " AS acl,
                    (SELECT tn.nspname || '.' || t.relname
                       FROM pg_catalog.pg_depend d
                       JOIN pg_catalog.pg_class t ON t.oid = d.refobjid
                       JOIN pg_catalog.pg_namespace tn ON tn.oid = t.relnamespace
                      WHERE c.relkind = 'S' AND d.classid = 'pg_catalog.pg_class'::regclass AND d.objid = c.oid
                        AND d.refclassid = 'pg_catalog.pg_class'::regclass AND d.deptype IN ('a', 'i')
                      LIMIT 1) AS owned_by
               FROM pg_catalog.pg_class c
               JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = ANY(?::text[]) AND c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S')
                AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend e
                                 WHERE e.classid = 'pg_catalog.pg_class'::regclass AND e.objid = c.oid AND e.deptype = 'e')
              ORDER BY 1, 2",
            [$schemas],
        ) as $r) {
            $relations[] = [
                'schema' => (string) $r['schema'],
                'name' => (string) $r['name'],
                'kind' => match ((string) $r['kind']) {
                    'r', 'p' => 'table',
                    'v' => 'view',
                    'm' => 'materialized_view',
                    'f' => 'foreign_table',
                    'S' => 'sequence',
                },
                'owner' => (string) $r['owner'],
                'privileges' => self::acl($r['acl']),
                'ownedBy' => $r['owned_by'] === null ? null : (string) $r['owned_by'],
            ];
        }

        $routinesAndTypes = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT n.nspname AS schema, p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')' AS name,
                    'function' AS kind, pg_get_userbyid(p.proowner) AS owner
               FROM pg_catalog.pg_proc p
               JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
              WHERE n.nspname = ANY(?::text[])
                AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend e
                                 WHERE e.classid = 'pg_catalog.pg_proc'::regclass AND e.objid = p.oid AND e.deptype = 'e')
             UNION ALL
             SELECT n.nspname, t.typname, 'type', pg_get_userbyid(t.typowner)
               FROM pg_catalog.pg_type t
               JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace
               LEFT JOIN pg_catalog.pg_class c ON c.oid = t.typrelid
              WHERE n.nspname = ANY(?::text[])
                AND (t.typtype IN ('e', 'd', 'r') OR (t.typtype = 'c' AND c.relkind = 'c'))
                AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend e
                                 WHERE e.classid = 'pg_catalog.pg_type'::regclass AND e.objid = t.oid AND e.deptype = 'e')
              ORDER BY 1, 2",
            [$schemas, $schemas],
        ) as $r) {
            $routinesAndTypes[] = ['schema' => (string) $r['schema'], 'name' => (string) $r['name'], 'kind' => (string) $r['kind'], 'owner' => (string) $r['owner']];
        }

        $defaults = [];
        foreach ($model->schemas as $schema) {
            $defaults[$schema] = ['tables' => [], 'sequences' => []];
        }
        foreach ($this->connection->fetchAllAssociative(
            "SELECT n.nspname AS schema, d.defaclobjtype AS objtype,
                    CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END AS grantee, a.privilege_type AS privilege
               FROM pg_catalog.pg_default_acl d
               JOIN pg_catalog.pg_namespace n ON n.oid = d.defaclnamespace
               JOIN pg_catalog.pg_roles r ON r.oid = d.defaclrole
              CROSS JOIN LATERAL aclexplode(d.defaclacl) a
              WHERE r.rolname = ? AND n.nspname = ANY(?::text[])",
            [$model->owner->value, $schemas],
        ) as $r) {
            $bucket = match ((string) $r['objtype']) {
                'r' => 'tables',
                'S' => 'sequences',
                default => null,
            };
            if ($bucket !== null) {
                $defaults[(string) $r['schema']][$bucket][(string) $r['grantee']][] = (string) $r['privilege'];
            }
        }
        foreach ($defaults as $schema => $buckets) {
            foreach ($buckets as $bucket => $grants) {
                $defaults[$schema][$bucket] = self::normalise($grants);
            }
        }

        $backupCatalogAccess = null;
        if ($model->backup !== null && isset($roles[$model->backup->value])) {
            $row = $this->connection->fetchAssociative(
                "SELECT has_table_privilege(?, 'pg_catalog.pg_file_settings', 'SELECT') AS view_access,
                        has_function_privilege(?, 'pg_catalog.pg_show_all_file_settings()', 'EXECUTE') AS function_access",
                [$model->backup->value, $model->backup->value],
            );
            $backupCatalogAccess = [
                'fileSettingsView' => $row !== false && self::bool($row['view_access']),
                'fileSettingsFunction' => $row !== false && self::bool($row['function_access']),
            ];
        }

        $superuserClientConnections = null;
        if (self::bool($this->connection->fetchOne(
            "SELECT current_setting('is_superuser') = 'on' OR pg_has_role(current_user, 'pg_read_all_stats', 'USAGE')",
        ))) {
            $superuserClientConnections = (int) $this->connection->fetchOne(
                "SELECT count(*) FROM pg_catalog.pg_stat_activity a
                   JOIN pg_catalog.pg_roles r ON r.rolname = a.usename
                  WHERE r.rolsuper AND a.client_addr IS NOT NULL AND a.backend_type = 'client backend'",
            );
        }

        return new RoleCatalogSnapshot(
            currentDatabase: (string) $database['name'],
            roles: $roles,
            memberships: $memberships,
            databaseOwner: (string) $database['owner'],
            databasePrivileges: self::acl($database['acl']),
            schemas: $schemaRows,
            relations: $relations,
            routinesAndTypes: $routinesAndTypes,
            ownerDefaultPrivileges: $defaults,
            backupCatalogAccess: $backupCatalogAccess,
            superuserClientConnections: $superuserClientConnections,
        );
    }

    /** @return array<string, list<string>> */
    private static function acl(mixed $json): array
    {
        $grants = [];
        foreach ((array) json_decode((string) $json, true, 8, JSON_THROW_ON_ERROR) as $entry) {
            if (\is_array($entry)) {
                $grants[(string) $entry['grantee']][] = (string) $entry['privilege'];
            }
        }

        return self::normalise($grants);
    }

    /**
     * @param array<string, list<string>> $grants
     * @return array<string, list<string>>
     */
    private static function normalise(array $grants): array
    {
        ksort($grants);
        foreach ($grants as $grantee => $privileges) {
            $privileges = array_values(array_unique($privileges));
            sort($privileges);
            $grants[$grantee] = $privileges;
        }

        return $grants;
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }
}
