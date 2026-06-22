<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest;

enum ChangeType: string
{
    case Enable         = 'enable';
    case Disable        = 'disable';
    case UpdateRules    = 'update_rules';
    case UpdateVariants = 'update_variants';
    case UpdateSchedule = 'update_schedule';
    case Promote        = 'promote';
    case Create         = 'create';
    case Archive        = 'archive';
    case UpdateMetadata = 'update_metadata';
}
