<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

enum LifecyclePhase: string
{
    case Init = 'init';
    case Plan = 'plan';
    case Apply = 'apply';
    case Destroy = 'destroy';
    case Show = 'show';
}
