<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

use Vortos\Persistence\Access\DatabaseRoleModel;
use Vortos\Persistence\Access\DatabaseRoleName;

/**
 * Renders the idempotent SQL that brings a database to its {@see DatabaseRoleModel}, split by
 * {@see ConvergenceAuthority}.
 *
 * Both tiers are safe on a live database:
 *
 *  - The owner tier is ONE transaction that revokes and re-grants exactly, so no session ever observes a role
 *    between losing a privilege and regaining it.
 *  - The superuser tier moves ownership ONE object per transaction under a short lock_timeout, retrying on lock
 *    contention. ALTER … OWNER takes an ACCESS EXCLUSIVE lock; holding it on every table until a single commit
 *    would stall the application for as long as the slowest lock wait.
 *
 * No statement carries a password: passwords arrive as SCRAM verifiers ({@see ScramSha256Verifier}). Every
 * identifier is validated by the model before it reaches this class and is still quoted here.
 */
final class DatabaseRoleConvergenceSql
{
    /**
     * Superuser tier, as statements to run ONE AT A TIME in autocommit mode (psql's default) in the governed database.
     * Never send them as one multi-statement string: PostgreSQL runs such a string as a single implicit transaction,
     * where the ownership loop's per-object COMMIT is refused.
     *
     * @param array<string, string> $verifiers role name => SCRAM verifier; roles absent keep their current password
     * @return list<string>
     */
    public function superuserStatements(DatabaseRoleModel $model, array $verifiers, bool $clearBootstrapPassword): array
    {
        $owner = $model->owner->value;
        $sql = ["SET lock_timeout = '2s'"];

        foreach ([DatabaseRoleKind::Owner, DatabaseRoleKind::Runtime, DatabaseRoleKind::Backup] as $kind) {
            $role = $kind->roleIn($model);
            if ($role === null) {
                continue;
            }
            $sql[] = sprintf(
                "DO \$vortos\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = %s) THEN CREATE ROLE %s; END IF; END \$vortos\$",
                self::literal($role->value),
                self::ident($role->value),
            );

            $attributes = [];
            foreach ($kind->attributes() as $attribute => $on) {
                $attributes[] = DatabaseRoleKind::keywords()[$attribute][$on ? 0 : 1];
            }
            $sql[] = sprintf('ALTER ROLE %s WITH %s INHERIT', self::ident($role->value), implode(' ', $attributes));

            if (isset($verifiers[$role->value])) {
                if (preg_match('#^SCRAM-SHA-256\$\d+:[A-Za-z0-9+/=]+\$[A-Za-z0-9+/=]+:[A-Za-z0-9+/=]+$#', $verifiers[$role->value]) !== 1) {
                    throw new \InvalidArgumentException(sprintf('The password for role "%s" must be a SCRAM-SHA-256 verifier, never a password.', $role->value));
                }
                $sql[] = sprintf("ALTER ROLE %s PASSWORD '%s'", self::ident($role->value), $verifiers[$role->value]);
            }

            // Exactly the declared memberships: revoke the rest (naming the grantor, which PostgreSQL 16+ requires),
            // then grant the declared ones.
            $sql[] = sprintf(
                "DO \$vortos\$ DECLARE m record; BEGIN FOR m IN SELECT g.rolname AS granted, u.rolname AS member, gr.rolname AS grantor FROM pg_catalog.pg_auth_members am JOIN pg_catalog.pg_roles g ON g.oid = am.roleid JOIN pg_catalog.pg_roles u ON u.oid = am.member JOIN pg_catalog.pg_roles gr ON gr.oid = am.grantor WHERE u.rolname = %s AND NOT (g.rolname = ANY (%s::text[])) LOOP EXECUTE format('REVOKE %%I FROM %%I GRANTED BY %%I', m.granted, m.member, m.grantor); END LOOP; END \$vortos\$",
                self::literal($role->value),
                self::literal('{' . implode(',', $kind->memberships()) . '}'),
            );
            if ($kind->memberships() !== []) {
                $sql[] = sprintf('GRANT %s TO %s', implode(', ', array_map(self::ident(...), $kind->memberships())), self::ident($role->value));
            }
            if ($kind === DatabaseRoleKind::Backup) {
                foreach (DatabaseRolePrivileges::BACKUP_CATALOG_GRANTS as $grant) {
                    $sql[] = sprintf($grant, self::ident($role->value));
                }
            }
        }

        if ($clearBootstrapPassword) {
            // With no password the SCRAM rule can never authenticate it, so the superuser is reachable only through the
            // server's local socket — from inside the database container.
            $sql[] = sprintf('ALTER ROLE %s PASSWORD NULL', self::ident($model->bootstrapSuperuser->value));
        }

        $sql[] = sprintf('ALTER DATABASE %s OWNER TO %s', self::ident($model->database), self::ident($owner));

        $schemas = self::literal('{' . implode(',', $model->schemas) . '}');
        $ownerLiteral = self::literal($owner);
        $sql[] = sprintf(
            "DO \$vortos\$ DECLARE s record; BEGIN FOR s IN SELECT nspname FROM pg_catalog.pg_namespace WHERE nspname = ANY (%s::text[]) AND nspowner <> %s::regrole AND nspowner <> 'pg_database_owner'::regrole LOOP EXECUTE format('ALTER SCHEMA %%I OWNER TO %%I', s.nspname, %s); END LOOP; END \$vortos\$",
            $schemas,
            self::literal($owner),
            self::literal($owner),
        );

        $sql[] = <<<SQL
            DO \$vortos\$
            DECLARE
                target record;
                attempts integer;
                done boolean;
            BEGIN
                FOR target IN
                    SELECT format('ALTER %s %I.%I OWNER TO %I',
                                  CASE c.relkind WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'f' THEN 'FOREIGN TABLE' WHEN 'S' THEN 'SEQUENCE' ELSE 'TABLE' END,
                                  n.nspname, c.relname, {$ownerLiteral}) AS statement
                      FROM pg_catalog.pg_class c
                      JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = ANY ({$schemas}::text[])
                       AND c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S')
                       AND c.relowner <> {$ownerLiteral}::regrole
                       AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend e WHERE e.classid = 'pg_catalog.pg_class'::regclass AND e.objid = c.oid AND e.deptype = 'e')
                       -- a sequence owned by a column moves with its table
                       AND NOT (c.relkind = 'S' AND EXISTS (SELECT 1 FROM pg_catalog.pg_depend d WHERE d.classid = 'pg_catalog.pg_class'::regclass AND d.objid = c.oid AND d.refclassid = 'pg_catalog.pg_class'::regclass AND d.deptype IN ('a', 'i')))
                    UNION ALL
                    SELECT format('ALTER %s %I.%I(%s) OWNER TO %I',
                                  CASE p.prokind WHEN 'a' THEN 'AGGREGATE' ELSE 'ROUTINE' END,
                                  n.nspname, p.proname, pg_catalog.pg_get_function_identity_arguments(p.oid), {$ownerLiteral})
                      FROM pg_catalog.pg_proc p
                      JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                     WHERE n.nspname = ANY ({$schemas}::text[])
                       AND p.proowner <> {$ownerLiteral}::regrole
                       AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend e WHERE e.classid = 'pg_catalog.pg_proc'::regclass AND e.objid = p.oid AND e.deptype = 'e')
                    UNION ALL
                    SELECT format('ALTER %s %I.%I OWNER TO %I',
                                  CASE t.typtype WHEN 'd' THEN 'DOMAIN' ELSE 'TYPE' END,
                                  n.nspname, t.typname, {$ownerLiteral})
                      FROM pg_catalog.pg_type t
                      JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace
                      LEFT JOIN pg_catalog.pg_class c ON c.oid = t.typrelid
                     WHERE n.nspname = ANY ({$schemas}::text[])
                       AND (t.typtype IN ('e', 'd', 'r') OR (t.typtype = 'c' AND c.relkind = 'c'))
                       AND t.typowner <> {$ownerLiteral}::regrole
                       AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend e WHERE e.classid = 'pg_catalog.pg_type'::regclass AND e.objid = t.oid AND e.deptype = 'e')
                LOOP
                    attempts := 0;
                    done := false;
                    WHILE NOT done LOOP
                        BEGIN
                            EXECUTE target.statement;
                            done := true;
                        EXCEPTION WHEN lock_not_available THEN
                            attempts := attempts + 1;
                            IF attempts >= 30 THEN
                                RAISE;
                            END IF;
                        END;
                        -- One object per transaction: its ACCESS EXCLUSIVE lock is released before the next is taken.
                        COMMIT;
                        IF NOT done THEN
                            PERFORM pg_catalog.pg_sleep(1);
                        END IF;
                    END LOOP;
                END LOOP;
            END
            \$vortos\$
            SQL;

        return $sql;
    }

    /**
     * Owner tier, as statements to run in ONE transaction by the owner role (or the bootstrap superuser).
     *
     * @param list<string> $backupWritableTables schema-qualified tables of the backup role's writable modules
     * @return list<string>
     */
    public function ownerStatements(DatabaseRoleModel $model, array $backupWritableTables): array
    {
        $db = self::ident($model->database);
        $owner = self::ident($model->owner->value);
        $runtime = self::ident($model->runtime->value);
        $backup = $model->backup === null ? null : self::ident($model->backup->value);

        $sql = [
            "SET LOCAL lock_timeout = '5s'",
            sprintf('REVOKE ALL ON DATABASE %s FROM PUBLIC', $db),
            sprintf('REVOKE ALL ON DATABASE %s FROM %s', $db, $runtime),
            sprintf('GRANT CONNECT ON DATABASE %s TO %s', $db, $runtime),
        ];
        if ($backup !== null) {
            $sql[] = sprintf('REVOKE ALL ON DATABASE %s FROM %s', $db, $backup);
            $sql[] = sprintf('GRANT CONNECT ON DATABASE %s TO %s', $db, $backup);
        }

        foreach ($model->schemas as $schemaName) {
            $schema = self::ident($schemaName);
            $grantees = $backup === null ? $runtime : $runtime . ', ' . $backup;

            array_push(
                $sql,
                sprintf('REVOKE ALL ON SCHEMA %s FROM PUBLIC, %s', $schema, $grantees),
                sprintf('GRANT USAGE ON SCHEMA %s TO %s', $schema, $grantees),
                sprintf('REVOKE ALL ON ALL TABLES IN SCHEMA %s FROM PUBLIC, %s', $schema, $grantees),
                sprintf('GRANT %s ON ALL TABLES IN SCHEMA %s TO %s', implode(', ', DatabaseRolePrivileges::TABLE_DML), $schema, $runtime),
                sprintf('REVOKE ALL ON ALL SEQUENCES IN SCHEMA %s FROM PUBLIC, %s', $schema, $grantees),
                sprintf('GRANT %s ON ALL SEQUENCES IN SCHEMA %s TO %s', implode(', ', DatabaseRolePrivileges::SEQUENCE_USE), $schema, $runtime),
                sprintf('ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s REVOKE ALL ON TABLES FROM PUBLIC, %s', $owner, $schema, $grantees),
                sprintf('ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s GRANT %s ON TABLES TO %s', $owner, $schema, implode(', ', DatabaseRolePrivileges::TABLE_DML), $runtime),
                sprintf('ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s REVOKE ALL ON SEQUENCES FROM PUBLIC, %s', $owner, $schema, $grantees),
                sprintf('ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s GRANT %s ON SEQUENCES TO %s', $owner, $schema, implode(', ', DatabaseRolePrivileges::SEQUENCE_USE), $runtime),
            );
        }

        if ($backup !== null && $backupWritableTables !== []) {
            foreach ($backupWritableTables as $table) {
                if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $table) !== 1) {
                    throw new \InvalidArgumentException(sprintf('Refusing to grant on table name "%s": not a plain schema.table identifier.', $table));
                }
            }
            // to_regclass: a module table a later migration dropped needs no grant, and must not abort the release.
            $tables = self::literal('{' . implode(',', $backupWritableTables) . '}');
            $backupLiteral = self::literal($model->backup->value);
            $sql[] = sprintf(
                "DO \$vortos\$ DECLARE g record; BEGIN FOR g IN SELECT format('GRANT %s ON TABLE %%s TO %%I', to_regclass(m.name), %s) AS statement FROM unnest(%s::text[]) AS m(name) WHERE to_regclass(m.name) IS NOT NULL UNION ALL SELECT format('GRANT %s ON SEQUENCE %%s TO %%I', s.oid::regclass, %s) FROM unnest(%s::text[]) AS m(name) JOIN pg_catalog.pg_depend d ON d.refobjid = to_regclass(m.name) AND d.refclassid = 'pg_catalog.pg_class'::regclass AND d.classid = 'pg_catalog.pg_class'::regclass AND d.deptype IN ('a', 'i') JOIN pg_catalog.pg_class s ON s.oid = d.objid AND s.relkind = 'S' LOOP EXECUTE g.statement; END LOOP; END \$vortos\$",
                implode(', ', DatabaseRolePrivileges::TABLE_DML),
                $backupLiteral,
                $tables,
                implode(', ', DatabaseRolePrivileges::SEQUENCE_USE),
                $backupLiteral,
                $tables,
            );
        }

        return $sql;
    }

    private static function ident(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private static function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
