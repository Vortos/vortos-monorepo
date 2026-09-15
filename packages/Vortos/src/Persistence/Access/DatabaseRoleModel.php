<?php

declare(strict_types=1);

namespace Vortos\Persistence\Access;

/**
 * Who may do what in the application database: one role per credential audience.
 *
 * WHY. Production ran every process — web, workers, the backup sidecar, migrations — as one role that was
 * SUPERUSER and BYPASSRLS and owned every object. Row-level security on the tenant-scoped tables was therefore
 * decoration: FORCE ROW LEVEL SECURITY binds the table owner, but a superuser and a BYPASSRLS role are exempt,
 * so a tenant-bound session read every tenant's rows. And any SQL injection, or any compromised container, held
 * the whole cluster: DDL, replication, reading server files, creating roles.
 *
 * The model names the four roles that replace it, each holding only what its audience needs:
 *
 *  - {@see $owner}     owns the database, the declared schemas and every object in them. Used only by the deploy
 *                      tooling (migrations, schema installers). NOSUPERUSER, NOBYPASSRLS, no replication.
 *  - {@see $runtime}   the application: SELECT/INSERT/UPDATE/DELETE on tables and USAGE on sequences, owns nothing,
 *                      cannot create objects, and is subject to row-level security.
 *  - {@see $backup}    the backup node: REPLICATION (base backups), BYPASSRLS + pg_read_all_data (a logical dump
 *                      must see every row), the monitoring and CHECKPOINT roles its probes and waker use, and write
 *                      access ONLY to the tables of {@see $backupWritableModules}.
 *  - {@see $bootstrapSuperuser} the initdb role. It stays SUPERUSER as the break-glass account reached through the
 *                      server's local socket; no client may connect to it over the network.
 *
 * Every field is required: which schemas are governed and which modules the backup node writes are decisions,
 * not omissions. `$backup` is nullable only as an explicit statement that no backup node connects.
 */
final readonly class DatabaseRoleModel
{
    /**
     * @param list<string> $schemas               schemas whose objects the owner owns and the runtime reads and writes
     * @param list<string> $backupWritableModules framework modules (e.g. "Backup", "Scheduler") whose tables the backup node writes
     */
    public function __construct(
        public string $database,
        public array $schemas,
        public DatabaseRoleName $bootstrapSuperuser,
        public DatabaseRoleName $owner,
        public DatabaseRoleName $runtime,
        public ?DatabaseRoleName $backup,
        public array $backupWritableModules,
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $database) !== 1) {
            throw new \InvalidArgumentException(sprintf('The database name must match ^[a-z_][a-z0-9_]{0,62}$, got "%s".', $database));
        }

        // config/persistence.php is untyped PHP: the list/string shapes the docblock promises are enforced here, not assumed.
        // @phpstan-ignore function.alreadyNarrowedType
        if ($schemas === [] || !array_is_list($schemas) || \count(array_unique($schemas)) !== \count($schemas)) {
            throw new \InvalidArgumentException('Declare at least one schema, each once.');
        }
        foreach ($schemas as $schema) {
            // @phpstan-ignore function.alreadyNarrowedType
            if (!\is_string($schema) || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $schema) !== 1 || str_starts_with($schema, 'pg_')) {
                // @phpstan-ignore function.alreadyNarrowedType
                throw new \InvalidArgumentException(sprintf('Schema names must match ^[a-z_][a-z0-9_]{0,62}$ and not start with "pg_", got "%s".', \is_string($schema) ? $schema : get_debug_type($schema)));
            }
        }

        $names = array_map(static fn (?DatabaseRoleName $n): ?string => $n?->value, [$bootstrapSuperuser, $owner, $runtime, $backup]);
        $present = array_values(array_filter($names, static fn (?string $n): bool => $n !== null));
        if (\count(array_unique($present)) !== \count($present)) {
            throw new \InvalidArgumentException(sprintf(
                'The bootstrap, owner, runtime and backup roles must be four distinct roles, got [%s]: sharing one role between audiences is the defect this model removes.',
                implode(', ', $present),
            ));
        }

        // @phpstan-ignore function.alreadyNarrowedType
        if (!array_is_list($backupWritableModules) || \count(array_unique($backupWritableModules)) !== \count($backupWritableModules)) {
            throw new \InvalidArgumentException('Declare each backup-writable module once.');
        }
        foreach ($backupWritableModules as $module) {
            // @phpstan-ignore function.alreadyNarrowedType
            if (!\is_string($module) || preg_match('/^[A-Z][A-Za-z0-9]*$/', $module) !== 1) {
                // @phpstan-ignore function.alreadyNarrowedType
                throw new \InvalidArgumentException(sprintf('Module names are PascalCase framework module names (e.g. "Backup"), got "%s".', \is_string($module) ? $module : get_debug_type($module)));
            }
        }

        if ($backup === null && $backupWritableModules !== []) {
            throw new \InvalidArgumentException('Backup-writable modules were declared without a backup role to grant them to.');
        }
    }

    /** @return array{database: string, schemas: list<string>, bootstrap_superuser: string, owner: string, runtime: string, backup: string|null, backup_writable_modules: list<string>} */
    public function toArray(): array
    {
        return [
            'database' => $this->database,
            'schemas' => $this->schemas,
            'bootstrap_superuser' => $this->bootstrapSuperuser->value,
            'owner' => $this->owner->value,
            'runtime' => $this->runtime->value,
            'backup' => $this->backup?->value,
            'backup_writable_modules' => $this->backupWritableModules,
        ];
    }

    /** @param array<mixed> $data the shape {@see toArray()} produces */
    public static function fromArray(array $data): self
    {
        foreach (['database', 'schemas', 'bootstrap_superuser', 'owner', 'runtime', 'backup_writable_modules'] as $key) {
            if (!\array_key_exists($key, $data)) {
                throw new \InvalidArgumentException(sprintf('The database role declaration is missing "%s".', $key));
            }
        }
        if (!\array_key_exists('backup', $data)) {
            throw new \InvalidArgumentException('The database role declaration is missing "backup" (null states that no backup node connects).');
        }

        return new self(
            database: (string) $data['database'],
            schemas: array_values((array) $data['schemas']),
            bootstrapSuperuser: new DatabaseRoleName((string) $data['bootstrap_superuser']),
            owner: new DatabaseRoleName((string) $data['owner']),
            runtime: new DatabaseRoleName((string) $data['runtime']),
            backup: $data['backup'] === null ? null : new DatabaseRoleName((string) $data['backup']),
            backupWritableModules: array_values((array) $data['backup_writable_modules']),
        );
    }
}
