<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Iam;

enum IamProvider: string
{
    case Aws = 'aws';
    case Gcp = 'gcp';
}
