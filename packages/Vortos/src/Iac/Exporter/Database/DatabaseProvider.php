<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Database;

enum DatabaseProvider: string
{
    case AwsRds = 'aws-rds';
    case GcpCloudSql = 'gcp-cloudsql';
}
