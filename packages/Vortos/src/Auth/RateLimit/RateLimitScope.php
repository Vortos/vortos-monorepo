<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit;

enum RateLimitScope: string
{
    case User   = 'user';
    case Ip     = 'ip';
    case Global = 'global';
}
