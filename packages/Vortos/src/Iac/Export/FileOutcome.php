<?php

declare(strict_types=1);

namespace Vortos\Iac\Export;

enum FileOutcome: string
{
    case Written = 'written';
    case Unchanged = 'unchanged';
    case Drift = 'drift';
    case WouldWrite = 'would-write';
}
