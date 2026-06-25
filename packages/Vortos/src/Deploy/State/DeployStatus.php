<?php

declare(strict_types=1);

namespace Vortos\Deploy\State;

enum DeployStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
