<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

final readonly class PostgresSettingsSnapshot
{
    /**
     * @param list<array{name: string, source: string, sourcefile: string}> $nonDefaultSettings settings not at their built-in default
     * @param list<array{name: string, sourcefile: string, error: string}>   $fileErrors         config file lines the server could not apply
     * @param list<array{name: string, scope: string}>                       $catalogSettings    ALTER ROLE / ALTER DATABASE … SET, cluster-wide
     * @param bool $sourcesVisible whether the reading role can see sourcefile AND pg_file_settings; without it every
     *                             file-sourced setting reads as sourcefile '' and file errors read as none
     * @param bool $clusterPrivileged whether the reading role is one that watches the cluster at all (superuser or
     *                             pg_read_all_settings). A role without it — the least-privilege application role —
     *                             is not a failing watcher, it is not the watcher; only a privileged role that still
     *                             cannot read pg_file_settings is a check that has been blinded.
     */
    public function __construct(
        public string $configFile,
        public bool $alterSystemAllowed,
        public array $nonDefaultSettings,
        public array $fileErrors,
        public array $catalogSettings,
        public bool $sourcesVisible,
        public bool $clusterPrivileged = false,
    ) {}
}
