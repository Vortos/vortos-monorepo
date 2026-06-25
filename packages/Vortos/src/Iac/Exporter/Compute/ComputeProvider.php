<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Compute;

enum ComputeProvider: string
{
    case Aws = 'aws';
    case Gcp = 'gcp';
    case GenericVps = 'generic-vps';
}
