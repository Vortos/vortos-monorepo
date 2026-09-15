<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

/**
 * Proves the running PostgreSQL cluster is configured by exactly the file the release declares (RC-5).
 *
 * WHY. The 2 MiB WAL rebuild needed four ALTER SYSTEM settings, the archive command and the replication
 * rule applied by hand; those lived only in the data volume and in compose `-c` flags, so the database's
 * real configuration was nowhere a reviewer could read and a recreated volume would have silently lost
 * part of it. With the configuration baked into the image, the remaining risk is a hand-applied change
 * that quietly overrides it. This inspector turns "configured by hand" into a finding: every setting that
 * is not a built-in default must come from the declared file, ALTER SYSTEM must be off, and the file must
 * apply cleanly. It compares sources, not values, so it needs no copy of the file and never reports a value.
 */
final class PostgresConfigDriftInspector
{
    public function __construct(
        private readonly PostgresSettingsReaderInterface $reader,
        /** Absolute path of the configuration file baked into the image; null = not declared. */
        private readonly ?string $declaredConfigFile,
    ) {}

    public function inspect(): PostgresConfigDriftReport
    {
        if ($this->declaredConfigFile === null) {
            return PostgresConfigDriftReport::undeclared();
        }

        try {
            $snapshot = $this->reader->read();
        } catch (\Throwable $e) {
            return PostgresConfigDriftReport::indeterminate($e::class);
        }

        if (!$snapshot->sourcesVisible) {
            return PostgresConfigDriftReport::unverifiable($this->declaredConfigFile);
        }

        $drift = [];

        if ($snapshot->configFile !== $this->declaredConfigFile) {
            $drift[] = new PostgresConfigDrift(PostgresConfigDriftKind::UndeclaredConfigFile, 'config_file', $snapshot->configFile);
        }

        if ($snapshot->alterSystemAllowed) {
            $drift[] = new PostgresConfigDrift(PostgresConfigDriftKind::AlterSystemAllowed, 'allow_alter_system', 'on');
        }

        foreach ($snapshot->nonDefaultSettings as $setting) {
            $kind = match (true) {
                $setting['source'] === 'command line' => PostgresConfigDriftKind::CommandLineSetting,
                $setting['source'] === 'environment variable' => PostgresConfigDriftKind::EnvironmentSetting,
                \in_array($setting['source'], ['database', 'user', 'database user'], true) => PostgresConfigDriftKind::CatalogSetting,
                $setting['source'] === 'configuration file' && str_ends_with($setting['sourcefile'], '/postgresql.auto.conf') => PostgresConfigDriftKind::AlterSystemSetting,
                $setting['source'] === 'configuration file' && $setting['sourcefile'] === $this->declaredConfigFile => null,
                $setting['source'] === 'configuration file' => PostgresConfigDriftKind::UndeclaredFileSetting,
                default => PostgresConfigDriftKind::UnrecognisedSource,
            };
            if ($kind !== null) {
                $drift[] = new PostgresConfigDrift($kind, $setting['name'], $setting['sourcefile'] !== '' ? $setting['sourcefile'] : $setting['source']);
            }
        }

        foreach ($snapshot->catalogSettings as $setting) {
            $drift[] = new PostgresConfigDrift(PostgresConfigDriftKind::CatalogSetting, $setting['name'], $setting['scope']);
        }

        foreach ($snapshot->fileErrors as $error) {
            $drift[] = new PostgresConfigDrift(PostgresConfigDriftKind::ConfigFileError, $error['name'], $error['sourcefile']);
        }

        return PostgresConfigDriftReport::of($this->declaredConfigFile, $drift);
    }
}
