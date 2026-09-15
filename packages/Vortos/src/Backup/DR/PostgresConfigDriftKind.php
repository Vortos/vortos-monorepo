<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

enum PostgresConfigDriftKind: string
{
    /** A setting applied with ALTER SYSTEM, living only in the data volume's postgresql.auto.conf. */
    case AlterSystemSetting = 'alter_system_setting';

    /** A setting passed as a server command-line flag rather than declared in the config file. */
    case CommandLineSetting = 'command_line_setting';

    /** A setting taken from the server's environment (PG* variables) rather than the config file. */
    case EnvironmentSetting = 'environment_setting';

    /** A setting applied with ALTER DATABASE / ALTER ROLE … SET, living only in the catalog. */
    case CatalogSetting = 'catalog_setting';

    /** A setting from a configuration file other than the declared one. */
    case UndeclaredFileSetting = 'undeclared_file_setting';

    /** A source this inspector does not recognise; reported rather than assumed harmless. */
    case UnrecognisedSource = 'unrecognised_source';

    /** The server is not running from the declared configuration file at all. */
    case UndeclaredConfigFile = 'undeclared_config_file';

    /** ALTER SYSTEM is still permitted, so the next hand-applied change is one statement away. */
    case AlterSystemAllowed = 'alter_system_allowed';

    /** A line in a configuration file the server could not apply. */
    case ConfigFileError = 'config_file_error';
}
