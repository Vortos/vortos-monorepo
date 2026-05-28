<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

enum LifecyclePlanChange: string
{
    case None = 'none';
    case Create = 'create';
    case Update = 'update';
    case Remove = 'remove';
}
