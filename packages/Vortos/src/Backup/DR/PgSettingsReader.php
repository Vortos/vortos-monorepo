<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use Doctrine\DBAL\Connection;

/**
 * Reads where the running cluster's settings come from, over the application connection.
 *
 * Sources that are not configuration are excluded on purpose: `default` is PostgreSQL's own value,
 * `override` is set by the server itself (config_file, data_directory, hba_file, data_checksums), and
 * `client` / `session` belong to this connection, not to the cluster. `database` / `user` sources are
 * excluded too: pg_settings shows them only for the connecting role and database, so they are read
 * cluster-wide from pg_db_role_setting instead — names and scopes, never values.
 */
final class PgSettingsReader implements PostgresSettingsReaderInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function read(): PostgresSettingsSnapshot
    {
        // Without these PostgreSQL hides sourcefile (NULL), refuses `SHOW config_file` and refuses
        // pg_file_settings (measured on 18: pg_read_all_settings alone is not enough for the last), which
        // would make a clean cluster look drifted and a broken file look clean. Checked first, so a role
        // that cannot see is reported as such and nothing is guessed.
        $sourcesVisible = (bool) $this->connection->fetchOne(
            "SELECT (current_setting('is_superuser') = 'on'
                     OR pg_has_role(current_user, 'pg_read_all_settings', 'USAGE'))
                AND has_table_privilege('pg_catalog.pg_file_settings', 'SELECT')",
        );

        if (!$sourcesVisible) {
            return new PostgresSettingsSnapshot(
                configFile: '',
                alterSystemAllowed: (string) $this->connection->fetchOne('SHOW allow_alter_system') === 'on',
                nonDefaultSettings: [],
                fileErrors: [],
                catalogSettings: [],
                sourcesVisible: false,
            );
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT name, source, COALESCE(sourcefile, '') AS sourcefile
               FROM pg_settings
              WHERE source NOT IN ('default', 'override', 'client', 'session', 'database', 'user', 'database user')
              ORDER BY name",
        );

        $errors = $this->connection->fetchAllAssociative(
            "SELECT name, COALESCE(sourcefile, '') AS sourcefile, error
               FROM pg_file_settings
              WHERE error IS NOT NULL
              ORDER BY sourcefile, sourceline",
        );

        $catalog = $this->connection->fetchAllAssociative(
            "SELECT split_part(cfg, '=', 1) AS name,
                    CASE WHEN s.setdatabase = 0 THEN 'role ' || r.rolname
                         WHEN s.setrole = 0 THEN 'database ' || d.datname
                         ELSE 'role ' || r.rolname || ' in database ' || d.datname
                    END AS scope
               FROM pg_catalog.pg_db_role_setting s
              CROSS JOIN LATERAL unnest(s.setconfig) AS cfg
               LEFT JOIN pg_catalog.pg_roles r ON r.oid = s.setrole
               LEFT JOIN pg_catalog.pg_database d ON d.oid = s.setdatabase
              ORDER BY scope, name",
        );

        return new PostgresSettingsSnapshot(
            configFile: (string) $this->connection->fetchOne('SHOW config_file'),
            alterSystemAllowed: (string) $this->connection->fetchOne('SHOW allow_alter_system') === 'on',
            nonDefaultSettings: array_map(static fn (array $r): array => [
                'name' => (string) $r['name'],
                'source' => (string) $r['source'],
                'sourcefile' => (string) $r['sourcefile'],
            ], $rows),
            fileErrors: array_map(static fn (array $r): array => [
                'name' => (string) ($r['name'] ?? ''),
                'sourcefile' => (string) $r['sourcefile'],
                'error' => (string) $r['error'],
            ], $errors),
            catalogSettings: array_map(static fn (array $r): array => [
                'name' => (string) $r['name'],
                'scope' => (string) $r['scope'],
            ], $catalog),
            sourcesVisible: true,
        );
    }
}
