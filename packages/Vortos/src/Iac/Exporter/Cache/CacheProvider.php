<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Cache;

enum CacheProvider: string
{
    case AwsElasticache = 'aws-elasticache';
    case GcpMemorystore = 'gcp-memorystore';
}
