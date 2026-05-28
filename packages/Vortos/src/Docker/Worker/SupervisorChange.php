<?php

declare(strict_types=1);

namespace Vortos\Docker\Worker;

enum SupervisorChange: string
{
    case Create = 'create';
    case Update = 'update';
    case Remove = 'remove';
    case None = 'none';
}
