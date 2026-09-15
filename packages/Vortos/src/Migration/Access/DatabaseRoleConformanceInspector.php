<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

use Vortos\Persistence\Access\DatabaseRoleModel;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;

/**
 * Holds the live database to the declared {@see DatabaseRoleModel}, in both directions.
 *
 * WHY. A least-privilege role set is only as good as its last hand-typed GRANT: one `ALTER ROLE … SUPERUSER`
 * to get past an incident, one table created by a role other than the owner, one privilege left on PUBLIC, and
 * the application is back to bypassing row-level security with nothing to say so. This inspector turns each of
 * those into a named finding: every declared role holds exactly its attributes, memberships and direct
 * privileges — nothing missing (the application would fail) and nothing extra (the boundary would be gone) —
 * the owner owns everything governed, no undeclared role can log in or is a superuser, and no client is
 * connected over the network as a superuser.
 *
 * It reads only the catalog and reports names and privilege words, never data.
 */
final class DatabaseRoleConformanceInspector
{
    private const SAMPLE = 5;

    public function __construct(
        private readonly RoleCatalogReaderInterface $reader,
        private readonly ModuleTableResolverInterface $tables,
        private readonly ?DeclaredDatabaseRoles $declared,
    ) {}

    public function inspect(): DatabaseRoleConformanceReport
    {
        $model = $this->declared?->model();
        if ($model === null) {
            return DatabaseRoleConformanceReport::undeclared();
        }

        try {
            $moduleTables = $model->backup === null ? [] : $this->tables->tablesOf($model->backupWritableModules);
            $snapshot = $this->reader->read($model);
        } catch (\Throwable $e) {
            return DatabaseRoleConformanceReport::indeterminate($e::class);
        }

        if ($snapshot->currentDatabase !== $model->database) {
            return DatabaseRoleConformanceReport::indeterminate(sprintf(
                'connected to database "%s", the role model governs "%s"',
                $snapshot->currentDatabase,
                $model->database,
            ));
        }

        $violations = [];
        $this->roles($model, $snapshot, $violations);
        $this->database($model, $snapshot, $violations);
        $this->schemas($model, $snapshot, $violations);
        $this->objects($model, $snapshot, array_flip($moduleTables), $violations);
        $this->defaults($model, $snapshot, $violations);

        if ($model->backup !== null && $snapshot->backupCatalogAccess !== null) {
            if (!$snapshot->backupCatalogAccess['fileSettingsView']) {
                $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::MissingPrivilege, $model->backup->value, 'view pg_catalog.pg_file_settings', 'lacks SELECT');
            }
            if (!$snapshot->backupCatalogAccess['fileSettingsFunction']) {
                $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::MissingPrivilege, $model->backup->value, 'function pg_catalog.pg_show_all_file_settings()', 'lacks EXECUTE');
            }
        }

        if (($snapshot->superuserClientConnections ?? 0) > 0) {
            $violations[] = new DatabaseRoleViolation(
                DatabaseRoleViolationKind::SuperuserClientConnection,
                '*',
                'pg_stat_activity',
                sprintf('%d network client session(s) connected as a superuser', $snapshot->superuserClientConnections),
            );
        }

        return DatabaseRoleConformanceReport::of($violations, $snapshot->superuserClientConnections !== null);
    }

    /** @param list<DatabaseRoleViolation> $violations */
    private function roles(DatabaseRoleModel $model, RoleCatalogSnapshot $snapshot, array &$violations): void
    {
        $declared = [];
        foreach (DatabaseRoleKind::cases() as $kind) {
            $role = $kind->roleIn($model);
            if ($role === null) {
                continue;
            }
            $declared[$role->value] = true;

            $actual = $snapshot->roles[$role->value] ?? null;
            if ($actual === null) {
                $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::RoleMissing, $role->value, $kind->value . ' role', 'does not exist');
                continue;
            }

            foreach ($kind->attributes() as $attribute => $expected) {
                if ($actual[$attribute] !== $expected) {
                    [$on, $off] = DatabaseRoleKind::keywords()[$attribute];
                    $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::RoleAttribute, $role->value, $kind->value . ' role', sprintf('is %s, must be %s', $actual[$attribute] ? $on : $off, $expected ? $on : $off));
                }
            }

            if ($kind === DatabaseRoleKind::Bootstrap) {
                continue;
            }
            $member = $snapshot->memberships[$role->value] ?? [];
            foreach (array_diff($member, $kind->memberships()) as $extra) {
                $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::RoleMembership, $role->value, 'membership ' . $extra, 'is a member, must not be');
            }
            foreach (array_diff($kind->memberships(), $member) as $missing) {
                $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::RoleMembership, $role->value, 'membership ' . $missing, 'is not a member, must be');
            }
        }

        foreach ($snapshot->roles as $name => $attributes) {
            if (isset($declared[$name])) {
                continue;
            }
            if ($attributes['superuser']) {
                $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::UndeclaredRole, $name, 'undeclared role', 'is SUPERUSER');
            } elseif ($attributes['login']) {
                $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::UndeclaredRole, $name, 'undeclared role', 'can log in');
            }
        }
    }

    /** @param list<DatabaseRoleViolation> $violations */
    private function database(DatabaseRoleModel $model, RoleCatalogSnapshot $snapshot, array &$violations): void
    {
        $subject = 'database ' . $model->database;
        if ($snapshot->databaseOwner !== $model->owner->value) {
            $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::Ownership, $snapshot->databaseOwner, $subject, sprintf('owns it, the owner role is %s', $model->owner->value));
        }

        $expected = [$model->runtime->value => DatabaseRolePrivileges::DATABASE_CONNECT];
        if ($model->backup !== null) {
            $expected[$model->backup->value] = DatabaseRolePrivileges::DATABASE_CONNECT;
        }
        $this->compare($model, $subject, $snapshot->databasePrivileges, $expected, $violations);
    }

    /** @param list<DatabaseRoleViolation> $violations */
    private function schemas(DatabaseRoleModel $model, RoleCatalogSnapshot $snapshot, array &$violations): void
    {
        foreach ($model->schemas as $schema) {
            $subject = 'schema ' . $schema;
            $actual = $snapshot->schemas[$schema] ?? null;
            if ($actual === null) {
                $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::Ownership, $model->owner->value, $subject, 'does not exist');
                continue;
            }

            // PostgreSQL 15+ creates `public` owned by pg_database_owner, which IS the database owner by definition —
            // so the role to name is whoever owns the database.
            $effectiveOwner = $actual['owner'] === 'pg_database_owner' ? $snapshot->databaseOwner : $actual['owner'];
            if ($effectiveOwner !== $model->owner->value) {
                $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::Ownership, $effectiveOwner, $subject, sprintf('owns it, the owner role is %s', $model->owner->value));
            }

            $expected = [$model->runtime->value => DatabaseRolePrivileges::SCHEMA_USAGE];
            if ($model->backup !== null) {
                $expected[$model->backup->value] = DatabaseRolePrivileges::SCHEMA_USAGE;
            }
            $this->compare($model, $subject, $actual['privileges'], $expected, $violations);
        }
    }

    /**
     * @param array<string, int>          $moduleTables flipped list of backup-writable tables
     * @param list<DatabaseRoleViolation> $violations
     */
    private function objects(DatabaseRoleModel $model, RoleCatalogSnapshot $snapshot, array $moduleTables, array &$violations): void
    {
        /** @var array<string, list<string>> $grouped kind|role|subject-kind|detail => names */
        $grouped = [];

        foreach ($snapshot->relations as $relation) {
            $name = $relation['schema'] . '.' . $relation['name'];
            $objectKind = str_replace('_', ' ', $relation['kind']);

            if ($relation['owner'] !== $model->owner->value) {
                $grouped[implode('|', [DatabaseRoleViolationKind::Ownership->value, $relation['owner'], $objectKind, 'owns it, the owner role is ' . $model->owner->value])][] = $name;
            }

            $isSequence = $relation['kind'] === 'sequence';
            $expected = [$model->runtime->value => $isSequence ? DatabaseRolePrivileges::SEQUENCE_USE : DatabaseRolePrivileges::TABLE_DML];
            if ($model->backup !== null) {
                $writable = $isSequence
                    ? ($relation['ownedBy'] !== null && isset($moduleTables[$relation['ownedBy']]))
                    : ($relation['kind'] === 'table' && isset($moduleTables[$name]));
                if ($writable) {
                    $expected[$model->backup->value] = $isSequence ? DatabaseRolePrivileges::SEQUENCE_USE : DatabaseRolePrivileges::TABLE_DML;
                }
            }

            foreach ($this->differences($model, $relation['privileges'], $expected) as [$kind, $role, $detail]) {
                $grouped[implode('|', [$kind->value, $role, $objectKind, $detail])][] = $name;
            }
        }

        foreach ($snapshot->routinesAndTypes as $object) {
            if ($object['owner'] !== $model->owner->value) {
                $grouped[implode('|', [DatabaseRoleViolationKind::Ownership->value, $object['owner'], $object['kind'], 'owns it, the owner role is ' . $model->owner->value])][] = $object['schema'] . '.' . $object['name'];
            }
        }

        foreach ($grouped as $key => $names) {
            [$kind, $role, $objectKind, $detail] = explode('|', $key, 4);
            $subject = \count($names) === 1
                ? $objectKind . ' ' . $names[0]
                : sprintf('%d %ss (%s%s)', \count($names), $objectKind, implode(', ', \array_slice($names, 0, self::SAMPLE)), \count($names) > self::SAMPLE ? ', …' : '');
            $violations[] = new DatabaseRoleViolation(DatabaseRoleViolationKind::from($kind), $role, $subject, $detail);
        }
    }

    /** @param list<DatabaseRoleViolation> $violations */
    private function defaults(DatabaseRoleModel $model, RoleCatalogSnapshot $snapshot, array &$violations): void
    {
        foreach ($model->schemas as $schema) {
            $defaults = $snapshot->ownerDefaultPrivileges[$schema] ?? ['tables' => [], 'sequences' => []];
            foreach (['tables' => DatabaseRolePrivileges::TABLE_DML, 'sequences' => DatabaseRolePrivileges::SEQUENCE_USE] as $bucket => $required) {
                foreach ($this->differences($model, $defaults[$bucket], [$model->runtime->value => $required]) as [, $role, $detail]) {
                    $violations[] = new DatabaseRoleViolation(
                        DatabaseRoleViolationKind::DefaultPrivileges,
                        $role,
                        sprintf('future %s the owner creates in schema %s', $bucket, $schema),
                        $detail,
                    );
                }
            }
        }
    }

    /**
     * @param array<string, list<string>>  $actual
     * @param array<string, list<string>>  $expected
     * @param list<DatabaseRoleViolation>  $violations
     */
    private function compare(DatabaseRoleModel $model, string $subject, array $actual, array $expected, array &$violations): void
    {
        foreach ($this->differences($model, $actual, $expected) as [$kind, $role, $detail]) {
            $violations[] = new DatabaseRoleViolation($kind, $role, $subject, $detail);
        }
    }

    /**
     * Direct grants against the model. The owner and the bootstrap superuser are skipped: both already hold every
     * privilege on governed objects, so an explicit grant to them widens nothing.
     *
     * @param array<string, list<string>> $actual
     * @param array<string, list<string>> $expected
     * @return list<array{DatabaseRoleViolationKind, string, string}>
     */
    private function differences(DatabaseRoleModel $model, array $actual, array $expected): array
    {
        $found = [];
        $grantees = array_unique([...array_keys($actual), ...array_keys($expected)]);
        sort($grantees);

        foreach ($grantees as $grantee) {
            if ($grantee === $model->owner->value || $grantee === $model->bootstrapSuperuser->value || $grantee === 'pg_database_owner') {
                continue;
            }
            $has = $actual[$grantee] ?? [];
            $needs = $expected[$grantee] ?? [];

            $extra = array_values(array_diff($has, $needs));
            if ($extra !== []) {
                $found[] = [DatabaseRoleViolationKind::ExcessPrivilege, (string) $grantee, 'has ' . implode(', ', $extra)];
            }
            $missing = array_values(array_diff($needs, $has));
            if ($missing !== []) {
                $found[] = [DatabaseRoleViolationKind::MissingPrivilege, (string) $grantee, 'lacks ' . implode(', ', $missing)];
            }
        }

        return $found;
    }
}
