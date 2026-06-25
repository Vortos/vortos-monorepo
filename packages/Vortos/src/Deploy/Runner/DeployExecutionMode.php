<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runner;

enum DeployExecutionMode: string
{
    case Live = 'live';
    case DryRun = 'dry-run';
}
