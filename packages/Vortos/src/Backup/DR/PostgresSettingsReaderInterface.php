<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

/**
 * Where the running PostgreSQL cluster's configuration comes from — names and sources only, never
 * values (RC-5). A setting's value can be sensitive (a connection string in a GUC); where it was set
 * from is what drift is about.
 */
interface PostgresSettingsReaderInterface
{
    public function read(): PostgresSettingsSnapshot;
}
