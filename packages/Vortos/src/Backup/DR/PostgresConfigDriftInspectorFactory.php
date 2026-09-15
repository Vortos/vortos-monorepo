<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use Vortos\Backup\Config\BackupConfigLoader;

/** Builds the drift inspector from the declaration in config/backup.php, read when the service is resolved. */
final class PostgresConfigDriftInspectorFactory
{
    public function __construct(private readonly BackupConfigLoader $loader) {}

    public function create(PostgresSettingsReaderInterface $reader): PostgresConfigDriftInspector
    {
        return new PostgresConfigDriftInspector($reader, $this->loader->postgresConfigFileOrNull());
    }
}
