<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\ComputeService;

enum ComputeServiceProvider: string
{
    case AwsEcs = 'aws-ecs';
    case GcpCloudRun = 'gcp-cloud-run';
}
