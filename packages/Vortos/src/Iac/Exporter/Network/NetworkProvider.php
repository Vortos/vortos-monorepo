<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Network;

enum NetworkProvider: string
{
    case Aws = 'aws';
    case Gcp = 'gcp';
}
