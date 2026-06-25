<?php

declare(strict_types=1);

namespace Vortos\Release\Tagging;

enum TaggingStatus: string
{
    case Planned = 'planned';
    case Partial = 'partial';
    case Complete = 'complete';
    case Undone = 'undone';
}
