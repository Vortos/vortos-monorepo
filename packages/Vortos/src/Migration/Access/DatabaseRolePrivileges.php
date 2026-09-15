<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

/**
 * The direct privileges each audience holds, shared by the convergence SQL and the conformance inspector so the
 * statement that grants a privilege and the check that demands it can never disagree.
 *
 * Deliberately absent: TRUNCATE (it bypasses row triggers, including the append-only ledger guards), REFERENCES,
 * TRIGGER and MAINTAIN on tables; CREATE on the database or any schema; TEMPORARY (no runtime path creates a
 * temporary table). PUBLIC holds nothing on governed objects.
 */
final class DatabaseRolePrivileges
{
    /** Runtime on every table, view and foreign table; backup on the tables of its writable modules. */
    public const TABLE_DML = ['DELETE', 'INSERT', 'SELECT', 'UPDATE'];

    /** nextval() needs USAGE, currval() SELECT, setval() UPDATE. */
    public const SEQUENCE_USE = ['SELECT', 'UPDATE', 'USAGE'];

    public const DATABASE_CONNECT = ['CONNECT'];

    public const SCHEMA_USAGE = ['USAGE'];

    /**
     * Catalog objects the backup role reads beyond pg_read_all_settings: pg_file_settings needs SELECT on the view
     * AND EXECUTE on the function behind it (measured on 18 — either alone is "permission denied").
     */
    public const BACKUP_CATALOG_GRANTS = [
        'GRANT SELECT ON pg_catalog.pg_file_settings TO %s',
        'GRANT EXECUTE ON FUNCTION pg_catalog.pg_show_all_file_settings() TO %s',
    ];
}
