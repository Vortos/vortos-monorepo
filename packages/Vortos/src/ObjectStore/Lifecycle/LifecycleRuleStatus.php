<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

enum LifecycleRuleStatus: string
{
    case Enabled = 'Enabled';
    case Disabled = 'Disabled';
}
