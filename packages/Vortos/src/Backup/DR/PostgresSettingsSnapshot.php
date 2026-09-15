<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

final readonly class PostgresSettingsSnapshot
{
    /**
     * @param list<array{name: string, source: string, sourcefile: string}> $nonDefaultSettings settings not at their built-in default
     * @param list<array{name: string, sourcefile: string, error: string}>   $fileErrors         config file lines the server could not apply
     * @param list<array{name: string, scope: string}>                       $catalogSettings    ALTER ROLE / ALTER DATABASE … SET, cluster-wide
     * @param bool $sourcesVisible whether the reading role can see sourcefile and pg_file_settings; without it every
     *                             file-sourced setting reads as sourcefile '' and file errors read as none
     */
    public function __construct(
        public string $configFile,
        public bool $alterSystemAllowed,
        public array $nonDefaultSettings,
        public array $fileErrors,
        public array $catalogSettings,
        public bool $sourcesVisible,
    ) {}
}
