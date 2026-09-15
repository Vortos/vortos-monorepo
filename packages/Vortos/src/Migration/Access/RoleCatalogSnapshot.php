<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

/**
 * What the PostgreSQL catalog says about roles, ownership and privileges in the governed database.
 *
 * Privilege maps hold DIRECT grants only (aclexplode of the object's ACL, or of its built-in default when the ACL
 * is NULL), keyed by grantee — "PUBLIC" for grantee 0 — with the object owner's own implicit entry removed.
 * Privileges a role reaches through membership of a predefined role (pg_read_all_data) are not in them, by
 * design: memberships are checked separately, so each grant path is judged on its own.
 */
final readonly class RoleCatalogSnapshot
{
    /**
     * @param array<string, array{superuser: bool, replication: bool, createrole: bool, createdb: bool, bypassrls: bool, login: bool}> $roles every non-predefined role
     * @param array<string, list<string>> $memberships member => roles it is a member of (sorted)
     * @param array<string, list<string>> $databasePrivileges grantee => privileges on the current database
     * @param array<string, array{owner: string, privileges: array<string, list<string>>}> $schemas governed schema => owner + grants
     * @param list<array{schema: string, name: string, kind: string, owner: string, privileges: array<string, list<string>>, ownedBy: string|null}> $relations
     *        kind: table | view | materialized_view | foreign_table | sequence; ownedBy: "schema.table" for a sequence owned by a column
     * @param list<array{schema: string, name: string, kind: string, owner: string}> $routinesAndTypes kind: function | type
     * @param array<string, array{tables: array<string, list<string>>, sequences: array<string, list<string>>}> $ownerDefaultPrivileges schema => grantee => privileges
     * @param array{fileSettingsView: bool, fileSettingsFunction: bool}|null $backupCatalogAccess null when no backup role exists
     * @param int|null $superuserClientConnections null when this connection cannot see other sessions
     */
    public function __construct(
        public string $currentDatabase,
        public array $roles,
        public array $memberships,
        public string $databaseOwner,
        public array $databasePrivileges,
        public array $schemas,
        public array $relations,
        public array $routinesAndTypes,
        public array $ownerDefaultPrivileges,
        public ?array $backupCatalogAccess,
        public ?int $superuserClientConnections,
    ) {}
}
