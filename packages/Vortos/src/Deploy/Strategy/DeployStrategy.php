<?php

declare(strict_types=1);

namespace Vortos\Deploy\Strategy;

enum DeployStrategy: string
{
    case BlueGreen = 'blue-green';
    case Rolling = 'rolling';
    case Recreate = 'recreate';
    case Canary = 'canary';
}
