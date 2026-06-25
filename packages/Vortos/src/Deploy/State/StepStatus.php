<?php

declare(strict_types=1);

namespace Vortos\Deploy\State;

enum StepStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
